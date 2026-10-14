<?php

namespace Tests\Feature\Study;

use App\Models\StudentPrivateBook;
use App\Models\Subject;
use Database\Factories\BookFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * The student's own library of uploaded PDFs.
 *
 * Two things are load-bearing here and are checked from several angles. The
 * file is private: it is written to a disk outside the public storage link, and
 * the only way to read it is the authenticated download route. And ownership is
 * absolute: every action on someone else's book is refused as not found, so a
 * student cannot even learn that another student's upload exists.
 *
 * Private books are not platform books. They share no record, no disk and no
 * access rule, and nothing here changes how a platform book is stored or
 * served.
 */
class StudentPrivateBookTest extends StudyTestCase
{
    private string $url = '/api/student/private-books';

    protected function setUp(): void
    {
        parent::setUp();

        // The private disk the uploads land on, and the public ones they must
        // never reach.
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('filament_public');
    }

    private function upload(Subject $subject, array $overrides = []): TestResponse
    {
        return $this->postJson($this->url, [
            'subject_id' => $subject->id,
            'title' => 'My Anatomy Notes',
            'author' => 'R. Snell',
            'pdf' => BookFactory::fakePdf('notes.pdf'),
            ...$overrides,
        ]);
    }

    // ─── Uploading ──────────────────────────────────────────────────────────

    public function test_a_student_uploads_a_private_pdf_against_a_subject(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);

        Sanctum::actingAs($student = $this->student());

        $this->upload($subject)
            ->assertCreated()
            ->assertJsonPath('data.title', 'My Anatomy Notes')
            ->assertJsonPath('data.author', 'R. Snell')
            ->assertJsonPath('data.subject_id', $subject->id)
            ->assertJsonPath('data.subject.name', $subject->name)
            ->assertJsonPath('data.has_pdf', true)
            ->assertJsonPath('data.pdf_file_name', 'notes.pdf')
            ->assertJsonPath('data.pdf_mime_type', 'application/pdf');

        $this->assertDatabaseHas('student_private_books', [
            'user_id' => $student->id,
            'subject_id' => $subject->id,
            'title' => 'My Anatomy Notes',
            'author' => 'R. Snell',
        ]);
    }

    /**
     * The file goes to the private disk, and nowhere a URL could reach it.
     */
    public function test_the_uploaded_file_is_stored_privately(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);

        Sanctum::actingAs($this->student());

        $this->upload($subject)->assertCreated();

        $media = StudentPrivateBook::query()->sole()->pdf();

        $this->assertSame(StudentPrivateBook::PDF_DISK, $media->disk);
        $this->assertTrue(Storage::disk('local')->exists($media->getPathRelativeToRoot()));

        // Not on either publicly served disk.
        $this->assertEmpty(Storage::disk('public')->allFiles());
        $this->assertEmpty(Storage::disk('filament_public')->allFiles());
    }

    /**
     * The payload points at the API route rather than a storage URL: a public
     * link to a student's own upload is exactly what must not exist.
     */
    public function test_the_payload_exposes_only_the_authenticated_download_route(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);

        Sanctum::actingAs($this->student());

        $book = StudentPrivateBook::query()->find(
            $this->upload($subject)->assertCreated()->json('data.id'),
        );

        $url = $this->getJson($this->url."/{$book->id}")->assertOk()->json('data.pdf_url');

        $this->assertStringEndsWith("/api/student/private-books/{$book->id}/pdf", $url);
        $this->assertStringNotContainsString('/storage/', $url);
    }

    public function test_the_author_is_optional(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);

        Sanctum::actingAs($this->student());

        $this->upload($subject, ['author' => null])
            ->assertCreated()
            ->assertJsonPath('data.author', null);
    }

    public function test_a_title_subject_and_file_are_required(): void
    {
        Sanctum::actingAs($this->student());

        $this->postJson($this->url, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_id', 'title', 'pdf']);

        $this->assertDatabaseCount('student_private_books', 0);
    }

    public function test_only_pdf_files_are_accepted(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);

        Sanctum::actingAs($this->student());

        $this->upload($subject, [
            'pdf' => UploadedFile::fake()->createWithContent('notes.txt', 'Plain text, not a PDF.'),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pdf');

        $this->assertDatabaseCount('student_private_books', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_an_oversized_file_is_refused(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);
        $limitKb = (int) config('media.max_document_size_kb');

        Sanctum::actingAs($this->student());

        $this->upload($subject, [
            'pdf' => BookFactory::fakePdf('huge.pdf', reportedKilobytes: $limitKb + 1),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pdf');

        $this->assertDatabaseCount('student_private_books', 0);
    }

    public function test_a_book_cannot_be_filed_under_an_unpublished_subject(): void
    {
        $subject = Subject::factory()->draft()->create();

        Sanctum::actingAs($this->student());

        $this->upload($subject)
            ->assertNotFound()
            ->assertJsonPath('code', 'SUBJECT_UNAVAILABLE');

        $this->assertDatabaseCount('student_private_books', 0);
    }

    public function test_a_book_cannot_be_filed_under_a_subject_that_does_not_exist(): void
    {
        Sanctum::actingAs($this->student());

        $this->postJson($this->url, [
            'subject_id' => 99999,
            'title' => 'Orphan',
            'pdf' => BookFactory::fakePdf(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subject_id');
    }

    // ─── Listing and reading ────────────────────────────────────────────────

    public function test_a_student_lists_only_their_own_books(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);
        $other = $this->student();

        StudentPrivateBook::factory()->for($other)->create([
            'subject_id' => $subject->id,
            'title' => 'Someone else\'s book',
        ]);

        Sanctum::actingAs($student = $this->student());

        StudentPrivateBook::factory()->for($student)->create([
            'subject_id' => $subject->id,
            'title' => 'Mine',
        ]);

        $this->getJson($this->url)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Mine');
    }

    public function test_the_library_can_be_filtered_by_subject_and_is_paginated(): void
    {
        $first = $this->subjectWithChapters(chapters: 1);
        $second = $this->subjectWithChapters(chapters: 1);

        Sanctum::actingAs($student = $this->student());

        StudentPrivateBook::factory()->for($student)->count(3)->create(['subject_id' => $first->id]);
        StudentPrivateBook::factory()->for($student)->create(['subject_id' => $second->id]);

        $this->getJson($this->url.'?subject_id='.$first->id)
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3);

        $this->getJson($this->url.'?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_a_student_reads_their_own_book(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);

        Sanctum::actingAs($student = $this->student());

        $book = StudentPrivateBook::factory()->for($student)->withPdfFile()->create([
            'subject_id' => $subject->id,
        ]);

        $this->getJson($this->url."/{$book->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $book->id)
            ->assertJsonPath('data.has_pdf', true);
    }

    public function test_another_students_book_is_not_found(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);
        $other = $this->student();
        $book = StudentPrivateBook::factory()->for($other)->withPdfFile()->create([
            'subject_id' => $subject->id,
        ]);

        Sanctum::actingAs($this->student());

        $this->getJson($this->url."/{$book->id}")->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->patchJson($this->url."/{$book->id}", ['title' => 'Hijacked'])->assertNotFound();
        $this->deleteJson($this->url."/{$book->id}")->assertNotFound();
        $this->get($this->url."/{$book->id}/pdf")->assertNotFound();

        $this->assertDatabaseHas('student_private_books', [
            'id' => $book->id,
            'title' => $book->title,
        ]);
    }

    // ─── Downloading ────────────────────────────────────────────────────────

    public function test_a_student_downloads_their_own_pdf(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);

        Sanctum::actingAs($student = $this->student());

        $book = StudentPrivateBook::factory()->for($student)->withPdfFile('mine.pdf')->create([
            'subject_id' => $subject->id,
        ]);

        $response = $this->get($this->url."/{$book->id}/pdf")->assertOk();

        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('mine.pdf', $response->headers->get('content-disposition'));
    }

    public function test_the_pdf_can_be_streamed_inline_for_a_reader(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);

        Sanctum::actingAs($student = $this->student());

        $book = StudentPrivateBook::factory()->for($student)->withPdfFile()->create([
            'subject_id' => $subject->id,
        ]);

        $response = $this->get($this->url."/{$book->id}/pdf?disposition=inline")->assertOk();

        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
    }

    public function test_a_book_without_a_file_has_nothing_to_download(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);

        Sanctum::actingAs($student = $this->student());

        $book = StudentPrivateBook::factory()->for($student)->create(['subject_id' => $subject->id]);

        $this->getJson($this->url."/{$book->id}/pdf")
            ->assertNotFound()
            ->assertJsonPath('code', 'BOOK_CONTENT_UNAVAILABLE');
    }

    // ─── Editing and deleting ───────────────────────────────────────────────

    public function test_a_student_updates_their_books_metadata(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);
        $otherSubject = $this->subjectWithChapters(chapters: 1);

        Sanctum::actingAs($student = $this->student());

        $book = StudentPrivateBook::factory()->for($student)->create([
            'subject_id' => $subject->id,
            'title' => 'Draft title',
            'author' => 'Unknown',
        ]);

        $this->patchJson($this->url."/{$book->id}", [
            'title' => 'Clinical Anatomy, 3rd ed.',
            'author' => 'R. Snell',
            'subject_id' => $otherSubject->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Clinical Anatomy, 3rd ed.')
            ->assertJsonPath('data.author', 'R. Snell')
            ->assertJsonPath('data.subject_id', $otherSubject->id);

        $this->assertDatabaseHas('student_private_books', [
            'id' => $book->id,
            'title' => 'Clinical Anatomy, 3rd ed.',
            'subject_id' => $otherSubject->id,
        ]);
    }

    /**
     * A field the client does not send is left alone rather than blanked.
     */
    public function test_an_absent_field_is_untouched(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);

        Sanctum::actingAs($student = $this->student());

        $book = StudentPrivateBook::factory()->for($student)->create([
            'subject_id' => $subject->id,
            'title' => 'Keep me',
            'author' => 'Keep me too',
        ]);

        $this->patchJson($this->url."/{$book->id}", ['title' => 'New title'])
            ->assertOk()
            ->assertJsonPath('data.author', 'Keep me too');
    }

    public function test_the_author_can_be_cleared(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);

        Sanctum::actingAs($student = $this->student());

        $book = StudentPrivateBook::factory()->for($student)->create([
            'subject_id' => $subject->id,
            'author' => 'Remove me',
        ]);

        $this->patchJson($this->url."/{$book->id}", ['author' => null])
            ->assertOk()
            ->assertJsonPath('data.author', null);
    }

    public function test_a_book_cannot_be_refiled_under_an_unpublished_subject(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);
        $draft = Subject::factory()->draft()->create();

        Sanctum::actingAs($student = $this->student());

        $book = StudentPrivateBook::factory()->for($student)->create(['subject_id' => $subject->id]);

        $this->patchJson($this->url."/{$book->id}", ['subject_id' => $draft->id])
            ->assertNotFound()
            ->assertJsonPath('code', 'SUBJECT_UNAVAILABLE');

        $this->assertDatabaseHas('student_private_books', [
            'id' => $book->id,
            'subject_id' => $subject->id,
        ]);
    }

    /**
     * Deleting means deleting: the student asked for their own file to go, so
     * the row and the stored PDF both do.
     */
    public function test_deleting_a_book_removes_its_file(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);

        Sanctum::actingAs($student = $this->student());

        $book = StudentPrivateBook::factory()->for($student)->withPdfFile()->create([
            'subject_id' => $subject->id,
        ]);

        $path = $book->pdf()->getPathRelativeToRoot();
        $this->assertTrue(Storage::disk('local')->exists($path));

        $this->deleteJson($this->url."/{$book->id}")->assertOk();

        $this->assertDatabaseMissing('student_private_books', ['id' => $book->id]);
        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    public function test_the_library_requires_authentication(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);
        $book = StudentPrivateBook::factory()->create(['subject_id' => $subject->id]);

        $this->getJson($this->url)->assertUnauthorized();
        $this->postJson($this->url, [])->assertUnauthorized();
        $this->getJson($this->url."/{$book->id}")->assertUnauthorized();
        $this->patchJson($this->url."/{$book->id}", [])->assertUnauthorized();
        $this->deleteJson($this->url."/{$book->id}")->assertUnauthorized();
        $this->getJson($this->url."/{$book->id}/pdf")->assertUnauthorized();
    }
}
