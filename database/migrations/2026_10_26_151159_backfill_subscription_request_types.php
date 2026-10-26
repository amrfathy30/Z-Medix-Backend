<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Handled requests require payments recorded AND paid before submission,
     * excluding their own payment. Equal/missing timestamps cannot prove prior
     * history and safely retain "new". Pending requests reflect all paid
     * history, like resubmission. Current subscription status is irrelevant.
     */
    public function up(): void
    {
        DB::table('subscription_requests')->orderBy('id')->chunkById(500, function (Collection $requests): void {
            foreach ($requests as $request) {
                $periods = DB::table('subscription_periods as periods')
                    ->join('student_subscriptions as subscriptions', 'subscriptions.id', '=', 'periods.student_subscription_id')
                    ->where('subscriptions.user_id', $request->user_id)
                    ->where(function ($query) use ($request): void {
                        $query->whereNull('periods.subscription_request_id')
                            ->orWhere('periods.subscription_request_id', '!=', $request->id);
                    });

                if ($request->status !== 'pending') {
                    if ($request->created_at === null) {
                        continue;
                    }
                    $periods->where('periods.created_at', '<', $request->created_at)
                        ->where('periods.paid_at', '<', $request->created_at);
                }

                DB::table('subscription_requests')->where('id', $request->id)->update([
                    'request_type' => $periods->exists() ? 'renewal' : 'new',
                ]);
            }
        });
    }

    public function down(): void
    {
        // Preserve classifications until the column itself is rolled back.
    }
};
