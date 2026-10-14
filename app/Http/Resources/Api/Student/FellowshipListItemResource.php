<?php

namespace App\Http\Resources\Api\Student;

use App\Models\Fellowship;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Fellowship card on the Question Bank screen.
 *
 * The screen lists Fellowships, not questions: a card carries the Fellowship's
 * identity, the Performance Topics it classifies its questions by, how many
 * questions it holds, and this student's `average_score`.
 *
 * `average_score` is the module's single accuracy figure — correct confirmed
 * answers over all confirmed answers. The frontend labels it "Completion Rate"
 * here and "Average Score" inside a Fellowship; the name differs, the number
 * does not. It is null until the student has confirmed an answer, so a new
 * student is shown no score rather than a zero.
 *
 * @mixin Fellowship
 */
class FellowshipListItemResource extends JsonResource
{
    /**
     * @param  array{average_score: float|null, total_questions: int, performance_topics: list<array{id: int, value: string}>}  $stats
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
            'performance_topics' => $this->stats['performance_topics'],
            'total_questions' => $this->stats['total_questions'],
            'average_score' => $this->stats['average_score'],
        ];
    }
}
