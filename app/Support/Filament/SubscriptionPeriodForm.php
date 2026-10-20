<?php

namespace App\Support\Filament;

use App\Enums\BillingCycle;
use App\Exceptions\Subscription\SubscriptionException;
use App\Models\Plan;
use App\Models\SubscriptionPeriod;
use App\Models\SubscriptionRequest;
use App\Models\User;
use App\Services\Subscription\RecordedPayment;
use App\Services\Subscription\SubscriptionService;
use Carbon\Carbon;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The "record a payment" form, shared by every place the admin turns a WhatsApp
 * payment into a subscription period: approving a request, adding a renewal to a
 * subscription, and creating a subscription for a student who never asked in the
 * app. One definition, so the three cannot drift apart.
 */
final class SubscriptionPeriodForm
{
    /**
     * @return array<int, Component>
     */
    public static function schema(): array
    {
        return [
            Select::make('plan_id')
                ->label(__('admin.subscriptions.field_plan'))
                ->options(fn (): array => Plan::query()
                    ->ordered()
                    ->get()
                    ->mapWithKeys(fn (Plan $plan): array => [$plan->getKey() => $plan->name])
                    ->all())
                ->required()
                ->searchable()
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set): void {
                    // A cycle the new plan does not sell cannot stay selected.
                    $plan = self::plan($get('plan_id'));
                    $cycle = BillingCycle::tryFrom((string) $get('billing_cycle'));

                    if ($plan === null || ($cycle !== null && ! $plan->offers($cycle))) {
                        $set('billing_cycle', null);
                    }

                    self::fillPrice($get, $set);
                }),

            Select::make('billing_cycle')
                ->label(__('admin.subscriptions.field_billing_cycle'))
                ->options(function (Get $get): array {
                    $plan = self::plan($get('plan_id'));

                    return collect(BillingCycle::cases())
                        ->filter(fn (BillingCycle $cycle): bool => $plan === null || $plan->offers($cycle))
                        ->mapWithKeys(fn (BillingCycle $cycle): array => [$cycle->value => $cycle->label()])
                        ->all();
                })
                ->required()
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::fillPrice($get, $set)),

            TextInput::make('amount')
                ->label(__('admin.subscriptions.field_amount'))
                ->helperText(__('admin.subscriptions.amount_hint'))
                ->numeric()
                ->minValue(0)
                ->required(),

            TextInput::make('currency')
                ->label(__('admin.subscriptions.field_currency'))
                ->length(3)
                ->required()
                ->default('USD'),

            DateTimePicker::make('paid_at')
                ->label(__('admin.subscriptions.field_paid_at'))
                ->seconds(false)
                ->default(now())
                ->required(),

            DateTimePicker::make('starts_at')
                ->label(__('admin.subscriptions.field_starts_at'))
                ->helperText(__('admin.subscriptions.starts_at_hint'))
                ->seconds(false),

            Textarea::make('notes')
                ->label(__('admin.subscriptions.field_notes'))
                ->rows(3)
                ->maxLength(1000)
                ->columnSpanFull(),

            // The screenshot is handed to the period's payment_proof collection
            // (private disk), so the form never stores it anywhere itself.
            FileUpload::make('proof')
                ->label(__('admin.subscriptions.field_proof'))
                ->helperText(__('admin.subscriptions.proof_hint', [
                    'size' => (int) round(self::proofMaxSizeKb() / 1024),
                ]))
                ->image()
                ->storeFiles(false)
                ->acceptedFileTypes(config('media.payment_proof_mime_types', []))
                ->maxSize(self::proofMaxSizeKb())
                ->required()
                ->columnSpanFull(),
        ];
    }

    /**
     * What the form starts with for a plan and cycle (a request's, usually).
     *
     * @return array<string, mixed>
     */
    public static function defaults(?Plan $plan = null, ?BillingCycle $cycle = null): array
    {
        $price = $plan !== null && $cycle !== null ? $plan->priceFor($cycle) : null;

        return [
            'plan_id' => $plan?->getKey(),
            'billing_cycle' => $cycle?->value,
            'amount' => $price,
            'currency' => $plan?->currency ?? 'USD',
            'paid_at' => now(),
        ];
    }

    /**
     * Records the form's payment against the student and returns the period.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException when the plan does not sell the chosen cycle
     * @throws SubscriptionException when the request was handled in the meantime
     */
    public static function record(User $student, array $data, ?SubscriptionRequest $request = null): SubscriptionPeriod
    {
        $plan = Plan::withTrashed()->findOrFail($data['plan_id']);
        $cycle = BillingCycle::from($data['billing_cycle']);

        if (! $plan->offers($cycle)) {
            throw ValidationException::withMessages([
                'billing_cycle' => __('admin.subscriptions.cycle_not_offered'),
            ]);
        }

        $proof = $data['proof'] ?? null;
        $proof = is_array($proof) ? reset($proof) : $proof;

        if (! $proof instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'proof' => __('admin.subscriptions.proof_required'),
            ]);
        }

        return app(SubscriptionService::class)->recordPeriod(new RecordedPayment(
            student: $student,
            plan: $plan,
            cycle: $cycle,
            amount: $data['amount'],
            proof: $proof,
            paidAt: filled($data['paid_at'] ?? null) ? Carbon::parse($data['paid_at']) : null,
            startsAt: filled($data['starts_at'] ?? null) ? Carbon::parse($data['starts_at']) : null,
            currency: filled($data['currency'] ?? null) ? strtoupper($data['currency']) : null,
            notes: $data['notes'] ?? null,
            recordedBy: auth('admin')->user(),
            request: $request,
        ));
    }

    public static function proofMaxSizeKb(): int
    {
        return (int) config('media.max_image_size_kb', 5 * 1024);
    }

    private static function plan(mixed $id): ?Plan
    {
        return filled($id) ? Plan::withTrashed()->find($id) : null;
    }

    /**
     * Suggests the plan's list price for the chosen cycle; the admin can still
     * change it to whatever was actually agreed on WhatsApp.
     */
    private static function fillPrice(Get $get, Set $set): void
    {
        $plan = self::plan($get('plan_id'));
        $cycle = BillingCycle::tryFrom((string) $get('billing_cycle'));

        if ($plan === null || $cycle === null || ! $plan->offers($cycle)) {
            return;
        }

        $set('amount', $plan->priceFor($cycle));
        $set('currency', $plan->currency);
    }
}
