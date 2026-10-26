# Student AI chat integration

## Scope and audit

The application already had Laravel 12, Sanctum student tokens, the `feature:ai` subscription gate, `App\Support\Api\ApiResponse`, database/Redis queue connections, and worker infrastructure. It had no chat models, routes, broadcasting configuration, Reverb server, or application Echo dependency. The implementation adds Reverb and its required transitive dependencies only; existing locked package versions were retained. No AI SDK, provider, intelligence, ingestion, prompt, or streaming implementation is included.

## Local setup

1. Install the updated lockfile with `composer install` if checking out these changes elsewhere.
2. Run `php artisan migrate --no-interaction`. The chat migrations create `ai_conversations`, `ai_messages`, and `ai_message_attachments`; they do not alter learning tables. Review other pending application migrations before running this command on an existing deployment.
3. Configure the following backend environment variables (use your own generated Reverb credentials; these are application credentials for your self-hosted server, with no Pusher subscription):

```dotenv
AI_CHAT_ADAPTER=mock
AI_CHAT_MOCK_FAILURE=false
AI_CHAT_QUEUE_CONNECTION=database
AI_CHAT_QUEUE=default
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=<local-application-id>
REVERB_APP_KEY=<local-public-key>
REVERB_APP_SECRET=<local-private-secret>
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
REVERB_ALLOWED_ORIGINS=localhost,your-frontend-hostname
```

`REVERB_HOST/PORT/SCHEME` are how Laravel reaches Reverb. `REVERB_SERVER_HOST/PORT` are the listener address. Origins are comma-separated hostnames. Use the actual frontend hostname, restrict origins in deployment, and expose a TLS WebSocket endpoint for HTTPS frontends. The frontend can use a different external host/port from Laravel's internal Reverb address. Never expose `REVERB_APP_SECRET` to the browser.

4. Run `php artisan config:clear --no-interaction` after environment changes locally.
5. In separate processes, run:

```sh
php artisan reverb:start --no-interaction
php artisan queue:work database --queue=default --timeout=60 --tries=5 --no-interaction
php artisan schedule:work --no-interaction
```

The site itself is already served by Herd. Use Redis instead of database when that is your existing queue connection; set `AI_CHAT_QUEUE_CONNECTION=redis` and run the corresponding worker. This implementation does not edit existing worker or production settings. Use a shared atomic cache across workers for overlapping-job locks. The job timeout is 60 seconds and the overlap lock expires after 75 seconds; queue visibility/retry intervals must exceed both (the existing database/Redis defaults are 90 seconds). Future Agent network timeouts must remain below the job timeout.

The scheduler runs `ai-chat:recover` every minute. It reschedules pending/processing records untouched for five minutes, recovering a crash between persistence and enqueueing or an interrupted worker. It requires the normal Laravel scheduler in deployment. Recovery and job retries use the same durable reply row, with at most three Agent invocations. Run `php artisan ai-chat:recover --no-interaction` manually if needed.

The default adapter is `unavailable`. `mock` is honored only in `local` and `testing`; production always refuses it. The fixed mock reply explicitly identifies itself as an integration test and contains no medical advice. `AI_CHAT_MOCK_FAILURE=true` exercises retry/failure behavior locally. Unconfigured adapters produce `AGENT_UNAVAILABLE` after asynchronous processing.

## Authentication and access

Use the existing student login flow and Sanctum bearer token on every REST call and broadcast authorization request:

```http
Authorization: Bearer <student-token>
Accept: application/json
Content-Type: application/json
```

The student must have the existing active AI plan feature. Existing subscription denials retain the application's HTTP 200 / `success:false` convention, with `SUBSCRIPTION_REQUIRED` or `FEATURE_NOT_IN_PLAN`. Check `success` as well as HTTP status. Authentication failures are 401. Another student's conversation/message returns 404. Private channel denial is 403. There is no second authentication system.

All chat REST routes are throttled to 60 requests/minute per authenticated student, and submission is additionally limited to 20/minute. Broadcast authorization is limited to 60/minute. A 429 response should be retried according to `Retry-After`.

## REST endpoints

All paths below have prefix `/api/student/ai`.

| Method | Path | Body / result |
|---|---|---|
| GET | `/conversations` | Paginated conversations, newest activity then largest ID first |
| POST | `/conversations` | Optional `title` (1–150 characters); default `New conversation`; 201 |
| GET | `/conversations/{id}` | One conversation |
| PATCH | `/conversations/{id}` | Required `title`, 1–150 characters |
| DELETE | `/conversations/{id}` | Permanently deletes conversation and messages |
| GET | `/conversations/{id}/messages` | Paginated messages, largest ID first |
| POST | `/conversations/{id}/messages` | Submit question; 202 for new work, 200 for a replay |
| GET | `/messages/{id}` | Persisted user/assistant message and latest status |

Lists accept `page` and `per_page`; page size is clamped to 1–100 (default 20 conversations, 50 messages). Message pages are newest-first so page 1 recovers recent work, with higher pages loading older records. Sort by numeric ID ascending for the displayed transcript; timestamps are not unique. Offset pagination can overlap as new rows arrive: reconcile by ID, refresh page 1, and de-duplicate older pages.

Send payload:

```json
{"content":"Test question","client_message_id":"c7871b69-00a8-45b0-8f63-a61c9019e340"}
```

`content` is required, nonblank, and at most 10,000 characters. `client_message_id` is required, 1–100 ASCII letters/digits/underscores/hyphens (UUIDs work). Keep the same key, content, and attachment reference set on network retries. Reuse with different content or references gives 422. Attachment order does not affect retry identity; omitted attachments and an empty array are equivalent. Keys are scoped to the conversation. Database uniqueness and the conversation row lock prevent duplicate user messages; a unique `reply_to_message_id` prevents duplicate assistant replies.

Single conversation success:

```json
{"success":true,"message":"Success.","data":{"id":12,"title":"New conversation","last_message_at":"2026-10-26T12:00:00+00:00","created_at":"2026-10-26T12:00:00+00:00","updated_at":"2026-10-26T12:00:00+00:00"}}
```

New message submission success:

```json
{
  "success":true,
  "message":"Message accepted.",
  "data":{
    "user_message":{"id":41,"conversation_id":12,"reply_to_message_id":null,"role":"user","content":"Test question","status":"completed","client_message_id":"client-1","correlation_id":"5cf26390-4769-4ef0-a482-203ecde39bdf","error_code":null,"created_at":"2026-10-26T12:00:00+00:00","updated_at":"2026-10-26T12:00:00+00:00"},
    "assistant_message":{"id":42,"conversation_id":12,"reply_to_message_id":41,"role":"assistant","content":null,"status":"pending","client_message_id":null,"correlation_id":"5cf26390-4769-4ef0-a482-203ecde39bdf","error_code":null,"created_at":"2026-10-26T12:00:00+00:00","updated_at":"2026-10-26T12:00:00+00:00"}
  }
}
```

Message detail wraps one message object in `data`. Paginated results return `success`, `message`, a `data` array, `meta` (`current_page`, `last_page`, `per_page`, `total`) and `links` (`first`, `last`, `prev`, `next`). Delete returns `success:true`, a message and `data:null`. Standard request validation uses Laravel's 422 `message` and `errors` structure. Other framework 401/403/404 responses use Laravel's JSON error format.

Scheduling failure returns 503 with `success:false`, `code:SCHEDULING_FAILED`, a safe message, `errors:[]`, and the same `data.user_message` / `data.assistant_message` identifiers. Both rows remain persisted; the assistant is failed. A replay returns the same persisted state, without automatically retrying terminal failures. Never interpret 202 as a completed Agent answer. Render all content as text or sanitize Markdown/HTML before displaying it.

## Reverb events

Authorize through `POST /api/student/broadcasting/auth` with `socket_id` and the wire `channel_name` (`private-student.ai.conversation.12`). The server checks the Sanctum student, active AI entitlement and conversation ownership.

Echo subscribes to `student.ai.conversation.{id}` using `.private()`. The wire channel carries the `private-` prefix. Every event uses the message resource fields shown above plus `message_id` (equal to `id`):

| Event | Meaning |
|---|---|
| `chat.message.created` | Persisted user message or pending assistant row |
| `chat.response.processing` | Assistant processing attempt started |
| `chat.response.completed` | Complete response persisted; `content` contains the complete answer |
| `chat.response.failed` | Terminal failure persisted; `content:null` and safe `error_code` |

All payloads include `attachments` (an empty array for messages without attachments), `conversation_id`, `message_id`, `reply_to_message_id`, `role`, `status`, `content`, `client_message_id`, `correlation_id`, `error_code`, `created_at`, and `updated_at`. Pending/processing assistant content is null. No chunks or token events exist. Client-originated socket events are disabled; send through REST.

Assistant lifecycle: `pending → processing → completed | failed`. Retries may emit `processing` again. User messages start completed. Failures use `AGENT_UNAVAILABLE`, `AGENT_FAILED`, or `SCHEDULING_FAILED`; no Agent exceptions or credentials are returned. Response content is currently limited to 20,000 UTF-8 bytes; oversized, empty, or mismatched-correlation replies are treated as Agent failures. Reverb request/message defaults are 262,144 bytes to allow complete escaped Unicode payloads; keep proxy/server limits compatible.

Events are queued only after their state transaction commits. Broadcast enqueue failure is logged using identifiers only; persisted messages remain available. WebSocket delivery may be duplicated, delayed, out of order, or missed. REST is authoritative. Poll pending replies periodically (for example every 5–10 seconds while visible) and refresh on reconnection; a connected socket alone cannot detect every lost event.

## Minimal separate frontend example

Install `laravel-echo` and `pusher-js` in the frontend application. They are not added to this backend's admin asset bundle. Supply frontend variables `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, and `VITE_REVERB_SCHEME` for the browser-reachable socket endpoint. The key is public; never provide the secret.

```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import axios from 'axios';
window.Pusher = Pusher;
const api = axios.create({
  baseURL: backendOrigin,
  headers: { Authorization: `Bearer ${studentToken}`, Accept: 'application/json' }
});
const echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY,
  wsHost: import.meta.env.VITE_REVERB_HOST,
  wsPort: Number(import.meta.env.VITE_REVERB_PORT),
  wssPort: Number(import.meta.env.VITE_REVERB_PORT),
  forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
  enabledTransports: ['ws', 'wss'],
  authorizer: channel => ({
    authorize: (socketId, callback) => {
      api.post('/api/student/broadcasting/auth', {
        socket_id: socketId, channel_name: channel.name
      }).then(({data}) => data.auth
        ? callback(null, data)
        : callback(new Error(data.code || 'Subscription denied'), null)
      ).catch(error => callback(error, null));
    }
  })
});
const messages = new Map();
function upsert(message) {
  const id = message.message_id ?? message.id;
  const previous = messages.get(id);
  // Events are hints: do not replace a terminal response with a late processing event.
  if (previous && ['completed', 'failed'].includes(previous.status)
      && ['pending', 'processing'].includes(message.status)) return;
  if (previous && Date.parse(previous.updated_at) > Date.parse(message.updated_at)) return;
  messages.set(id, {...message, id});
  render([...messages.values()].sort((a, b) => a.id - b.id));
}
async function refresh() {
  const {data} = await api.get(`/api/student/ai/conversations/${conversationId}/messages`);
  if (!data.success) throw new Error(data.code || data.message);
  data.data.forEach(upsert);
}
const channel = echo.private(`student.ai.conversation.${conversationId}`);
for (const event of ['chat.message.created', 'chat.response.processing',
                     'chat.response.completed', 'chat.response.failed']) {
  channel.listen(`.${event}`, upsert);
}
channel.subscribed(refresh); // Refresh after each successful subscribe/reconnect.
channel.error(showConnectionError);
async function send(content, clientMessageId = crypto.randomUUID()) {
  // Store clientMessageId with the draft until resolved; reuse it on retry.
  const {data} = await api.post(`/api/student/ai/conversations/${conversationId}/messages`, {
    content, client_message_id: clientMessageId
  });
  if (!data.success) throw new Error(data.code || data.message);
  upsert(data.data.user_message);
  upsert(data.data.assistant_message);
}
```

Also refresh when the tab becomes visible, use REST polling for nonterminal replies, and fetch higher pages for older history. Upsert on both REST and events. On HTTP 503, reconcile the supplied IDs or refresh; on connection loss preserve the pending UI. Unsubscribe with `echo.leave(...)` when leaving a conversation. Remove deleted conversations from local state; subsequent reads/subscriptions return 404/403. Open subscriptions are not forcibly revoked on plan expiry; entitlement is rechecked on REST and channel authorization.

## Persistence, deletion and Agent handoff

Conversations are permanently deleted, with database cascading deletion of all messages and their attachments. Educational source records and files are never deleted by chat deletion. Jobs carry only the assistant message ID and never create conversations. Deleted pending jobs become no-ops, and late Agent results cannot recreate rows. Completion locks the conversation and checks a per-attempt token so a superseded attempt cannot overwrite the persisted response. The Agent call runs outside the database transaction. Already-authorized socket clients may receive an event queued before deletion; REST 404 is authoritative.

Implement `App\Contracts\AiChatAgentInterface::generateResponse(AgentChatRequest): AgentChatResponse` and replace its container binding in `AppServiceProvider` for the agreed adapter. Request DTO: conversation ID, user message ID, current question content, correlation UUID, and `attachments` from persisted attachment rows (see below). Response DTO: complete content and the same correlation UUID. No history is currently passed; agree context handling with the AI developer before extending this DTO. The adapter must honor the worker deadline and avoid logging sensitive request content. The API, database and event contracts need no change for a complete-response adapter.

The AI developer owns provider/protocol/authentication decisions, medical processing, context strategy and actual answer generation. AI credit consumption/accounting is not implemented by this infrastructure; only the existing AI feature entitlement is applied. Deployment needs migrations, durable queue workers, shared cache locks, scheduler, Reverb process, approved origins, CORS for the separate frontend as applicable, TLS/proxy settings, and normal process restarts after releases. No deployment infrastructure was modified.

## Focused verification

Run only the module tests:

```sh
php artisan test --compact tests/Feature/AiChat/AiChatAttachmentTest.php
php artisan test --compact tests/Feature/AiChat/AiChatTest.php
php artisan test --compact tests/Feature/AiChat/ReverbSmokeTest.php
```

The smoke test requires Node.js 22+ and a free loopback port. It starts a temporary Reverb process, obtains socket-specific private authorization through Laravel, verifies a foreign channel is denied, subscribes an actual WebSocket client and checks complete response delivery. Both child processes are stopped afterward. Test data uses isolated SQLite and factories, with no seeders.

## File inventory

Created:

- `app/Models/AiConversation.php`, `app/Models/AiMessage.php`
- `database/factories/AiConversationFactory.php`, `database/factories/AiMessageFactory.php`
- `database/migrations/2026_10_26_105430_create_ai_conversations_table.php`, `database/migrations/2026_10_26_105430_create_ai_messages_table.php`
- `app/Http/Controllers/Api/Student/AiConversationController.php`, `app/Http/Controllers/Api/Student/AiMessageController.php`
- `app/Http/Requests/Api/Student/StoreAiConversationRequest.php`, `app/Http/Requests/Api/Student/UpdateAiConversationRequest.php`, `app/Http/Requests/Api/Student/StoreAiMessageRequest.php`
- `app/Http/Resources/Api/Student/AiConversationResource.php`, `app/Http/Resources/Api/Student/AiMessageResource.php`
- `app/Contracts/AiChatAgentInterface.php`
- `app/Data/AiChat/AgentChatRequest.php`, `app/Data/AiChat/AgentChatResponse.php`
- `app/Services/AiChat/AiChatService.php`, `app/Services/AiChat/ChatBroadcaster.php`, `app/Services/AiChat/MockAgentAdapter.php`, `app/Services/AiChat/UnavailableAgentAdapter.php`
- `app/Jobs/ProcessAiChatResponse.php`, `app/Events/AiChatMessageChanged.php`, `app/Console/Commands/RecoverAiChatResponses.php`
- `config/ai_chat.php`, `config/broadcasting.php`, `config/reverb.php`, `routes/channels.php`
- `tests/Feature/AiChat/AiChatTest.php`, `tests/Feature/AiChat/ReverbSmokeTest.php`
- `docs/student-ai-chat-api.md`

Modified:

- `composer.json`, `composer.lock` — Reverb dependency and required transitive packages
- `.env.example` — adapter, queue, Reverb and frontend connection variables
- `app/Providers/AppServiceProvider.php` — Agent binding and named chat rate limits
- `bootstrap/app.php` — Sanctum/AI feature protected broadcast authorization
- `routes/api/student.php` — eight chat REST endpoints
- `routes/console.php` — recovery schedule


## Educational attachments

The existing message submission endpoint accepts an optional `attachments` list. Each entry must contain only `type` and `id`. Supported types are `book` (platform books, PDF or content), `chapter`, `highlight` and `note`. Private student books, file uploads, PDF pages, client snapshots and version IDs are not accepted. A PDF reference always means the whole book; no PDF bytes are read or copied by chat.

```json
{
  "content": "Explain these topics.",
  "client_message_id": "attachment-example-1",
  "attachments": [
    {"type": "book", "id": 25},
    {"type": "chapter", "id": 12},
    {"type": "highlight", "id": 45},
    {"type": "note", "id": 89}
  ]
}
```

Up to 10 references are allowed, with no repeated `(type, id)` pair. IDs must be positive integers. Combined server-resolved snapshot data is limited to 100,000 JSON-encoded bytes (`ai_chat.max_attachment_snapshot_bytes`); oversized selections return 422 without truncation. This leaves room for message text and envelope data within the existing Reverb request limit. A null attachments value is invalid; omit the field or send `[]` for text-only messages. Text remains required.

Laravel resolves every snapshot from existing records. Books must be published under a published subject. Chapters must satisfy the existing `StudyAccessService` publication and predecessor-completion rules. Notes and highlights must belong to the authenticated student and their source chapter/page must remain accessible. Deleted, unavailable and unauthorized references return the same 422 reference-validation error before any message is created. Existing conversation ownership and AI subscription checks still apply.

The message, attachments and pending assistant reply are persisted in one transaction. The conversation lock and existing idempotency key protect retries. An identical retry returns the original records and snapshots even after a source is edited, deleted, or becomes inaccessible; it does not read the sources again or queue a second response. Changing the text or reference set under the same key returns 422. To ask a new question against current source content, use a new key.

All message REST responses (submission, detail, pagination) and Reverb message payloads add `attachments`. For example, the following is the attachment field of a user message:

```json
{
  "attachments": [
    {"type": "book", "id": 25, "snapshot": {"title": "Cardiology", "book_type": "pdf", "subject_id": 3, "source_updated_at": "2026-10-26T12:00:00+00:00", "media_id": 70, "media_uuid": "aa8cd337-d6dd-445c-a582-803116d8a104", "media_updated_at": "2026-10-26T12:00:00+00:00"}},
    {"type": "chapter", "id": 12, "snapshot": {"title": "Heart failure", "subject_id": 3, "source_updated_at": "2026-10-26T12:00:00+00:00"}},
    {"type": "highlight", "id": 45, "snapshot": {"selected_text": "Exact highlighted passage", "chapter_page_id": 18, "chapter_id": 12, "subject_id": 3}},
    {"type": "note", "id": 89, "snapshot": {"selected_text": "Original passage", "content": "Exact student note", "chapter_page_id": 18, "chapter_id": 12, "subject_id": 3}}
  ]
}
```

Snapshot fields:

| Type | Persisted snapshot |
|---|---|
| PDF book | Title, `book_type: pdf`, subject ID, observed book update time, media ID/UUID and observed media update time; media fields are null if no PDF is currently attached |
| Content book | Title, `book_type: content`, subject ID and observed book update time; no page bodies |
| Chapter | Title, subject ID and observed chapter update time; no page bodies |
| Highlight | Exact `selected_text`, chapter-page ID, chapter ID, subject ID |
| Note | Exact `content` and `selected_text`, chapter-page ID, chapter ID, subject ID |

Render snapshots for historical display, without requiring the source to exist. Annotation text and titles survive edits/deletion. Treat saved text as untrusted display content and escape/sanitize it. History eagerly loads attachments in one query per page and never resolves source records. Attachments have a foreign key only to their message, so deleting educational content does not remove saved snapshots; deleting chat does not remove educational content.

### Persisted Agent contract

`AgentChatRequest::$attachments` defaults to `[]` for compatibility. Each entry has the shape:

```php
[
    'reference_type' => 'note',
    'reference_id' => 89,
    'snapshot' => [
        'selected_text' => 'Original passage',
        'content' => 'Exact student note',
        'chapter_page_id' => 18,
        'chapter_id' => 12,
        'subject_id' => 3,
    ],
]
```

`ProcessAiChatResponse` builds this array exclusively from the user message's saved `ai_message_attachments` rows on every attempt. It does not use HTTP input or look up live educational sources. Unique message/type/ID constraints prevent duplicated references. Edited or deleted notes/highlights never replace historical text in the Agent DTO. The Mock Agent, durable asynchronous scheduling, private channels and complete-response events remain unchanged. No retrieval, embeddings, PDF processing or medical AI behavior is implemented.

### Historical content consistency and frontend-handoff limitation

**Full historical Book/Chapter content reproducibility is not supported by the current learning architecture. This feature is not ready for a handoff that requires that guarantee.**

Inspection of Book, Chapter, page models and learning migrations found no content revision IDs, revision tables or immutable page versions. `source_updated_at` is observation metadata, not a version identifier: a page edit need not update its parent book/chapter timestamp. Titles, original IDs and timestamps cannot prove unchanged content. Content-book and chapter snapshots preserve historical display labels and identifiers only, not their original page bodies.

PDF snapshots identify the media record selected at submission using its real media ID and UUID. These identifiers are not immutable file versions or checksums. Books use the Media Library `singleFile()` collection; replacement deletes the previous media row and file. Chat retains the old ID/UUID but cannot recover deleted PDF bytes or guarantee detection of in-place file changes. It neither follows replacement media automatically nor invents a version ID. Books without media save null media identifiers. No paths or temporary download URLs are treated as version evidence.

The smallest safe architectural solution is a shared learning-content revision store, outside chat snapshots: retain PDF objects under immutable version keys instead of deleting versions referenced by chat; record an immutable revision manifest for content books/chapters pointing to immutable page revisions; atomically publish a current revision and save its revision ID with each chat attachment. Define retention for referenced revisions and back up their objects. A checksum alone can detect change but cannot restore deleted content. This requires a focused change to learning publish/edit/file-replacement lifecycle and storage retention, and has deliberately not been introduced as an incidental chat change. Existing chats cannot retroactively recover already replaced material.

Before frontend handoff requiring guaranteed original learning context, implement that revision/retention design and extend the saved snapshots/Agent contract with actual immutable revision IDs. Until then, frontend can display historical snapshots but must not promise historical Book/Chapter content access. Apply the new `2026_10_26_123619_create_ai_message_attachments_table.php` migration through the normal release process; it has been exercised only against the isolated test database here. No deployment workflows were changed.

Attachment files added: `app/Models/AiMessageAttachment.php`, `app/Services/AiChat/AttachmentResolver.php`, `database/migrations/2026_10_26_123619_create_ai_message_attachments_table.php`, and `tests/Feature/AiChat/AiChatAttachmentTest.php`. Existing chat service, message request/controller/resource/model, broadcaster, Agent DTO/job and configuration were extended.
