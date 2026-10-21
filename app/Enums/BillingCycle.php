<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /**
     * When a period starting at `$start` ends. Months are added without
     * overflow, so a period starting on Jan 31 ends on Feb 28, not Mar 3.
     */
    public function endsAt(CarbonInterface $start): CarbonImmutable
    {
        $start = CarbonImmutable::instance($start);

        return match ($this) {
            self::Monthly => $start->addMonthNoOverflow(),
            self::Yearly => $start->addYearNoOverflow(),
        };
    }

    public function label(): string
    {
        return __('subscriptions.billing_cycles.'.$this->value);
    }

    /**
     * @return array<string, string> value => label, for select inputs.
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $cycle) {
            $options[$cycle->value] = $cycle->label();
        }

        return $options;
    }
}
