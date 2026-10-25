<?php

namespace Tests\Feature\Study;

use App\Enums\ContentStatus;
use App\Models\Book;
use App\Models\BookPage;
use App\Models\Plan;
use App\Models\StudentHighlight;
use App\Models\StudentNote;
use App\Models\Subject;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

class StudentCollectionFiltersTest extends StudyTestCase
{
    /** @return array<string, array{string, class-string, string}> */
    public static function collections(): array
    {
        return [
            'notes' => ['/api/student/study/notes', StudentNote::class, 'content'],
            'highlights' => ['/api/student/study/highlights', StudentHighlight::class, 'selected_text'],
            'books' => ['/api/student/books', Book::class, 'title'],
        ];
    }

    #[DataProvider('collections')]
    public function test_search_and_multi_subject_filters_preserve_the_collection_contract(string $url, string $model, string $field): void
    {
        Sanctum::actingAs($student = $this->student());
        $subjects = [];
        $matchingIds = [];

        foreach (range(1, 3) as $index) {
            $subject = $this->subjectWithChapters(chapters: 1, pages: 1);
            $subjects[] = $subject->id;
            $attributes = $model === Book::class
                ? ['subject_id' => $subject->id, 'author' => 'Unrelated keyword']
                : ['user_id' => $student->id, 'chapter_page_id' => $subject->chapters()->first()->pages()->first()->id];

            $matchingIds[] = $model::factory()->create([...$attributes, $field => 'Saved keyword '.$index])->id;
            $model::factory()->create([...$attributes, $field => 'Other text']);
        }

        $this->getJson($url)->assertOk()->assertJsonCount(6, 'data')->assertJsonPath('meta.total', 6);
        $this->getJson($url.'?search=keyword')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson($url.'?'.http_build_query(['subject_ids' => array_slice($subjects, 0, 2)]))
            ->assertOk()->assertJsonCount(4, 'data');

        $filters = ['search' => 'keyword', 'subject_ids' => array_slice($subjects, 0, 2), 'subject_id' => $subjects[2], 'per_page' => 1];
        $response = $this->getJson($url.'?'.http_build_query($filters))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)->assertJsonPath('success', true)->assertJsonPath('message', 'Success.')
            ->assertJsonStructure(['links' => ['first', 'last', 'prev', 'next']]);
        $second = $this->getJson($url.'?'.http_build_query([...$filters, 'page' => 2]))->assertOk();
        $this->assertEqualsCanonicalizing(array_slice($matchingIds, 0, 2), [$response->json('data.0.id'), $second->json('data.0.id')]);

        $this->getJson($url.'?subject_id='.$subjects[0])->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($url.'?'.http_build_query(['subject_ids' => [], 'subject_id' => $subjects[0]]))
            ->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($url.'?'.http_build_query(['subject_ids' => []]))->assertOk()->assertJsonCount(6, 'data');
        $this->getJson($url.'?search=')->assertOk()->assertJsonCount(6, 'data');
        $this->getJson($url.'?search=missing')->assertOk()->assertJsonCount(0, 'data');
    }

    #[DataProvider('collections')]
    public function test_invalid_filters_are_validation_errors(string $url, string $model, string $field): void
    {
        Sanctum::actingAs($this->student());

        foreach (['subject_ids=1', 'subject_ids[]=0', 'subject_ids[]=-1', 'subject_ids[]=abc', 'subject_ids[]=1.5', 'subject_ids[0][]=1', 'subject_ids[]=', 'subject_id=0', 'search[]=text', 'search='.str_repeat('a', 101)] as $query) {
            $this->getJson($url.'?'.$query)->assertUnprocessable();
        }

        // Match the legacy contract: annotations validate existence; books simply yield no rows.
        $response = $this->getJson($url.'?subject_ids[]=999999');
        if ($model === Book::class) {
            $response->assertOk()->assertJsonCount(0, 'data');
        } else {
            $response->assertUnprocessable();
        }
    }

    public function test_notes_search_saved_content_and_never_the_selected_passage(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();
        Sanctum::actingAs($student = $this->student());
        StudentNote::factory()->for($student)->create(['chapter_page_id' => $page->id, 'content' => 'No match here', 'selected_text' => 'keyword']);
        $note = StudentNote::factory()->for($student)->create(['chapter_page_id' => $page->id, 'content' => 'Saved keyword', 'selected_text' => 'Passage']);

        $this->getJson($this->studyUrl.'/notes?search=keyword')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $note->id)
            ->assertJsonStructure(['data' => [['id', 'chapter_page_id', 'content', 'selected_text', 'created_at', 'updated_at', 'context']]]);
    }

    public function test_filtered_annotations_preserve_ownership_publication_and_chapter_access(): void
    {
        Sanctum::actingAs($student = $this->student());
        $other = $this->student();
        $subject = $this->subjectWithChapters(chapters: 3, pages: 1);
        [$open, $draft, $locked] = $subject->chapters()->get()->all();
        $draft->update(['status' => ContentStatus::Draft]);
        $hidden = $this->subjectWithChapters(chapters: 1, pages: 1);
        $hidden->update(['status' => ContentStatus::Draft]);

        foreach ([StudentNote::class => 'content', StudentHighlight::class => 'selected_text'] as $model => $field) {
            foreach ([$open, $draft, $locked, $hidden->chapters()->first()] as $chapter) {
                $model::factory()->for($student)->create(['chapter_page_id' => $chapter->pages()->first()->id, $field => 'keyword']);
            }
            $model::factory()->for($other)->create(['chapter_page_id' => $open->pages()->first()->id, $field => 'keyword']);
        }

        foreach (['notes', 'highlights'] as $collection) {
            $query = http_build_query(['search' => 'keyword', 'subject_ids' => [$subject->id, $hidden->id]]);
            $this->getJson($this->studyUrl.'/'.$collection.'?'.$query)->assertOk()->assertJsonCount(1, 'data');
            $this->getJson($this->studyUrl.'/'.$collection.'?'.$query.'&chapter_id='.$locked->id)->assertOk()->assertJsonCount(0, 'data');
            $this->getJson($this->studyUrl.'/'.$collection.'?subject_ids[]='.$hidden->id)->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_books_preserve_publication_counts_and_exclude_page_bodies(): void
    {
        Sanctum::actingAs($this->student());
        $subject = Subject::factory()->create();
        $hidden = Subject::factory()->draft()->create();
        $pdf = Book::factory()->pdf()->create(['subject_id' => $subject->id, 'title' => 'keyword PDF', 'pdf_pages_count' => 12, 'order' => 1]);
        $content = Book::factory()->content()->create(['subject_id' => $subject->id, 'title' => 'keyword Content', 'order' => 2]);
        BookPage::factory()->count(2)->create(['book_id' => $content->id]);
        Book::factory()->draft()->create(['subject_id' => $subject->id, 'title' => 'keyword Draft']);
        Book::factory()->archived()->create(['subject_id' => $subject->id, 'title' => 'keyword Archived']);
        Book::factory()->create(['subject_id' => $hidden->id, 'title' => 'keyword Hidden']);

        $this->getJson('/api/student/books?'.http_build_query(['search' => 'keyword', 'subject_ids' => [$subject->id, $hidden->id]]))
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $pdf->id)
            ->assertJsonPath('data.0.pages_count', 12)->assertJsonPath('data.1.pages_count', 2)
            ->assertJsonMissingPath('data.0.pages')->assertJsonMissingPath('data.1.pages')
            ->assertJsonMissingPath('data.1.content');
    }

    public function test_unpaginated_subjects_preserve_fields_progress_order_and_search(): void
    {
        Sanctum::actingAs($student = $this->student());
        $subject = $this->subjectWithChapters(chapters: 2, pages: 1);
        $subject->update(['name' => 'Anatomy A']);
        $this->completeChapter($student, $subject->chapters()->first());
        Subject::factory()->create(['name' => 'Anatomy B']);
        Subject::factory()->draft()->create(['name' => 'Anatomy Draft']);
        Subject::factory()->archived()->create(['name' => 'Anatomy Archived']);
        $query = '?search=Anatomy&per_page=1';
        $paginated = $this->getJson($this->studyUrl.'/subjects'.$query)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $normal = $this->getJson($this->studyUrl.'/subjects'.$query.'&without_pagenation=false')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonStructure(['meta', 'links' => ['first', 'last', 'prev', 'next']]);
        $this->assertSame($paginated->json('data'), $normal->json('data'));
        $this->assertSame($paginated->json('meta'), $normal->json('meta'));
        $all = $this->getJson($this->studyUrl.'/subjects'.$query.'&without_pagenation=true&page=9')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('meta')->assertJsonMissingPath('links')
            ->assertJsonPath('data.0.chapters.completed_chapters', 1)->assertJsonPath('data.0.chapters.percentage', 50)
            ->assertJsonPath('data.1.name', 'Anatomy B');
        $this->assertSame($paginated->json('data.0'), $all->json('data.0'));
        $this->getJson($this->studyUrl.'/subjects?search=missing&without_pagenation=true')
            ->assertOk()->assertExactJson(['success' => true, 'message' => 'Success.', 'data' => []]);
    }

    public function test_unpaginated_subjects_have_no_page_size_limit(): void
    {
        Subject::factory()->count(55)->create();
        Sanctum::actingAs($this->student());
        $this->getJson($this->studyUrl.'/subjects?without_pagenation=true&per_page=1')
            ->assertOk()->assertJsonCount(55, 'data')->assertJsonMissingPath('meta')->assertJsonMissingPath('links');
    }

    public function test_all_four_endpoints_preserve_authentication_and_entitlement_denials(): void
    {
        $urls = ['/api/student/books?subject_ids[]=1', $this->studyUrl.'/notes?search=keyword', $this->studyUrl.'/highlights?search=keyword', $this->studyUrl.'/subjects?without_pagenation=true'];
        foreach ($urls as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        Sanctum::actingAs(User::factory()->create());
        foreach ($urls as $url) {
            $this->getJson($url)->assertOk()->assertJsonPath('success', false)
                ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED')->assertJsonMissingPath('data');
        }
        Sanctum::actingAs($this->subscribedStudent(Plan::factory()->create()));
        foreach ($urls as $url) {
            $this->getJson($url)->assertOk()->assertJsonPath('success', false)
                ->assertJsonPath('code', 'FEATURE_NOT_IN_PLAN')->assertJsonMissingPath('data');
        }
    }
}
