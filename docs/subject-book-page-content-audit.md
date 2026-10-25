# Subject Book Content: Page-by-Page API Audit

Audit date: 2026-10-25. Repository: `/Users/hanafawy/Herd/zmedix`, commit `1058ce35424be01695aecf85c4d00b4555df8789`.

## 1. Executive Summary

**Books already support multiple ordered content pages in storage and in Filament. The student API does not deliver them using the same contract as Chapter pages.**

Chapters return an unpaginated page index without bodies, then expose a separate endpoint to retrieve one page by its database ID. Books return metadata from the detail endpoint and content bodies in a conventionally paginated collection from the pages endpoint. That collection defaults to 15 records and permits 1–50 records per response.

Consequently, a one-page reader is possible today using `per_page=1`, but this is not Chapter-style individual page retrieval. The smallest implementation for parity is additive: retain the existing Book models, schema, admin tools and collection contract; expose an ordered metadata-only page index and a page-by-ID read endpoint. No migration or content conversion is required.

This is a source audit, not a live data or deployment audit. The initial chat workspace `/Users/hanafawy/Documents/ChatGPT/z-medics` was empty; the saved local Z-Medix Laravel project above was used. Database support below means confirmed migration/model support; migration application and production records were not queried. Examples use illustrative IDs and content, derived from actual resource/envelope code, not captured HTTP responses.

## 2. Existing Chapter Page Architecture

### Routes and retrieval

The exact application-relative URLs are:

| Method | URL | Handler | Delivery |
|---|---|---|---|
| GET | `/api/student/study/subjects/{subject}` | `SubjectController::show` | Subject tabs, chapter summaries and Book list rows; no page bodies |
| GET | `/api/student/study/chapters/{chapter}` | `ChapterController::show` | Chapter details and all page metadata; no bodies |
| GET | `/api/student/study/chapter-pages/{chapterPage}` | `ChapterPageController::show` | One page body by database ID |
| POST | `/api/student/study/chapter-pages/{chapterPage}/complete` | `ChapterPageController::complete` | Idempotent completion; page metadata plus reading progress |

The prefix comes from `bootstrap/app.php` and `routes/api/student.php`. GET routes were also verified with `php artisan route:list --method=GET --path=api/student/study --except-vendor`. Deployment origin is unspecified; prepend the actual API origin to these paths. No host or scheme is assumed.

All these routes require Sanctum authentication and `feature:library`. `StudyAccessService` validates publication and, for Chapters, completion of the previous published Chapter. A page inherits its Chapter's access rules. Pages within an accessible Chapter are not individually sequentially locked.

### Storage and ordering

`Subject::chapters()` has many `Chapter` records. `Chapter::subject()` belongs to Subject; `Chapter::pages()` has many `ChapterPage` records; each page belongs to one Chapter.

`database/migrations/2026_10_05_222561_create_chapter_pages_table.php` defines a cascading `chapter_id` foreign key, nullable `longText content`, unsigned `order`, timestamps, soft deletes and an index on `(chapter_id, order)`. The subsequent `2026_10_06_100000_drop_title_from_chapter_pages_table.php` removes the page title.

`Chapter::pages()` sorts by `order`, then `id`. `HasSequentialOrder` appends new records when no explicit order is supplied, scoped to their parent and considering deleted siblings when finding the maximum. `HasDerivedPageNumber` computes a 1-based position among active siblings using the same `(order, id)` comparison. Position is not a stored page number and need not equal `order` or the record ID. Reordering and soft deletion can change positions while IDs remain stable.

### Content and admin management

`app/Filament/Resources/ChapterResource/RelationManagers/PagesRelationManager.php` uses a `RichEditor` for `content`, presents HTML in the infolist and supports create, view, edit, soft delete, restore, force delete and drag reordering. Content is optional. Attachments use public `filament_public` storage under `chapter-pages`, with image MIME types and the image size limit from `config/media.php`.

Text/rich HTML and inline images are supported. Configured image types are JPEG, PNG, WebP and SVG; the default size limit is 5 MiB, configurable by environment. There are no typed text/image/video page blocks or separate page media fields. No dedicated video upload or embedding workflow is configured in this page manager. The global video MIME configuration does not establish page video support. A longText column can hold markup, but supported video authoring, persistence through editor sanitization and frontend playback are not confirmed by this implementation.

### Response contracts

`ChapterController::show` loads all `pages` and the quiz. `ChapterResource` maps every page through `ChapterPageResource(withContent: false)`. There is **no conventional pagination for this page index**, and none of its bodies is returned. A single-page request uses `ChapterPageResource` with content enabled.

Representative `GET /api/student/study/chapters/10`:

```json
{
  "success": true,
  "message": "Success.",
  "data": {
    "id": 10,
    "subject_id": 1,
    "title": "Anatomy basics",
    "references": null,
    "order": 1,
    "is_completed": false,
    "reading_progress": {"completed_pages": 0, "total_pages": 2, "percentage": 0},
    "pages": [
      {"id": 101, "chapter_id": 10, "position": 1, "is_completed": false},
      {"id": 102, "chapter_id": 10, "position": 2, "is_completed": false}
    ],
    "quiz": {"id": 5, "title": "Anatomy basics Quiz", "difficulty": null, "question_count": 2}
  }
}
```

Representative `GET /api/student/study/chapter-pages/101`:

```json
{
  "success": true,
  "message": "Success.",
  "data": {
    "id": 101,
    "chapter_id": 10,
    "position": 1,
    "is_completed": false,
    "content": "<p>First page content.</p>"
  }
}
```

Neither page response supplies explicit previous/next page IDs or URLs. The client selects adjacent IDs from the ordered Chapter page index; total pages is available in `reading_progress.total_pages` and the index length. The single-page response itself has no total. Completion returns the same metadata without `content`, plus updated `reading_progress`, and message `Page marked as completed.`

Evidence: the four controllers/resources above; `app/Models/{Subject,Chapter,ChapterPage}.php`; `app/Models/Concerns/{HasSequentialOrder,HasDerivedPageNumber}.php`; `app/Services/Learning/{StudyAccessService,ChapterProgressService}.php`; `app/Support/Api/ApiResponse.php`.

## 3. Existing Book Content Architecture

The flow is **Subject → published Books → Book metadata → content-page collection or PDF file**. Books and Chapters are independent children of a Subject; neither parents the other.

| Investigation question | Confirmed finding |
|---|---|
| Can a Subject contain multiple Books? | Yes. `Subject::books()` is `hasMany`, ordered by `order, id`; `publishedBooks()` filters publication. No one-Book-per-Subject constraint exists. |
| How does Admin add content? | Create a Book in the Subject's Books relation manager using `BookService::create`, selecting `content` or `pdf`. Open its Book workspace. Content Books expose the Pages relation manager; PDF Books expose upload/replace actions. |
| One Book HTML field or multiple pages? | Multiple `BookPage` rows for content Books, with one nullable HTML/text body per page. `books` itself has no content body column. PDF Books store one uploaded file. |
| Does the database support pages? | Yes in the checked-in schema: dedicated `book_pages`, not `chapter_pages`. Actual deployed schema was not inspected. |
| Full content at once? | Book details contain no page bodies. `/books/{book}/pages` returns a batch of bodies, default 15, capped at 50; a small Book may fit entirely in one response. PDF details return the file URL. |
| Specific page endpoint? | No Book page-by-ID route. `?per_page=1&page=N` can retrieve the current Nth page as a one-element collection. |
| Text/images/videos? | Rich text and inline image uploads mirror Chapters. Dedicated video support is not configured or confirmed. |
| Is order preserved? | Yes: `Book::pages()` sorts by `order, id`. Positions are computed from paginator offset and row index. |
| Page numbers/navigation? | Each row has `position`; collection metadata has `current_page`, `last_page`, `per_page`, `total`, and pagination links. These describe result batches, not necessarily individual reading pages. |
| Consistent with Chapters? | Storage concepts, ordering traits and admin editing are similar. Student delivery differs: body batches versus metadata index + page-by-ID reads. |

`Book::pages()` and `BookPage::book()` already provide the dedicated relationship. `2026_10_06_110001_create_book_pages_table.php` defines `id`, cascading `book_id`, nullable `longText content`, unsigned `order` defaulting to 0, timestamps, soft deletes and `(book_id, order)` index. BookPage uses the same ordering and derived-number traits as ChapterPage but remains a separate model/table.

`2026_10_06_110000_create_books_table.php` stores Subject ownership, title, nullable author, type, status, order and lifecycle columns. Later migrations add nullable classification and nullable `total_pages`. `Book::totalPages()` counts active page rows for content Books; for PDFs it returns the admin-entered count, which may be null. Nothing parses PDFs into page rows.

The Book Pages manager mirrors Chapter page CRUD/reordering and image configuration, using directory `book-pages`. It is hidden for PDF Books. PDF/cover files use separate single-file Media Library collections (`book_pdf`, `book_cover`); inline page attachments use the rich editor's disk configuration, not these Book collections. The Book model prevents changing type away from a PDF with an uploaded file or a content Book with active pages. Empty published Books and optional/empty page bodies are valid current states.

`BookService` manages creation and PDF replacement/removal; it does not split HTML or paginate reading pages. `ChapterService` creates Chapters with quizzes and is not a Book page delivery service.

Evidence: `app/Models/{Book,BookPage,Subject}.php`; Book migrations above and `2026_10_18_{122612_add_classification,134640_add_total_pages}_to_books_table.php`; `app/Filament/Resources/SubjectResource/RelationManagers/BooksRelationManager.php`; `app/Filament/Resources/BookResource.php`; its `Pages/ViewBook.php` and `RelationManagers/PagesRelationManager.php`; `app/Services/Learning/BookService.php`; `config/media.php`.

## 4. Current Student Book API Contracts

### Discovery and details

| Method | Exact application-relative URL | Contract |
|---|---|---|
| GET | `/api/student/study/subjects` | Paginated subject discovery; optional search |
| GET | `/api/student/study/subjects/{subject}` | Subject details, all published Book list rows in `data.books`, `total_books`, Chapters, quizzes and stats |
| GET | `/api/student/study/subjects/{subject}/books` | Paginated published Book list, default 15, limits 1–50 |
| GET | `/api/student/study/books/{book}` | Book metadata, count and PDF fields; no page bodies or page index |
| GET | `/api/student/study/books/{book}/pages` | Content Book page bodies in ordered, paginated batches; default 15, limits 1–50 |

All require authentication and the library feature. `assertCanAccessBook()` requires a published Book and accessible published Subject, without Chapter completion prerequisites. Implicit binding excludes soft-deleted records. There is no Book page route, completion API or persisted Book reading position. Book list rows report `progress_percentage: null`.

`SubjectController::show` loads all published Books with page counts and media. `SubjectDetailsResource` uses `BookListItemResource` for `books`; switching to the Books tab therefore needs no extra request. The separate list endpoint is useful for paginated discovery or refreshing the list. These responses contain no page content.

Representative full response for `GET /api/student/study/subjects/1/books` with one content Book (the Book objects embedded in Subject details use the same row shape):

```json
{
  "success": true,
  "message": "Success.",
  "data": [{
    "id": 20,
    "subject_id": 1,
    "title": "Anatomy reference",
    "author": null,
    "classification": "Fundamentals",
    "type": "content",
    "order": 1,
    "has_pdf": false,
    "pages_count": 2,
    "cover_image": null,
    "progress_percentage": null
  }],
  "meta": {"current_page": 1, "last_page": 1, "per_page": 15, "total": 1},
  "links": {
    "first": "{origin}/api/student/study/subjects/1/books?page=1",
    "last": "{origin}/api/student/study/subjects/1/books?page=1",
    "prev": null,
    "next": null
  }
}
```

`{origin}` stands for the runtime request origin; it is not a literal returned value. The JSON object/envelope fields come from `BookListItemResource` and `ApiResponse::paginatedResponse`.

Representative `GET /api/student/study/books/20`:

```json
{
  "success": true,
  "message": "Success.",
  "data": {
    "id": 20,
    "subject_id": 1,
    "title": "Anatomy reference",
    "author": null,
    "classification": "Fundamentals",
    "type": "content",
    "order": 1,
    "has_pdf": false,
    "pdf_url": null,
    "pdf_file_name": null,
    "pages_count": 2,
    "cover_image": null
  }
}
```

For a PDF Book the shape stays the same: `type` is `pdf`; `has_pdf` reflects upload existence; `pdf_url`/`pdf_file_name` describe the file when present; `pages_count` is the optional manual count. There is no automatic PDF page extraction. A PDF viewer may navigate the file locally, independently of the content-page API.

### Content-page collection

Representative `GET /api/student/study/books/20/pages?per_page=1&page=1` for a two-page Book:

```json
{
  "success": true,
  "message": "Success.",
  "data": [{
    "id": 201,
    "book_id": 20,
    "position": 1,
    "content": "<p>First book page.</p>"
  }],
  "meta": {"current_page": 1, "last_page": 2, "per_page": 1, "total": 2},
  "links": {
    "first": "{origin}/api/student/study/books/20/pages?page=1",
    "last": "{origin}/api/student/study/books/20/pages?page=2",
    "prev": null,
    "next": "{origin}/api/student/study/books/20/pages?page=2"
  }
}
```

`BookPageResource` always includes `content`. For `per_page=2&page=2`, positions are 3 and 4, not 1 and 2. `meta.total` counts content records; `meta.last_page` counts result batches. These are equal only at `per_page=1` (apart from the empty-list paginator convention). IDs are not positions. A request beyond available results returns an empty collection, not an individual-page not-found response.

**Navigation caveat:** neither Book collection query calls `withQueryString()` or `appends()`. Laravel's paginator builds URLs from its configured query values, so generated links do not preserve the requested `per_page`. Following `links.next` from a one-page request without retaining `per_page=1` changes the effective batch size back to 15. Clients must explicitly send both parameters for every reading-page request. This conclusion follows from `BookController` and the installed `Illuminate/Pagination/AbstractPaginator::url()` implementation.

A PDF page-collection request returns HTTP 404:

```json
{
  "success": false,
  "message": "This book has no content of that kind.",
  "errors": [],
  "code": "BOOK_CONTENT_UNAVAILABLE"
}
```

Unpublished Books return 404 `BOOK_UNAVAILABLE`; inaccessible Subjects return 404 `SUBJECT_UNAVAILABLE`. A locked Chapter instead returns 403 `CHAPTER_LOCKED`. Missing subscription/library entitlement is handled earlier by middleware with 403 `SUBSCRIPTION_REQUIRED` or `FEATURE_NOT_IN_PLAN`. Route binding failures use Laravel's not-found handling, not necessarily the domain error envelope above.

Evidence: `routes/api/student.php`; `bootstrap/app.php`; `SubjectController::show`; `BookController::{index,show,pages}`; `SubjectDetailsResource`; `BookListItemResource`; `BookResource`; `BookPageResource`; `ApiResponse`; `HandlesStudyExceptions`; `StudyAccessService`; `StudyAccessException`.

## 5. Chapter vs Book Comparison

Book column refers to `type=content` unless specified.

| Capability | Chapter | Book | Gap |
|---|---|---|---|
| Multiple content pages | Separate ChapterPage rows | Separate BookPage rows | None in storage; PDFs use one file |
| Individual page retrieval | GET by ChapterPage ID | Collection only; one record possible with `per_page=1` | No BookPage-by-ID read |
| Ordered content | `order, id` | `order, id` | None |
| Text content | Nullable HTML/text per page | Nullable HTML/text per page | None |
| Images | RichEditor image attachments | Same configuration, different directory | None |
| Videos | No dedicated workflow confirmed | No dedicated workflow confirmed | Shared limitation, not a Book-specific gap |
| Current page number | Per-page `position`; no `current_page` reader state | Per-row `position`; paginator `current_page` means batch | Different delivery metadata; no persisted Book reader state |
| Total pages | `reading_progress.total_pages` on Chapter details | `pages_count` on details; collection `meta.total` | Available for both; PDF count optional/manual |
| Next/previous page navigation | Client selects adjacent page-index IDs | Batch links; one-page requests possible | No lightweight Book index; nondefault page size omitted from links |
| Admin page management | Page CRUD, restore, reorder | Same for content Books | None |

Chapter completion, highlights, notes, bookmarks and resume logic are Chapter-specific. Their existence does not mean Books implement those capabilities, and they are not prerequisites for page-by-page delivery.

## 6. Confirmed Missing Functionality

1. A student endpoint that retrieves exactly one BookPage by stable database ID.
2. A metadata-only ordered Book page index equivalent to `ChapterResource.pages`; Book details currently expose only the count.
3. Preservation of `per_page` in generated Book pagination links, relevant to the existing one-record-per-request workaround.

There is **no missing Book page table, relationship, ordering mechanism, HTML body field or admin page editor**. Explicit next/previous reading-page links are absent for Chapters too, so adding them is not required for parity. Video authoring/playback is not confirmed for either reader. Book reading progress/resume is absent, but expanding it is a separate feature from content delivery.

## 7. Recommended Minimal Implementation

Recommendation only; none of the following has been implemented.

1. Keep `Book`, `BookPage`, `Book::pages()`, existing page content and both shared ordering traits. Do not merge Books into Chapters or introduce a polymorphic page redesign.
2. Add a metadata-only Book page index. For the smallest Chapter-shaped contract, add an ordered `pages` array to content Book details, containing `id`, `book_id` and derived `position`, without `content`. Build positions from the ordered collection index to avoid one count query per row. Select only the fields needed for metadata. For PDFs, define an empty index if adding the field uniformly.
3. Add a read endpoint analogous to Chapters, for example **proposed** `GET /api/student/study/book-pages/{bookPage}`. Bind a nondeleted BookPage; require its nondeleted parent Book, enforce `assertCanAccessBook()` and content type, then return one `BookPageResource` with position from `positionInBook()`. Apply the same authentication/library middleware and exception envelope as existing student routes.
4. Extend `BookPageResource` with an optional content-inclusion flag, defaulting to its present behavior, or add a small index resource if that keeps existing call sites simpler. Keep Book-specific ownership fields; `ChapterPageResource` is not a drop-in replacement because it assumes Chapter IDs and completion state.
5. Keep `/books/{book}/pages` unchanged for existing consumers. Fixing its query-string preservation is a separate, small compatible improvement if the old reader remains in use; do not replace the collection with a single-object response.

If absolutely no existing response additions are acceptable, serve the new metadata index from a separate proposed endpoint such as `GET /api/student/study/books/{book}/page-index`. This adds a route but leaves Book detail responses byte-shape compatible. The index + single-page read pattern remains the same as Chapters.

Reuse `StudyAccessService`, `ApiResponse`, `HandlesStudyExceptions`, ordered relationships, resource conventions and existing Filament tools. Do not apply Chapter completion locks or `ChapterProgressService` to Books: Book access is independent of the Chapter track. No new generic page service is necessary for these small read operations.

For a future implementation, relevant checks should cover metadata omission of bodies, individual body retrieval, correct position after reorder/deletion, publication and library access, missing/deleted parent/page, PDF refusal and continued compatibility of existing collections. These checks were not written or run during this audit.

## 8. Database and Backward Compatibility Impact

**No database changes are necessary for Chapter-style content Book delivery.** Existing page IDs, parent foreign keys, HTML bodies, orders, soft-delete behavior, indexes and media paths can remain intact. Confirm migration deployment separately before implementing; this audit does not assert production migration status.

No monolithic `books.content` field exists to migrate. If an actual Book has a long body inside a single BookPage, an admin can author additional ordered pages using existing tools. Automatic splitting has no implemented boundary rule and cannot be assumed to preserve rich-text structure or educational meaning. Actual records were not inspected, so no claim is made about how many pages current Books contain. PDF splitting/conversion is a distinct content operation and is unnecessary for this recommendation.

Adding `pages` to Book details is an additive response change; no existing field needs removal or semantic changes. Clients that reject unknown fields should be checked before rollout. A separate index endpoint avoids even that addition. Existing `/books/{book}/pages`, embedded Subject Book rows and Book list contracts can remain unchanged. Never silently remove `content` from the existing collection or change its array/pagination envelope: existing consumers may rely on it.

No frontend consumers were audited outside this backend's scoped files. Compatibility conclusions concern the exposed contract, not verified behavior of deployed apps.

## 9. Expected Frontend API Call Sequence

### Supported today

| Step | API/action | Current support |
|---|---|---|
| 1. Open Subject | `GET /api/student/study/subjects/1` | Returns Book rows under `data.books` |
| 2. Select Books tab | Render `data.books`; optionally `GET /api/student/study/subjects/1/books?page=1&per_page=15` | Supported; extra request optional |
| 3. Select Book | `GET /api/student/study/books/20` | Inspect `type` and `pages_count` |
| 4. Open page 1 | For content: `GET /api/student/study/books/20/pages?per_page=1&page=1` | One-element array if page exists; handle zero-page Books |
| 5. Next page | `GET /api/student/study/books/20/pages?per_page=1&page=2` | Supported by ordinal; retain `per_page=1` explicitly |
| 6. Previous page | `GET /api/student/study/books/20/pages?per_page=1&page=1` | Supported by ordinal; cached body may be reused |

Use `meta.total` and returned `position` to bound ordinal navigation. With PDFs, use `pdf_url` in a PDF reader; do not call the content pages route. A missing file and unknown PDF page count are valid states to handle.

### Recommended Chapter-style flow after implementation

Steps 1–3 remain the same. At step 3, obtain the new lightweight ordered page index from Book details (or the separate index endpoint). Open the first page with **proposed** `GET /api/student/study/book-pages/201`. Move next using the next ID in that index, for example `/api/student/study/book-pages/202`; move back by requesting `/api/student/study/book-pages/201` or using cache. Derive the label and boundaries from positions and index length/count, just as the Chapter reader does.

**Page-by-page delivery does not mean list pagination.** The target pattern is a metadata index followed by stable page-ID reads. Existing Book pagination can deliver one body per request, but provides a different contract. Concurrent admin reorder/deletion can invalidate ordinal assumptions; refresh the index when needed. Stable IDs avoid treating a changing ordinal as a permanent page identity.

## 10. Final Verdict

| Required question | Answer |
|---|---|
| Does Book content currently return page by page? | As ordered page records in paginated batches, yes; one at a time is possible with `per_page=1`. Using the same index + individual-ID approach as Chapters, no. |
| Does the database already support Book pages? | Yes in repository migrations/models, through dedicated `book_pages`. Deployed schema was not queried. |
| Is Book content currently returned in a single response? | Book details return no content bodies. Each pages response includes a batch of bodies, potentially the whole small Book; larger Books require more requests. PDFs are exposed through a single file URL. |
| Can we reuse the existing Chapter page approach? | Yes: reuse its delivery pattern and common infrastructure, retaining the existing BookPage model and Book access rules. |
| What is the minimum implementation needed? | Add a metadata-only ordered Book page index and an authorized single-BookPage read route/controller action; adapt resource content inclusion if reused. No migrations or admin redesign. |
| Will existing Book API responses need to change? | No breaking changes are necessary. An index on Book details is additive; a separate index endpoint avoids changing existing responses entirely. Preserve the current page collection. |

### Verification record

Read relevant implementation, migrations, Filament managers/resources and existing test source. Inspected `tests/Feature/Study/PlatformBookTest.php` (ordered bodies, positions across pagination, PDF refusal, publication, access independence and authentication), `ChapterAccessTest.php` (individual page contract and Chapter gating), the relevant portion of `SubjectTabsTest.php` (Book totals/rows), and `tests/Feature/Learning/{BookPageTest,ChapterPageTest}.php` (schema expectations, editor parity, page creation, ordering/reordering and lifecycle assertions). These are evidence of intended coverage, **not a claim that tests currently pass**.

Executed only read-only inspection commands and filtered Artisan route listing. No tests, seeders, migrations, database queries, API content requests, dependency installation, application edits, OpenAPI/Apidog edits, commits or pushes were performed. The only intentional repository addition is this report.
