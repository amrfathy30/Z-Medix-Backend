# Student Book Reading API

Book reading follows the Chapter reader pattern:

**Book Details → Ordered Page Index → Fetch One Page by ID → Navigate Using Page IDs.**

All paths below require Sanctum authentication and an active subscription with the library feature. Books and their Subjects must be published. Book access does not depend on Chapter completion.

## Breaking change for the Frontend

`GET /api/student/study/books/{book}/pages` now returns the complete ordered **metadata-only index**. It no longer returns page bodies, pagination `meta`, or pagination `links`. `page` and `per_page` are ignored on this route. There is no paginated content-reading mode.

Replace calls such as `/books/15/pages?per_page=1&page=2` with a request to `/book-pages/{bookPage}`, using the second page's database ID from the index. A page ID is not its position or order value.

The Subject Books list, `GET /api/student/study/subjects/{subject}/books`, still paginates Book list rows. This is unrelated to reading a Book's content.

## Book details and page index

`GET /api/student/study/books/15`

Existing metadata fields are preserved. Content Books now include all active pages in `pages`, ordered by `order`, then `id`, with a 1-based `position`. No page body is included.

```json
{
  "success": true,
  "message": "Success.",
  "data": {
    "id": 15,
    "subject_id": 1,
    "title": "Medical Reference",
    "author": null,
    "classification": "Fundamentals",
    "type": "content",
    "order": 1,
    "has_pdf": false,
    "pdf_url": null,
    "pdf_file_name": null,
    "pages_count": 2,
    "cover_image": null,
    "pages": [
      {"id": 101, "book_id": 15, "position": 1},
      {"id": 102, "book_id": 15, "position": 2}
    ]
  }
}
```

The retained `GET /api/student/study/books/15/pages` route returns the same index directly under `data`:

```json
{
  "success": true,
  "message": "Success.",
  "data": [
    {"id": 101, "book_id": 15, "position": 1},
    {"id": 102, "book_id": 15, "position": 2}
  ]
}
```

An empty content Book returns `pages: []` and `pages_count: 0` in details; its index route returns `data: []`.

## Retrieve exactly one page

`GET /api/student/study/book-pages/101`

```json
{
  "success": true,
  "message": "Success.",
  "data": {
    "id": 101,
    "book_id": 15,
    "position": 1,
    "content": "<p>First page content.</p>"
  }
}
```

`content` is the existing rich-text/HTML value and can be null. This route always addresses one record by ID; pagination parameters do not select content. Position follows the current active `order, id` sequence, matching Chapter page numbering.

## Frontend call sequence

1. Open the Subject with `GET /api/student/study/subjects/{subject}`. Its `books` array already provides Book list rows for the Books tab.
2. Open a selected Book with `GET /api/student/study/books/{book}` and read `type`.
3. For a content Book, use the returned `pages` index; no separate index request is required.
4. Fetch the first page using `GET /api/student/study/book-pages/{pages[0].id}`.
5. For next/previous navigation, choose adjacent IDs from the index and request the same individual-page route, or reuse a cached body.

Use the index length / `pages_count` for navigation bounds. Reordering or deletion can change positions; IDs remain the record identity. Refresh the index when content changes or a page becomes unavailable. No explicit next/previous URLs or Book reading-progress fields are introduced.

## PDF and access behavior

PDF Books keep their existing `pdf_url`, `pdf_file_name`, `has_pdf` and optional admin-entered `pages_count`. Details include an empty `pages` array. Open their file in the PDF reader. The index route continues to return HTTP 404 `BOOK_CONTENT_UNAVAILABLE` for PDFs; the individual-page route also refuses page rows attached to PDFs.

Unpublished/archived Books return HTTP 404 `BOOK_UNAVAILABLE`; unavailable Subjects return HTTP 404 `SUBJECT_UNAVAILABLE`. A missing or soft-deleted page returns HTTP 404. Pages under deleted Books or Subjects cannot expose content. Authentication and library subscription gates apply to the new page route as they do to existing Book routes.

No database, model, Filament editor, stored HTML, media, Chapter implementation or page-ordering changes are required by this API update. The earlier `subject-book-page-content-audit.md` records the pre-change implementation; this document describes the current contract.
