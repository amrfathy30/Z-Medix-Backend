<?php

namespace App\Http\Resources\Api\Student;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Recent Activity entry: something the student actually did, read from the
 * row their own action wrote.
 *
 * `related_type` and `related_id` are what the frontend navigates back with —
 * the entity the entry is about, not the row the activity was derived from, so
 * a finished chapter points at the chapter and a closed study session at its
 * subject.
 */
class DashboardActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this['type'],
            'title' => $this['title'],
            'description' => $this['description'],
            'occurred_at' => $this['occurred_at']?->toIso8601String(),
            'related_type' => $this['related_type'],
            'related_id' => $this['related_id'],
        ];
    }
}
