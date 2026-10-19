<?php

namespace App\Http\Resources\Api\Student;

use App\Models\Chapter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Continue Learning card: something the student has started and can pick
 * back up, which is either a study subject or a Fellowship session.
 *
 * `type` says which, and decides which of the two optional blocks is filled —
 * a subject carries `current_chapter` and the study resume state, a Fellowship
 * carries its open `session`. The other is null rather than absent, so the card
 * has one shape whichever kind it is.
 *
 * `resume` is what the frontend continues through the existing endpoints: for a
 * subject it is {@see StudyResumeResource}, the very payload
 * `study/subjects/{subject}/resume` returns; for a Fellowship it is the session
 * id and the question it stopped on, which `fellowship-sessions/{session}`
 * reopens.
 */
class DashboardContinueLearningResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $chapter = $this['current_chapter'];
        $session = $this['session'];

        return [
            'type' => $this['type'],
            'id' => $this['id'],
            'title' => $this['title'],
            'image' => $this['image'],
            'progress' => $this['progress'],
            'current_chapter' => $chapter instanceof Chapter ? [
                'id' => $chapter->getKey(),
                'title' => $chapter->title,
                'order' => $chapter->order,
            ] : null,
            'resume' => $this['type'] === 'subject'
                ? new StudyResumeResource($this['resume'])
                : $this['resume'],
            'session' => $session === null ? null : new FellowshipSessionSummaryResource(
                $session['model'],
                $session['tally'],
                $session['meta'],
            ),
            'last_active_at' => $this['last_active_at']?->toIso8601String(),
        ];
    }
}
