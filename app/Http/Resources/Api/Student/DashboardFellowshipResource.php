<?php

namespace App\Http\Resources\Api\Student;

use App\Models\Fellowship;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Question Bank card on the home screen.
 *
 * Deliberately lighter than {@see FellowshipListItemResource}: the home screen
 * advertises what a bank holds rather than how the student is doing in it, so
 * it carries the bank's totals and none of the student's figures.
 *
 * `total_difficulty_levels` counts the difficulties present in this bank's own
 * questions, not the platform's full set of levels.
 *
 * @mixin Fellowship
 */
class DashboardFellowshipResource extends JsonResource
{
    /**
     * @param  array{total_questions: int, total_topics: int, total_difficulty_levels: int}  $stats
     */
    public function __construct($resource, private array $stats)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'image' => $this->imageUrl(),
            'total_questions' => $this->stats['total_questions'],
            'total_topics' => $this->stats['total_topics'],
            'total_difficulty_levels' => $this->stats['total_difficulty_levels'],
        ];
    }
}
