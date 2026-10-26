<?php

namespace App\Services\AiChat;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\StudentHighlight;
use App\Models\StudentNote;
use App\Models\User;
use App\Services\Learning\StudyAccessService;
use Illuminate\Validation\ValidationException;

class AttachmentResolver
{
    public function __construct(private StudyAccessService $access) {}

    /**
     * @param  array<int, array{type: string, id: int}>  $references
     * @return array<int, array{reference_type: string, reference_id: int, snapshot: array<string, mixed>}>
     */
    public function resolve(User $student, array $references): array
    {
        $groups = collect($references)->groupBy('type');
        $records = [];
        foreach ($groups as $type => $items) {
            $query = match ($type) {
                'book' => Book::query()->with(['subject', 'media']),
                'chapter' => Chapter::query(),
                'highlight' => StudentHighlight::query()->forStudent($student)->with('chapterPage.chapter'),
                'note' => StudentNote::query()->forStudent($student)->with('chapterPage.chapter'),
            };
            $records[$type] = $query->whereKey($items->pluck('id'))->get()->keyBy('id');
        }
        $accessibleChapters = $groups->keys()->diff(['book'])->isNotEmpty()
            ? array_flip($this->access->accessibleChapterIds($student)) : [];
        $attachments = [];
        foreach ($references as $index => $reference) {
            $record = $records[$reference['type']]->get($reference['id']);
            $chapter = $record instanceof Chapter ? $record : (($record instanceof StudentHighlight || $record instanceof StudentNote) ? $record->chapterPage?->chapter : null);
            $allowed = $record instanceof Book
                ? $this->access->canAccessBook($student, $record)
                : $chapter !== null && isset($accessibleChapters[$chapter->id]);
            if ($record === null || ! $allowed) {
                throw ValidationException::withMessages(["attachments.{$index}.id" => 'This reference is unavailable or inaccessible.']);
            }
            if ($record instanceof Book) {
                $snapshot = ['title' => $record->title, 'book_type' => $record->type->value, 'subject_id' => $record->subject_id, 'source_updated_at' => $record->updated_at?->toIso8601String()];
                if ($record->isPdf()) {
                    $media = $record->getFirstMedia(Book::PDF_COLLECTION);
                    $snapshot['media_id'] = $media?->id;
                    $snapshot['media_uuid'] = $media?->uuid;
                    $snapshot['media_updated_at'] = $media?->updated_at?->toIso8601String();
                }
            } elseif ($record instanceof Chapter) {
                $snapshot = ['title' => $record->title, 'subject_id' => $record->subject_id, 'source_updated_at' => $record->updated_at?->toIso8601String()];
            } else {
                $snapshot = [
                    'selected_text' => $record->selected_text,
                    'chapter_page_id' => $record->chapter_page_id,
                    'chapter_id' => $chapter->id,
                    'subject_id' => $chapter->subject_id,
                ];
                if ($record instanceof StudentNote) {
                    $snapshot['content'] = $record->content;
                }
            }
            $attachments[] = ['reference_type' => $reference['type'], 'reference_id' => (int) $reference['id'], 'snapshot' => $snapshot];
        }
        if (strlen(json_encode($attachments, JSON_THROW_ON_ERROR)) > config('ai_chat.max_attachment_snapshot_bytes')) {
            throw ValidationException::withMessages(['attachments' => 'The combined attachment snapshots are too large.']);
        }

        return $attachments;
    }
}
