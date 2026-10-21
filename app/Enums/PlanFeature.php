<?php

namespace App\Enums;

/**
 * What a plan can unlock. The set is fixed because each case guards a specific
 * part of the student API (see the `feature` route middleware), so adding a
 * case means adding the gate that enforces it.
 */
enum PlanFeature: string
{
    case Library = 'library';
    case QuestionBank = 'question_bank';
    case CaseReport = 'case_report';
    case Ai = 'ai';

    public function label(): string
    {
        return __('subscriptions.features.'.$this->value);
    }

    /**
     * Only some features carry a limit. Today that is the AI credits; the rest
     * are plain on/off.
     */
    public function isLimitable(): bool
    {
        return $this === self::Ai;
    }

    /**
     * @return array<string, string> value => label, for select inputs.
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $feature) {
            $options[$feature->value] = $feature->label();
        }

        return $options;
    }
}
