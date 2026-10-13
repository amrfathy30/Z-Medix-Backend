# Fellowship Module — Student API

Endpoint reference for frontend integration, with the request and response
shapes each endpoint actually returns.

The OpenAPI document is generated from the routes and controllers by
`dedoc/scramble` and served at **`/docs/api`** (`/docs/api.json` for the raw
document, or `php artisan scramble:export`). This file adds the request and
response examples and the business rules behind the figures, which the generated
document does not carry.

[Appendix — admin question import](#appendix--admin-question-import) covers the
admin side: how duplicate rows are decided and what an update writes. It is not
an HTTP API, but it is what determines the question bank these endpoints serve.

## Conventions

These follow the rest of the student API unchanged.

- **Base**: `/api/student`, behind `auth:sanctum`. Every endpoint below requires
  `Authorization: Bearer <token>`; without one the response is `401`.
- **Locale**: `SetPublicLocale` middleware, so `Accept-Language: en|ar` applies.
- **Success envelope**:
  ```json
  { "success": true, "message": "Success.", "data": { } }
  ```
- **Error envelope**, with a stable machine-readable `code` clients may branch
  on:
  ```json
  { "success": false, "message": "…", "errors": {}, "code": "FELLOWSHIP_…" }
  ```
- **Paginated envelope** adds `meta` (`current_page`, `last_page`, `per_page`,
  `total`) and `links` (`first`, `last`, `prev`, `next`) beside `data`.
- **Validation failures** are Laravel's standard `422` with `errors` keyed by
  field; business-rule failures use the coded envelope above.

### Error codes

| Code | Status | Meaning |
|---|---|---|
| `FELLOWSHIP_UNAVAILABLE` | 404 | The fellowship is inactive, so it is not on offer. |
| `NOT_FOUND` | 404 | The session is not this student's. |
| `FELLOWSHIP_INSUFFICIENT_QUESTIONS` | 422 | The filters cannot fill the requested count; `errors.available_questions` says how many they would yield. No session is created. |
| `FELLOWSHIP_SESSION_NOT_IN_PROGRESS` | 422 | The session is paused. Resume it first. |
| `FELLOWSHIP_SESSION_ALREADY_PAUSED` | 422 | — |
| `FELLOWSHIP_SESSION_ALREADY_IN_PROGRESS` | 422 | — |
| `FELLOWSHIP_SESSION_ALREADY_COMPLETED` | 422 | The session is finished and closed to further action. |
| `FELLOWSHIP_QUESTION_NOT_IN_SESSION` | 404 | — |
| `FELLOWSHIP_QUESTION_OUT_OF_ORDER` | 422 | Answer the current question before any later one. |
| `FELLOWSHIP_QUESTION_ALREADY_ANSWERED` | 422 | An answer is final; a second submission is refused rather than overwriting. |
| `FELLOWSHIP_OPTION_NOT_IN_QUESTION` | 422 | The option key is not one the session's snapshot offers. |

### `average_score`

One figure, one name, everywhere:

```
average_score = correct confirmed answers / all confirmed answers * 100
```

The frontend labels it **Completion Rate** on the fellowship list and **Average
Score** inside a fellowship or a session. It is `null` — not `0` — until the
student has confirmed an answer. The size of the question bank is never the
denominator. Confirmed answers count immediately, including those in a paused
or unfinished session, and a question answered again in a later session counts
as a second answer.

### Historical topic identities

Every `topic_performance` row, wherever it appears, is grouped by the topic a
question was **captured** under, not the one it carries now. A session question
stores both halves of that identity on itself when the session is generated —
the topic's id in `topic_value_id` and its display text in `topic_value` — and
the analytics read those columns and never follow a foreign key to the live
filter value.

That is deliberate: a student's recorded history must not change because an
admin reorganised the question bank afterwards. Four consequences follow, and a
client should expect all of them.

- **Renaming a topic splits it.** Answers given before the rename keep reporting
  under the old text, with the same `topic_value_id`; answers after it report
  under the new one. Two rows may therefore share a `topic_value_id` and differ
  in `topic_value`.
- **Deleting a topic changes nothing already recorded.** The rows stay, text and
  id intact, because nothing in the grouping resolves the id.
- **Deleting and recreating a topic under the same name is a second identity.**
  Two rows will carry the same `topic_value` and different `topic_value_id`s, and
  they are not folded together. The new value is a new row in the filter, and
  the old answers were not given under it.
- **Re-filing a question under a different topic does not move its history.**
  Answers already given stay where they were recorded; only later sessions
  capture the new topic.

So the pair `(topic_value_id, topic_value)` is the identity, not either half of
it. A client that groups, caches or charts these rows should key on the pair —
keying on the name alone merges two histories that the module reports apart, and
keying on the id alone merges a topic with its own former name.

Both halves are nullable, and both are null only for a session question
captured without a Performance Topic at all. Neither is ever nulled afterwards:
`performance_filter_value_id` on a session question deliberately carries **no
foreign key**, so deleting the live filter value cannot null it and recreating
one under the same name cannot make it re-point. A non-null `topic_value_id`
may therefore name a value that no longer exists, which is correct — it is a
historical reference that happened to match a live id while that row was there,
and nothing in the reporting resolves it. Rows are ordered by `topic_value`.

The same rule governs `topics_completed`, which counts distinct captured topics,
and the `performance_topics` list inside a session or its result, which is the
set of topics that session's own questions were captured under rather than the
fellowship's current classification. `performance_topics` on
`GET /api/student/fellowships/{fellowship}` is the exception: it is the
fellowship's live classification, for filling filter controls.

---

## `GET /api/student/fellowships`

The Question Bank screen. Lists active fellowships, not questions.

**Response**

```json
{
  "success": true,
  "message": "Success.",
  "data": [
    {
      "id": 1,
      "name": "Emergency Medicine",
      "image": "https://example.test/storage/fellowships/em.png",
      "performance_topics": [
        { "id": 4, "value": "Cardiology" },
        { "id": 5, "value": "Neurology" }
      ],
      "total_questions": 420,
      "average_score": 76.19
    }
  ]
}
```

`performance_topics` is the fellowship's Performance Topic classification, for
card display. `average_score` is `null` for a student who has answered nothing.

---

## `GET /api/student/fellowships/{fellowship}`

The fellowship dashboard. Every figure aggregates this student's confirmed
answers across all of their sessions in this fellowship.

**Query**

| Param | Default | Notes |
|---|---|---|
| `days` | `7` | Length of the `daily_study_time` window, 1–90. |

**Response**

```json
{
  "success": true,
  "message": "Success.",
  "data": {
    "id": 1,
    "name": "Emergency Medicine",
    "image": "https://example.test/storage/fellowships/em.png",
    "performance_topics": [{ "id": 4, "value": "Cardiology" }],
    "total_questions": 420,
    "study_time_seconds": 10740,
    "average_score": 76.19,
    "topics_completed": 3,
    "day_streak": 5,
    "daily_study_time": [
      { "date": "2026-10-06", "seconds": 0 },
      { "date": "2026-10-07", "seconds": 1800 }
    ],
    "quiz_performance": [
      { "difficulty": "easy", "answered_questions": 12, "correct_answers": 10, "incorrect_answers": 2, "average_score": 83.33 },
      { "difficulty": "medium", "answered_questions": 9, "correct_answers": 6, "incorrect_answers": 3, "average_score": 66.67 },
      { "difficulty": "hard", "answered_questions": 0, "correct_answers": 0, "incorrect_answers": 0, "average_score": null }
    ],
    "topic_performance": [
      { "topic_value_id": 4, "topic_value": "Cardiology", "answered_questions": 14, "correct_answers": 11, "incorrect_answers": 3, "average_score": 78.57 }
    ],
    "recent_activity": [
      {
        "session_id": 31,
        "session_title": "Morning revision",
        "question_text": "Which antiplatelet?",
        "performance_topic": "Cardiology",
        "difficulty": "easy",
        "is_correct": true,
        "answered_within_time": true,
        "answered_at": "2026-10-12T09:14:02+00:00"
      }
    ]
  }
}
```

Notes on the figures:

- **`topics_completed`** — distinct Performance Topics with at least one
  confirmed answer. Not how many topics the student has exhausted.
- **`topic_performance`** — `correct confirmed answers in topic / all confirmed
  answers in topic * 100`, grouped by the topic captured in the session-question
  snapshot. See [Historical topic identities](#historical-topic-identities) for
  what renaming, deleting or re-filing a topic does to these rows; the short of
  it is that it does nothing to history already recorded.
- **`study_time_seconds`** — summed from session activity periods, not from the
  session's lifetime. An open period counts up to this request.
- **`daily_study_time`** — a fixed window ending today, zeroes included. A
  period crossing midnight is split across both days, in the configured
  timezone (`config('fellowship.timezone')`).
- **`day_streak`** — consecutive days with at least one confirmed answer,
  counting back from today, or from yesterday when today has none yet. Never
  inferred from how long a session was left open.
- **`quiz_performance`** — every difficulty level is reported, including the
  untouched ones, so the panel's shape is stable.

---

## `GET /api/student/fellowships/{fellowship}/filters`

Everything the Generate Session form offers.

**Response**

```json
{
  "success": true,
  "message": "Success.",
  "data": {
    "filters": [
      {
        "id": 2,
        "name": "Performance Topic",
        "is_performance": true,
        "values": [{ "id": 4, "value": "Cardiology" }, { "id": 5, "value": "Neurology" }]
      },
      {
        "id": 3,
        "name": "System",
        "is_performance": false,
        "values": [{ "id": 9, "value": "Cardiovascular" }]
      }
    ],
    "difficulties": ["easy", "medium", "hard"],
    "question_statuses": ["all", "answered", "unanswered"],
    "total_questions": 420,
    "question_time_limit_seconds": 90,
    "max_question_count": 200
  }
}
```

Filters are per-fellowship; another fellowship's classifications never appear
here. `question_time_limit_seconds` is the limit a timed session would capture,
shown so the form can state it before the student decides.

---

## `POST /api/student/fellowships/{fellowship}/available-questions`

How many questions a set of filters would actually yield — counted with exactly
the query generation draws from, so the two cannot disagree.

**Request**

```json
{
  "title": "Preview",
  "question_count": 20,
  "difficulties": ["easy", "hard"],
  "question_status": "unanswered",
  "filters": { "3": [9, 10] }
}
```

`title` and `question_count` are required by the shared request object;
`question_count` is echoed back so the form can compare.

**Response**

```json
{
  "success": true,
  "message": "Success.",
  "data": { "available_questions": 37, "requested_question_count": 20, "can_generate": true }
}
```

---

## `POST /api/student/fellowships/{fellowship}/sessions`

Generate a session. All-or-nothing: if the filters cannot fill the requested
count, nothing is created.

**Request**

| Field | Required | Notes |
|---|---|---|
| `title` | yes | Max 255 characters. |
| `question_count` | yes | 1 to `config('fellowship.session.max_question_count')`. |
| `timer_enabled` | no | Default `false`. Captures the platform per-question limit into the session. |
| `difficulties[]` | no | Any of `easy`, `medium`, `hard`. Omitted means all. |
| `question_status` | no | `all` (default), `answered`, `unanswered`. |
| `filters` | no | Object keyed by **filter id**, each an array of **value ids**. |

```json
{
  "title": "Morning revision",
  "question_count": 20,
  "timer_enabled": true,
  "difficulties": ["easy", "medium"],
  "question_status": "unanswered",
  "filters": { "2": [4, 5], "3": [9] }
}
```

Filter semantics:

- Several values **within one filter** are alternatives — OR.
- Values **across different filters** must all be satisfied — AND.
- A filter left out, or sent empty, means **All** and restricts nothing.
- `answered` means this student has already confirmed an answer to the question
  in *any* session of this fellowship; `unanswered` is the complement.
- Only filter values belonging to this fellowship are accepted (`422`
  otherwise).

**Response** — `201`, the same shape as `GET /fellowship-sessions/{id}` below.

**Insufficient pool** — `422`:

```json
{
  "success": false,
  "message": "There are not enough questions matching these filters.",
  "errors": { "available_questions": 12 },
  "code": "FELLOWSHIP_INSUFFICIENT_QUESTIONS"
}
```

---

## `GET /api/student/fellowship-sessions/{session}`

One session's state: its fixed question order, what has been answered, and the
question the student is on. An unfinished session opens on
`current_question_id`.

**Response**

```json
{
  "success": true,
  "message": "Success.",
  "data": {
    "id": 31,
    "fellowship_id": 1,
    "title": "Morning revision",
    "status": "in_progress",
    "question_count": 20,
    "current_position": 3,
    "current_question_id": 544,
    "timer_enabled": true,
    "question_time_limit_seconds": 90,
    "started_at": "2026-10-12T09:00:00+00:00",
    "completed_at": null,
    "study_time_seconds": 842,
    "performance_topics": ["Cardiology", "Neurology"],
    "total_questions": 20,
    "answered_questions": 2,
    "correct_answers": 2,
    "incorrect_answers": 0,
    "average_score": 100,
    "questions": [
      {
        "id": 542,
        "position": 1,
        "fellowship_question_id": 118,
        "question_text": "Which antiplatelet?",
        "difficulty": "easy",
        "performance_topic": { "id": 4, "value": "Cardiology" },
        "filters": [
          { "filter_id": 3, "filter_name": "System", "value_id": 9, "value": "Cardiovascular" }
        ],
        "options": [
          { "key": "801", "option_text": "Aspirin" },
          { "key": "802", "option_text": "Warfarin" }
        ],
        "is_answered": true,
        "answered_at": "2026-10-12T09:01:12+00:00",
        "selected_option_key": "801",
        "selected_option_text": "Aspirin",
        "is_correct": true,
        "correct_option_key": "801",
        "correct_option_text": "Aspirin",
        "explanation": "Aspirin is the first-line antiplatelet.",
        "answered_within_time": true,
        "elapsed_seconds": 72
      },
      {
        "id": 544,
        "position": 3,
        "fellowship_question_id": 206,
        "question_text": "Which anticoagulant?",
        "difficulty": "medium",
        "performance_topic": { "id": 4, "value": "Cardiology" },
        "filters": [],
        "options": [
          { "key": "815", "option_text": "Aspirin" },
          { "key": "816", "option_text": "Warfarin" }
        ],
        "is_answered": false,
        "answered_at": null,
        "selected_option_key": null,
        "selected_option_text": null,
        "is_correct": null,
        "correct_option_key": null,
        "correct_option_text": null,
        "explanation": null,
        "answered_within_time": null,
        "elapsed_seconds": 18
      }
    ]
  }
}
```

Everything here is rendered from the session's own immutable snapshots, so
editing or deleting the source question afterwards changes nothing. On an
unanswered question `is_correct`, `correct_option_key`, `correct_option_text`
and `explanation` are withheld — the answer response is the first disclosure.
`fellowship_question_id` is traceability only and becomes `null` once the source
question is force-deleted.

`question_time_limit_seconds` is the limit *this* session captured at
generation; raising the platform limit later does not re-judge it. It is `null`
when timing was disabled. `elapsed_seconds` is active time already spent on that
question, so the client can show what remains rather than restarting the clock
after a resume.

---

## `POST /api/student/fellowship-sessions/{session}/questions/{sessionQuestion}/answer`

Submit a final answer. The question is addressed by its **session row id**, not
by the live question, so a session stays answerable after the original question
is deleted.

**Request**

```json
{ "option_key": "815" }
```

`option_key` is the string the session served as `options[].key`, echoed back
unchanged. It is validated against that question's snapshot — an option the
admin has since deleted is still answerable, and one they have since added is
not.

**Response**

```json
{
  "success": true,
  "message": "Answer submitted.",
  "data": {
    "question": { "…": "the answered question, as above, with correctness and explanation disclosed" },
    "session": { "…": "the session state, as above, advanced to the next question" }
  }
}
```

Rules:

- Only the question at `current_position` may be answered
  (`FELLOWSHIP_QUESTION_OUT_OF_ORDER`). There is no Skip.
- An answer is final; a second submission is refused
  (`FELLOWSHIP_QUESTION_ALREADY_ANSWERED`), which makes a double-clicked submit
  harmless.
- Answering the last open question completes the session, which the returned
  `session.status` reflects. There is no Finish Early.
- `answered_within_time` is informational: `true` inside the limit, `false`
  after it, `null` when the session was untimed. A late answer is graded exactly
  as an on-time one, and the flag never touches `is_correct`, `average_score`,
  `topic_performance` or any count.

---

## `POST /api/student/fellowship-sessions/{session}/pause`
## `POST /api/student/fellowship-sessions/{session}/resume`

The only two things that stop and start a session's clock. Both return the
session state and the messages `Session paused.` / `Session resumed.`

- **Pause** closes the session's activity period and banks the current
  question's elapsed time. Closing the browser or losing connectivity does
  not — time keeps running.
- **Resume** opens a new activity period and continues the current question from
  the time remaining, with the same questions in the same order, on the question
  it stopped on.
- At most one activity period is ever open per session, so a repeated resume
  cannot double-count time.
- When the per-question limit expires nothing is submitted, nothing advances and
  nothing is written; the student answers normally.

---

## `GET /api/student/fellowships/{fellowship}/sessions`

Paginated Session History, newest first.

**Query** — `per_page` (default 15, max 50), `page`.

**Response**

```json
{
  "success": true,
  "message": "Success.",
  "data": [
    {
      "id": 31,
      "title": "Morning revision",
      "status": "paused",
      "performance_topics": ["Cardiology", "Neurology"],
      "total_questions": 20,
      "answered_questions": 8,
      "correct_answers": 6,
      "incorrect_answers": 2,
      "average_score": 75,
      "study_time_seconds": 842,
      "current_position": 9,
      "can_resume": true,
      "can_view_result": false,
      "started_at": "2026-10-12T09:00:00+00:00",
      "completed_at": null,
      "created_at": "2026-10-12T09:00:00+00:00"
    }
  ],
  "meta": { "current_page": 1, "last_page": 3, "per_page": 15, "total": 34 },
  "links": { "first": "…", "last": "…", "prev": null, "next": "…" }
}
```

`can_resume` and `can_view_result` are derived from the status: an incomplete
session reopens on `current_position`, a completed one shows its result. Every
figure in a row is scoped to that session; the fellowship-level equivalents
aggregate across all of the student's sessions.

---

## `GET /api/student/fellowship-sessions/{session}/result`

A session's result: totals, per-topic accuracy, and the ordered question review.
Available in any state — an unfinished session reports what has been answered so
far.

**Response**

```json
{
  "success": true,
  "message": "Success.",
  "data": {
    "id": 31,
    "fellowship_id": 1,
    "title": "Morning revision",
    "status": "completed",
    "started_at": "2026-10-12T09:00:00+00:00",
    "completed_at": "2026-10-12T09:38:20+00:00",
    "study_time_seconds": 2300,
    "performance_topics": ["Cardiology", "Neurology"],
    "total_questions": 20,
    "answered_questions": 20,
    "correct_answers": 15,
    "incorrect_answers": 5,
    "average_score": 75,
    "topic_performance": [
      { "topic_value_id": 4, "topic_value": "Cardiology", "answered_questions": 12, "correct_answers": 10, "incorrect_answers": 2, "average_score": 83.33 }
    ],
    "questions": [{ "…": "every question in order, with the student's answer, the correct answer, the explanation and answered_within_time" }]
  }
}
```

---

# Appendix — admin question import

Not an HTTP API. The import is a Filament page (`ImportFellowshipQuestions`)
driven over Livewire, and it is documented here because it is what decides the
question bank every endpoint above serves — in particular which questions exist,
which were replaced, and which were deliberately left out.

Three steps: **upload**, **map**, **review**. Only the third writes anything.
Staging validates every row and matches duplicates without creating a single
question, filter or filter value, so a mapping can be tried, looked at and
changed again with no consequence. The fellowship is never a field in the
wizard — it is the record in the URL, so nothing an admin fills in can redirect
an import into a different fellowship.

## Pending duplicate decisions

A staged row is `valid`, `invalid` or `duplicate`.

A duplicate is matched on the normalised question text, and the match has a
**scope** saying where its twin is:

| `duplicate_scope` | Meaning |
|---|---|
| `existing` | It duplicates a question already stored in this fellowship. |
| `file` | It duplicates an **earlier row of the same upload**. |

**Every duplicate starts with no decision at all.** There is no default, and
nothing is inferred from the file: `decision` is null, and the row is *pending*.
This is the module's firmest rule about the import — a duplicate is never
skipped, updated or inserted on an admin's behalf.

While any duplicate is pending, the import cannot be confirmed:

- the Confirm button is disabled, and the page reports how many rows are still
  waiting;
- `FellowshipImportConfirmationService::confirm()` refuses independently, by
  re-counting pending duplicates before it claims the import. The check is not
  only in the UI, so the rule holds if the page is bypassed, stale or scripted
  around.

Clearing a decision puts the row back to pending, and that blocks confirmation
again. A decision can be changed as often as the admin likes until the import is
confirmed; once a row has been processed its decision is fixed.

### The decisions

| Decision | Effect | Recorded outcome |
|---|---|---|
| `skip` | The row is not imported. The stored question, if any, is untouched. | `skipped` |
| `keep_both` | The row is inserted as a new question beside the one it duplicates. | `created` |
| `update_existing` | The row is applied over the question it duplicates. | `updated` |

`update_existing` is **offered only to a row whose scope is `existing`**. A row
duplicating an earlier row of the same file has nothing stored to update yet, so
the choice does not appear for it and the service refuses it if asked. That is
also why a bulk `update_existing` over a mixed selection leaves such rows
pending rather than quietly giving them something else — they go on blocking
confirmation until the admin deals with them.

What `update_existing` writes, precisely:

- the question's own fields — text, explanation, difficulty, Performance Topic —
  are replaced;
- the **whole option set** is rewritten, not merged. An imported row is the
  complete answer set for its question, so matching old options to new ones by
  text would silently keep an option the admin had removed;
- a row carrying no Performance Topic of its own keeps the one the question
  already had, since a question is never left unclassified;
- dynamic filters are touched only where **this import mapped a column** for
  them:

| The file's column | Effect on the question's value |
|---|---|
| Mapped, with a value | Set to that value. |
| Mapped, cell empty | **Cleared.** A mapped empty cell is an explicit instruction, not a missing one. |
| Not in the file at all | Left exactly as it was. |

Clearing a question's value removes only that question's value. The filter and
the values the rest of the bank is filed under are untouched.

Sessions already generated are never affected by an update. They serve their own
snapshots, so no score a student has earned can move — which is the same
guarantee [Historical topic identities](#historical-topic-identities) describes
from the reporting side.

### Individual and bulk actions

Both act on the same rows and produce the same decisions; the bulk action is a
convenience, not a second code path.

- **Individually** — each pending duplicate has its own decision select, showing
  only the decisions that row allows. Choosing one writes it through to the row
  immediately, so a review can be left and resumed from
  `?import=<id>` without losing decisions already made. Each row also carries a
  side-by-side comparison of the stored question and the imported one.
- **In bulk** — rows are ticked individually or with select-all, which selects
  every duplicate of the import, and one decision is applied to the selection.
  Rows the decision does not suit are **skipped, not substituted**, and the
  result reports both figures: how many were applied, and how many were not
  applicable. The selection is then reloaded from the rows, so the screen shows
  what was actually recorded rather than what was asked for.

Neither action can decide an already-processed row.

## Confirmation, failure and retry

Confirmation claims the import under a row lock, moving it from `staged` to
`processing`, so a double-clicked Confirm finds nothing to claim and does
nothing. Each row is then applied **in its own transaction** and stamped with its
outcome and a `processed_at`.

That per-row stamping is what makes a retry safe. A row already stamped is
skipped, so a confirmation interrupted part way through resumes rather than
repeats:

- **A single row that fails** is recorded as `failed` with its reason and the
  rest of the file still goes in — the same principle as row validation, applied
  to the write. Nothing of a failed row is written, since its own transaction
  rolled back.
- **A failure after rows have been committed** cannot roll them back, and is not
  meant to. The import is put back to `staged` with its reason in
  `failure_reason` and no `confirmed_at`, which is the state a retry can claim;
  the committed rows keep their outcomes and their link to the question they
  created. Retrying finishes the rows still pending and completes the import,
  inserting no question and no filter value twice.

Row outcomes are `created`, `updated`, `skipped` or `failed`, and the import's
`created_questions` / `updated_questions` / `skipped_questions` totals are
recounted from the rows themselves rather than accumulated, so a summary cannot
drift from what actually happened.

Invalid rows are never applied, whatever else the import does.
