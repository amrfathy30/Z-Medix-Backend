<?php

namespace Tests\Feature\AiChat;

use App\Contracts\AiChatAgentInterface;
use App\Data\AiChat\AgentChatRequest;
use App\Data\AiChat\AgentChatResponse;
use App\Events\AiChatMessageChanged;
use App\Jobs\ProcessAiChatResponse;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAttachment;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\StudentHighlight;
use App\Models\StudentNote;
use App\Models\Subject;
use App\Models\User;
use App\Services\AiChat\AiChatService;
use App\Services\AiChat\ChatBroadcaster;
use Database\Factories\BookFactory;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\SubscribesStudents;
use Tests\TestCase;

class AiChatAttachmentTest extends TestCase
{
    use DatabaseMigrations, SubscribesStudents;

    private User $student;

    private AiConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_chat.queue_connection' => 'database', 'ai_chat.adapter' => 'mock']);
        $this->student = $this->subscribedStudent();
        $this->conversation = AiConversation::factory()->for($this->student)->create();
        Sanctum::actingAs($this->student);
        Queue::fake();
        Event::fake([AiChatMessageChanged::class]);
        Storage::fake('filament_public');
    }

    private function path(): string
    {
        return '/api/student/ai/conversations/'.$this->conversation->id.'/messages';
    }

    /** @param array<int, array<string, mixed>> $attachments */
    private function send(array $attachments, string $key = 'attachment-1'): TestResponse
    {
        return $this->postJson($this->path(), ['content' => 'Explain these topics.', 'client_message_id' => $key, 'attachments' => $attachments]);
    }

    public function test_mixed_references_and_pdf_file_identity_survive_replacement(): void
    {
        $pdf = Book::factory()->withPdfFile()->create();
        $media = $pdf->getFirstMedia(Book::PDF_COLLECTION);
        $book = Book::factory()->content()->create();
        $chapter = Chapter::factory()->create();
        $page = ChapterPage::factory()->for($chapter)->create();
        $highlight = StudentHighlight::factory()->for($this->student)->for($page)->selectedText("Exact\n highlighted text")->create();
        $note = StudentNote::factory()->for($this->student)->for($page)->content("Exact\n note text")->create();
        $references = [
            ['type' => 'book', 'id' => $pdf->id], ['type' => 'book', 'id' => $book->id],
            ['type' => 'chapter', 'id' => $chapter->id], ['type' => 'highlight', 'id' => $highlight->id], ['type' => 'note', 'id' => $note->id],
        ];
        $data = $this->send($references)->assertAccepted()->assertJsonCount(5, 'data.user_message.attachments')
            ->assertJsonPath('data.user_message.attachments.0.snapshot.media_uuid', $media->uuid)
            ->assertJsonPath('data.user_message.attachments.1.snapshot.title', $book->title)
            ->assertJsonPath('data.user_message.attachments.2.snapshot.title', $chapter->title)
            ->assertJsonPath('data.user_message.attachments.3.snapshot.selected_text', $highlight->selected_text)
            ->assertJsonPath('data.user_message.attachments.4.snapshot.content', $note->content)
            ->assertJsonPath('data.assistant_message.attachments', [])->json('data');
        $snapshots = $data['user_message']['attachments'];
        $pdf->addMedia(BookFactory::fakePdf('replacement.pdf'))->toMediaCollection(Book::PDF_COLLECTION);
        $book->update(['title' => 'Changed book']);
        $chapter->update(['title' => 'Changed chapter']);
        $highlight->update(['selected_text' => 'Changed highlight']);
        $note->update(['content' => 'Changed note']);
        $this->getJson('/api/student/ai/messages/'.$data['user_message']['id'])->assertOk()->assertJsonPath('data.attachments', $snapshots);
        $highlight->delete();
        $note->delete();
        $this->getJson('/api/student/ai/messages/'.$data['user_message']['id'])->assertOk()->assertJsonPath('data.attachments', $snapshots);
        $this->getJson($this->path().'?per_page=1&page=2')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.attachments', $snapshots);
        $this->send(array_reverse($references))->assertOk()->assertJsonPath('data.user_message.attachments', $snapshots);
        Queue::assertPushed(ProcessAiChatResponse::class, 1);
        Event::assertDispatched(AiChatMessageChanged::class, fn ($event) => $event->eventName === 'chat.message.created' && $event->payload['id'] === $data['user_message']['id'] && $event->payload['attachments'] === $snapshots);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        $this->assertSame($media->id, $snapshots[0]['snapshot']['media_id']);
        $this->assertArrayNotHasKey('version_id', $snapshots[1]['snapshot']);
    }

    public function test_inaccessible_or_invalid_references_are_rejected_before_writes(): void
    {
        $draft = Book::factory()->draft()->create();
        $hiddenSubject = Subject::factory()->draft()->create();
        $hiddenBook = Book::factory()->for($hiddenSubject)->create();
        $first = Chapter::factory()->create();
        $locked = Chapter::factory()->for($first->subject)->create();
        $draftChapter = Chapter::factory()->draft()->create();
        $foreignNote = StudentNote::factory()->create();
        $foreignHighlight = StudentHighlight::factory()->create();
        $deleted = Book::factory()->create();
        $deleted->delete();
        $ownLockedNote = StudentNote::factory()->for($this->student)->for(ChapterPage::factory()->for($locked), 'chapterPage')->create();
        foreach ([['book', $draft->id], ['book', $hiddenBook->id], ['book', $deleted->id], ['chapter', $locked->id], ['chapter', $draftChapter->id], ['note', $foreignNote->id], ['highlight', $foreignHighlight->id], ['note', $ownLockedNote->id], ['book', 999999], ['unsupported', 1], ['book', -1]] as [$type, $id]) {
            $this->send([['type' => $type, 'id' => $id]])->assertUnprocessable();
        }
        $this->assertDatabaseCount('ai_messages', 0);
        $this->assertDatabaseCount('ai_message_attachments', 0);
        Queue::assertNothingPushed();
    }

    public function test_duplicates_limits_and_client_snapshots_are_rejected(): void
    {
        $book = Book::factory()->create();
        $this->send([['type' => 'book', 'id' => $book->id], ['type' => 'book', 'id' => (string) $book->id]])->assertUnprocessable();
        $this->send(array_fill(0, 11, ['type' => 'book', 'id' => $book->id]))->assertUnprocessable();
        $this->send([['type' => 'book', 'id' => $book->id, 'snapshot' => ['title' => 'Forged']]])->assertUnprocessable();
        $this->postJson($this->path(), ['content' => 'Question', 'client_message_id' => 'key', 'attachments' => null])->assertUnprocessable();
        config(['ai_chat.max_attachment_snapshot_bytes' => 10]);
        $this->send([['type' => 'book', 'id' => $book->id]])->assertUnprocessable();
        $this->assertDatabaseCount('ai_messages', 0);
    }

    public function test_idempotency_compares_content_and_reference_sets(): void
    {
        $book = Book::factory()->create();
        $other = Book::factory()->create();
        $references = [['type' => 'book', 'id' => $book->id]];
        $first = $this->send($references)->assertAccepted()->json('data.user_message.id');
        $this->send($references)->assertOk()->assertJsonPath('data.user_message.id', $first)->assertJsonPath('data.assistant_message.attachments', []);
        foreach ([[], [['type' => 'book', 'id' => $other->id]], [['type' => 'chapter', 'id' => $book->id]]] as $changed) {
            $this->send($changed)->assertUnprocessable()->assertJsonValidationErrors('client_message_id');
        }
        $this->postJson($this->path(), ['content' => 'Different', 'client_message_id' => 'attachment-1', 'attachments' => $references])->assertUnprocessable();
        $this->assertDatabaseCount('ai_messages', 2);
        $this->assertDatabaseCount('ai_message_attachments', 1);
    }

    public function test_agent_and_retries_receive_persisted_references_after_source_deletion(): void
    {
        $note = StudentNote::factory()->for($this->student)->create(['content' => 'Original note']);
        $data = $this->send([['type' => 'note', 'id' => $note->id]])->assertAccepted()->json('data');
        $saved = AiMessageAttachment::query()->firstOrFail();
        $note->delete();
        $agent = new class implements AiChatAgentInterface
        {
            public array $requests = [];

            public function generateResponse(AgentChatRequest $request): AgentChatResponse
            {
                $this->requests[] = $request;
                if (count($this->requests) === 1) {
                    throw new RuntimeException('Retry');
                }

                return new AgentChatResponse('Complete mock response', $request->correlationId);
            }
        };
        $job = new ProcessAiChatResponse($data['assistant_message']['id']);
        try {
            $job->handle($agent, app(AiChatService::class), app(ChatBroadcaster::class));
            $this->fail('First attempt should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('AI chat processing failed.', $exception->getMessage());
        }
        $job->handle($agent, app(AiChatService::class), app(ChatBroadcaster::class));
        $expected = [['reference_type' => 'note', 'reference_id' => $note->id, 'snapshot' => $saved->snapshot]];
        $this->assertSame($expected, $agent->requests[0]->attachments);
        $this->assertSame($expected, $agent->requests[1]->attachments);
        $this->assertSame('completed', AiMessage::findOrFail($data['assistant_message']['id'])->status);
        Event::assertDispatched(AiChatMessageChanged::class, fn ($event) => $event->eventName === 'chat.response.completed' && $event->payload['content'] === 'Complete mock response');
    }

    public function test_attachment_write_failure_rolls_back_messages_and_events(): void
    {
        $book = Book::factory()->create();
        AiMessageAttachment::creating(function (): void {
            throw new RuntimeException('Storage failure');
        });
        try {
            app(AiChatService::class)->submit($this->student, (string) $this->conversation->id, 'Question', 'atomic', [['type' => 'book', 'id' => $book->id]]);
            $this->fail('Expected storage failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Storage failure', $exception->getMessage());
        } finally {
            AiMessageAttachment::flushEventListeners();
        }
        $this->assertDatabaseCount('ai_messages', 0);
        $this->assertDatabaseCount('ai_message_attachments', 0);
        Event::assertNotDispatched(AiChatMessageChanged::class);
        Queue::assertNothingPushed();
    }

    public function test_deletion_cascades_only_chat_records_and_history_queries_are_batched(): void
    {
        $book = Book::factory()->create();
        $note = StudentNote::factory()->for($this->student)->create();
        $chapter = Chapter::factory()->create();
        $highlight = StudentHighlight::factory()->for($this->student)->create();
        $references = [['type' => 'book', 'id' => $book->id], ['type' => 'note', 'id' => $note->id], ['type' => 'chapter', 'id' => $chapter->id], ['type' => 'highlight', 'id' => $highlight->id]];
        $this->send($references)->assertAccepted();
        $this->send($references, 'second')->assertAccepted();
        DB::enableQueryLog();
        $this->getJson($this->path())->assertOk()->assertJsonCount(4, 'data');
        $attachmentQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from "ai_message_attachments"'));
        DB::disableQueryLog();
        $this->assertCount(1, $attachmentQueries);
        $this->deleteJson('/api/student/ai/conversations/'.$this->conversation->id)->assertOk();
        $this->assertDatabaseCount('ai_message_attachments', 0);
        foreach ([$book, $note, $chapter, $highlight] as $source) {
            $this->assertModelExists($source);
        }
    }
}
