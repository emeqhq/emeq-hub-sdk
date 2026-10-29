# emeq Hub SDK

Laravel 13 consumer client for the emeq Hub `/v1` API (Saloon v4).

**Provider-agnostic:** new Hub partners (Moneybird, …) appear via
`Hub::integrations()->list()` and `Hub::oauth()->init($provider, …)` without an
SDK release. Partner wire stays in the Hub + `emeq/*-api` packages — this SDK
does **not** expose per-partner pass-through.

Payload shapes, OAuth UX, and OpenAPI live in the Hub docs — see
[Further reading](#further-reading). This README covers install, call sites and
the API surface; webhook wiring lives in [`docs/webhooks.md`](docs/webhooks.md).

## Install

```bash
composer config repositories.emeq-hub-sdk vcs https://github.com/yusufkaracaburun/emeq-hub-sdk.git
composer require emeq/hub-sdk:^0.24
```

```bash
php artisan hub:install
# or publish selectively:
php artisan vendor:publish --tag=hub-config
php artisan vendor:publish --tag=hub-migrations
```

```env
EMEQ_HUB_BASE=https://hub.emeq.nl
EMEQ_HUB_PAT=your-sanctum-pat
EMEQ_HUB_TIMEOUT=30
EMEQ_HUB_WEBHOOK_SECRET=shared-with-hub-consumer-callback-secret
# Opt-in BFF routes (default off) — enable + tune middleware to your guard
EMEQ_HUB_ROUTES=true
EMEQ_HUB_ROUTES_PREFIX=api
# Default when unset: api,auth:sanctum,throttle:60,1 — this var is comma-split,
# so use a named limiter (throttle:hub) if you override it.
EMEQ_HUB_ROUTES_MIDDLEWARE=api,auth:sanctum
# Cache store for webhook dedupe locks. Empty = default store (must support locks).
EMEQ_HUB_WEBHOOK_LOCK_STORE=redis
# How long one delivery may hold that lock.
EMEQ_HUB_WEBHOOK_LOCK_SECONDS=30
# Relative path on YOUR host only (no https://…). Empty = omit return_url.
EMEQ_HUB_OAUTH_RETURN_PATH=/settings/integrations?oauth=1
# Booking documents? See "Booking documents" below.
EMEQ_HUB_BOOKING_CONNECTION=
EMEQ_HUB_BOOKING_LOCK_STORE=redis
```

### Checklist

1. Publish config + migrations (`hub:install` or tags above).
2. Set `EMEQ_HUB_*` in `.env`.
3. Bind `ResolvesAccountId` (`accountId()` + `displayName()`).
4. Receiving Hub webhooks? Bind `ResolvesWebhookAccount`, register the route and
   migrate `webhook_calls` — [`docs/webhooks.md`](docs/webhooks.md). The package
   does **not** auto-run migrations.
5. Booking documents? Migrate `hub_documents` onto the database that holds them
   and set `hub.booking.connection` when that is not your default —
   [Booking documents](#booking-documents).
6. Run `php artisan hub:doctor` — see [Checking the wiring](#checking-the-wiring).

### Checking the wiring

```bash
php artisan hub:doctor          # configuration only, no network
php artisan hub:doctor --ping   # also calls Hub with the configured PAT
```

It reads exactly what this application booted with and reports per check:
base URL and PAT, request timeout, both lock stores and their windows —
by taking and releasing a throwaway lock, so the `database` store missing its
`cache_locks` table fails here instead of at the first booking —
`ResolvesAccountId` / `ResolvesWebhookAccount` bindings, the `hub_documents`
table (and whether it carries the trace and accounting-change columns) on the
connection `hub.booking.connection` names, the `webhook-client` entry and its
signing secret, `webhook_calls` on the default connection, the BFF middleware
stack and the OAuth return path.

Exit code is non-zero when a check **fails**; warnings do not fail the command.
A warning means the feature that check covers is off or unmigrated — fine when
this app does not use it, a missing deploy step when it does.

One thing it cannot see: in a multi-database application `webhook_calls` must
also exist on the connection `ResolvesWebhookAccount::prepare()` binds, and the
command can only inspect the default connection. Check that one yourself —
[`docs/webhooks.md`](docs/webhooks.md).

The package registers auth-protected routes when `EMEQ_HUB_ROUTES=true`
(default `false`). Middleware must be non-empty **and carry an `auth`-family
entry** — exactly `auth`, `auth:*` or `auth.basic` (case-sensitive;
`auth.session` does not count). Otherwise boot fails. If your auth middleware is
named something else (`tenant.auth`), set `hub.routes.allow_unauthenticated` to
`true` to declare that deliberate.

| Method | Path | Action |
|---|---|---|
| `GET` | `/{prefix}/integrations` | optional: list providers + status |
| `POST` | `/{prefix}/integrations/connect-session` | mint Hub hosted `/connect` URL |

**Recommended UX:** one button that `POST`s `connect-session` and redirects to
`url`. Users manage every provider (connect / disconnect / status) on Hub —
do not re-implement per-provider OAuth in your app. Use `Hub::oauth()` /
`Hub::connections()` only for server-side automation.

Set `EMEQ_HUB_OAUTH_RETURN_PATH` to a path on **your** host (or leave empty).
The SDK never assumes an app-specific URL.

## Account binding

Bind your tenant → Hub `external_id` server-side:

```php
use Emeq\HubSdk\Contracts\ResolvesAccountId;

class HubAccountIdResolver implements ResolvesAccountId
{
    public function accountId(): string
    {
        return (string) current_tenant_id(); // your app
    }

    public function displayName(): ?string
    {
        return current_tenant_name(); // null is fine — Hub names the account
    }
}

// AppServiceProvider
$this->app->bind(ResolvesAccountId::class, HubAccountIdResolver::class);
```

Optionally bind `ResolvesConnectSessionContext` to send the connect page your
`categories`, a `mode` (`manage` / `view`) and the `actor` for the logged-in
user. Throw from it (e.g. `abort(403)`) to deny the session. Unbound, the
connect-session route sends none of them.

```php
use Emeq\HubSdk\Contracts\ResolvesConnectSessionContext;
use Illuminate\Contracts\Auth\Authenticatable;

class HubConnectSessionContext implements ResolvesConnectSessionContext
{
    public function context(?Authenticatable $user): array
    {
        return [
            'categories' => [['key' => 'fuel', 'label' => 'Brandstof', 'type' => 'expense']],
            'mode' => $user?->can('manage-integrations') ? 'manage' : 'view',
            'actor' => ['name' => $user->name, 'email' => $user->email],
        ];
    }
}
```

## Inbound Hub webhooks

Spatie `webhook-client` bases live in the SDK; apps only wire tenancy + handlers.
One published file: `config/hub.php` — at boot the package upserts the Hub entry
into Spatie’s `webhook-client.configs` from `hub.webhook.*`. Listen for these
rather than cargo-culting empty profile/job subclasses:

| Event | When |
|---|---|
| `HubWebhookReceived` | Every accepted envelope (before per-event hooks) |
| `HubConnectionRevoked` | `connection.revoked` |
| `HubWebhookHandled` | An event your job's `handles()` claims |
| `HubWebhookIgnored` | Any other canonical event (default: log only) |

Override `handles(): array` on a `ProcessHubWebhookJob` subclass to name the
`HubWebhookEvent` cases you act on, and `onEvent()` to act on them — cleaner
than claiming an event inside `onIgnored()` before calling `parent::`.

Wiring, multi-DB connection placement (**gets this wrong and it fails
silently**), and deduplication: [`docs/webhooks.md`](docs/webhooks.md).

## Booking documents

`Hub::accounting()->createDocument()` is the raw call. Everything below it —
the ledger, the retry policy, the backlog, the batch loop — ships here, and
three interfaces are what you implement.

| You write | The SDK takes over |
|---|---|
| a mapper: your model → canonical document | `DocumentBooker` — lock, send, classify, record |
| `ResolvesBookableDocument` — find, authorise, map | `BookingRunner` — check / book, one or a batch |
| `ProvidesBacklogSources` — your tables → nine columns | `BacklogRepository` — join, filter, sort, page, summarise |

Nothing here knows your data model, and nothing in your app has to know Hub's
retry rules.

### Booking one document

```php
use Emeq\HubSdk\Booking\BookingOutcome;
use Emeq\HubSdk\Booking\DocumentBooker;

$record = app(DocumentBooker::class)->book(
    $this->mapper->toDocument($invoice),      // your mapping, your models
    attachments: fn () => [$this->pdf->render($invoice)],
);

$outcome = BookingOutcome::from($record);     // booked / refused / needs a human
```

Mapping stays in your app: what a sales invoice looks like is your data model.
Throw `Emeq\HubSdk\Exceptions\DocumentNotBookable` from your mapper for
documents that can never be sent (a draft, a missing party) — nothing is sent
and nothing is recorded.

`party.kind` (`company` | `person`) and `party.external_id` are required; an
optional `party.relation_id` pins the resolved relation and skips Hub's
matching ladder entirely. There is no flag for "create the relation if it does
not exist" — Hub decides that deterministically and reports what it did in
`warnings[]` on the posted record (`relation.created`, `relation.matched_by_name`,
`relation.name_differs`, `relation.relinked`), reachable as `$record->warnings`
and, once resolved, `$outcome->warnings`.

That list is Hub's, not this package's: warnings are passed through unread, so a
code Hub adds arrives without an SDK release. Treat an unknown `code` as
something to show rather than something to drop — `relation.relinked` is exactly
what the previous release would have swallowed.

Rules the ledger encodes, and the reason it exists rather than asking Hub every
time ([ADR-0003](docs/adr/0003-the-booking-ledger-lives-in-the-consumer.md)):

| Hub answered | Ledger | Retry |
|---|---|---|
| `201` | `posted` + `external_ref` / `external_number` | never — a correction is a credit note |
| `document_already_posted`, `idempotency_key_reuse`, `upstream_rejected` | `rejected` | only after fixing the document |
| any other Hub error | `failed` (reported) | after fixing the cause |
| `429` / `5xx` | **no row** — throws `BookingTemporarilyUnavailable` | yes, same key |
| `idempotency_request_in_progress`, `document_sync_in_progress` | **no row** — throws `BookingAlreadyInProgress` | yes, once the run in front finishes |
| transport dropped mid-send | `unknown` | yes, if the document still says the same thing |

"No row" and "a row saying failed" mean different things to the next run. That
is the whole retry policy.

An `unknown` row means the send was interrupted and nobody knows whether it
landed. Offering the same document again, unchanged, is safe and is how such a
row is resolved: Hub replays the response it stored against that idempotency
key, and once that has expired its per-connection guard recognises the document
by content fingerprint and answers `deduplicated` instead of booking it twice.
Change the content in between and you get `document_already_posted` — also
correct, because a correction is a credit note. Nothing retries this for you:
only your app knows the document is unchanged.

### Retrying under load

`BookingTemporarilyUnavailable`, `BookingAlreadyInProgress` and both outcome
types carry `retryAfter` — the seconds Hub asked you to wait, taken from its
`Retry-After` header. Honour it. Hub rate-limits per consumer, so a fleet that
retries on a fixed interval synchronises itself into one throttled herd.

The SDK deliberately does not retry inside the call: waiting there pins a PHP
worker for the duration. Retry from a queue instead.

```php
class BookDocumentJob implements ShouldQueue
{
    public int $tries = 5;

    public function handle(BookingRunner $runner): void
    {
        $outcome = $runner->bookOne('invoice', $this->uuid);

        if ($outcome->mayRetry()) {
            $this->release($outcome->retryAfter ?? $this->backoffSeconds());
        }
    }
}
```

`mayRetry()` is true only for 503 — the answers that say nothing about your
document. A 422 or 502 must not be retried blindly.

### Booking from a controller

Implement `ResolvesBookableDocument` once — find the record, authorise it, map
it — and `BookingRunner` answers for one document or a batch:

```php
use Emeq\HubSdk\Booking\BookableDocument;
use Emeq\HubSdk\Booking\Contracts\ResolvesBookableDocument;
use Emeq\HubSdk\Exceptions\DocumentNotAuthorized;

class BookableDocuments implements ResolvesBookableDocument
{
    public function resolve(string $module, string $id): BookableDocument
    {
        $invoice = Invoice::where('uuid', $id)->firstOrFail();   // ModelNotFoundException → 404

        if ($request->user()->cannot('book', $invoice)) {
            throw new DocumentNotAuthorized;                     // → 403
        }

        return new BookableDocument(
            $this->mapper->toDocument($invoice),                 // DocumentNotBookable → 422
            attachments: fn () => [$this->pdf->render($invoice)],
        );
    }
}
```

```php
$outcome = app(BookingRunner::class)->bookOne('invoice', $uuid);
$results = app(BookingRunner::class)->book($request->documents());   // stops on its time budget
$checks  = app(BookingRunner::class)->check($request->documents());  // free, catches most refusals
```

A batch stops once `hub.booking.batch_seconds` is spent and returns fewer
results than asked. Repeat with the remainder — safe, because an already-posted
document is never sent twice.

A batch also stops at the first `429`. Every document from that one on comes
back with `$outcome->rateLimited` true, status `503` and `retryAfter` (Hub's
`Retry-After`, or 60 seconds without one). The documents after it are not
checked and not sent; each still fires `DocumentBookingFailed` with that
`rateLimited` outcome, so you get one event per requested document. Retry them
all after the wait: each document is sent under the same
`Idempotency-Key` again, so a retry never books one twice.

Routes, request validation and your response envelope stay yours; the SDK ships
`Booking\Resources\*` and `Backlog\Resources\*` for the payload shapes.

### The backlog

"Which of my documents are not booked yet" is a join over your own tables, which
Hub cannot participate in. Implement `ProvidesBacklogSources` — return one query
per module with the nine columns the interface names, excluding posted documents
with `PostedDocuments::excluding()` — and:

```php
$page    = app(BacklogRepository::class)->paginate($filters);  // rows carry hub_document
$summary = app(BacklogRepository::class)->summary($filters);   // whole filter, not the page
```

Filters: `search_term`, `start_date`, `end_date`, `modules`, `status`,
`direction`, `min_amount`, `max_amount`, `sort_by`, `order`, `page_length`,
`accounting_changed`. Validate `sort_by` / `direction` / `status` against
`BacklogRepository::SORTS`, `::DIRECTIONS` and `BacklogStatus::all()`, and
`page_length` against `::MAX_PAGE_LENGTH`.

A document the bookkeeping changed after this consumer booked it — an
`accounting.*.changed` webhook naming a document already `posted` — belongs
back in the backlog even though `PostedDocuments::excluding()` would otherwise
drop it: `excluding()` only drops a posted document while
`accounting_changed_at` is still null. `accounting_changed: true` filters to
exactly those; it composes with `status` rather than replacing it (a changed
document is `posted` *and* changed, not a `BacklogStatus` case), so pass it
without a `status` filter to see them. The summary carries a matching
`accounting_changed` count, a sibling of `by_status`, and every row exposes
`accounting_changed_at` (ISO 8601) / `accounting_change_action` directly —
no need to read into `booking` for the marker.

Nothing to wire for the marker itself: `AccountingChangeRecorder` listens for
`HubWebhookReceived` and writes those columns when a change event names a
document this consumer posted. It joins on `external_ref` — the provider's own
id, which Hub returns when you book and repeats as `entity_id` on the event —
scoped to the account, and it ignores the echo of your own write so a booking
does not badge itself seconds later.

```env
# Off if you write the columns yourself. Off also means the filter stays empty.
EMEQ_HUB_BOOKING_RECORD_ACCOUNTING_CHANGES=true
# How long after a booking a change still counts as that write's echo.
EMEQ_HUB_BOOKING_ECHO_WINDOW_SECONDS=300
```

A ledger without the three columns — a consumer that books nothing, or one on a
stub from before 0.18 — is left alone rather than failing the delivery.

### Watching a booking happen

`BookingRunner` announces every attempt, so an activity log or an audit trail is
written once instead of at each call site:

| Event | When |
|---|---|
| `DocumentBooked` | the document reached the bookkeeping |
| `DocumentBookingFailed` | every other outcome — refused, unauthorised, missing, upstream error, undecided |

Both carry `module`, `id`, the `BookingOutcome`, and whatever your
`ResolvesBookableDocument` hung on the `BookableDocument` as `subject`:

```php
return new BookableDocument($this->mapper->toDocument($invoice), subject: $invoice);
```

Without it a listener has to find and authorise the record a second time just to
name it. `DocumentBookingFailed` covers outcomes that are not alike — read
`$outcome->status` and `$outcome->mayRetry()` rather than treating a 422 like a
503. `check()` announces nothing: it books no document.

### Configuration

```env
# Connection holding hub_documents. Empty = your default connection.
EMEQ_HUB_BOOKING_CONNECTION=tenant
# Cache store serializing attempts on one document. Empty = default (must lock).
EMEQ_HUB_BOOKING_LOCK_STORE=redis
EMEQ_HUB_BOOKING_LOCK_SECONDS=40
EMEQ_HUB_BOOKING_BATCH_SECONDS=60
EMEQ_HUB_BOOKING_PAGE_LENGTH=25
```

`EMEQ_HUB_BOOKING_LOCK_SECONDS` must exceed `EMEQ_HUB_TIMEOUT`, and booking
refuses to run when it does not: a lock that expires while the send is still in
flight lets a second attempt start alongside the first. Leave room for
attachment rendering on top of the timeout.

Publish `hub-migrations` and migrate `hub_documents` onto the database that
holds the documents it tracks — the backlog joins the two, so they cannot live
on separate connections. A ledger on the wrong connection reads as "not booked
yet", and the next run posts a duplicate into a real administration. Every
backlog query runs on `hub.booking.connection`, not on your default connection,
so the source builders `ProvidesBacklogSources` hands back have to be readable
from there.

**Roll the ledger migrations out to every tenant before deploying.** The package
asks each ledger database once whether it carries the optional trace and
accounting-change columns, and caches that answer per connection *and* database
name. A worker that serves several tenant databases through one connection name
therefore answers correctly per tenant — but a tenant still missing a migration
silently books without a trace until you run it.

### Tracing a failure back to Hub

A failed row carries `request_id` and `category`: the value Hub logged the
request under, and the provider-independent class of failure. Quote the
`request_id` in a support question and the Hub side of the story is one lookup
instead of a search by timestamp.

`add_trace_to_hub_documents_table` adds both to a ledger created before 0.20.0;
a fresh `create_hub_documents_table` already has them, and running both is safe.
The migration is optional — without it booking works exactly as before and no
trace is stored.

The same values reach your log without any of that: `HubException::context()` is
picked up by Laravel's exception handler, so every reported Hub failure carries
`hub_request_id` on its own.

### Who writes the message a user sees

Hub does, for anything Hub answered. Its messages name the relation or the
ledger account that is missing and say what to do about it — `"Grootboek-code
'8000' niet in de mirror — draai POST /v1/accounting/sync."` — which no line
shipped here could. `BookingOutcome` passes them through unchanged, so a new
error code reads correctly the day Hub deploys it: no SDK release, nothing for
you to update.

This package only writes copy for outcomes it decides alone — the bookkeeping
was unreachable, a booking is already running, the attachment failed to render.
Those ship in `en` and `nl`; `php artisan vendor:publish --tag=hub-translations`
to reword them.

Publishing an `error.<code>` key takes a code back: where the key exists it wins
over Hub's message. Useful to soften one specific message for your users; do it
per code, not wholesale, or you freeze copy that Hub keeps improving.

## Usage

```php
use Emeq\HubSdk\Facades\Hub;

$providers = Hub::integrations()->list(); // data-driven
$init = Hub::oauth()->init($providers[0]['key'], returnUrl: $url);

Hub::accounts()->create('tenant-1', 'Acme B.V.'); // treat 409 as "already exists"
Hub::connections()->delete($connectionId);

// Canonical accounting — Hub picks the partner adapter.
// Idempotency-Key is required on create (passed as $idempotencyKey). Derive it
// from the document's external_id so a retry presents the same key; a fresh
// uuid per call books the document twice.
Hub::accounting()->validateDocument($payload);
Hub::accounting()->createDocument($payload, idempotencyKey: $payload['external_id']);
Hub::accounting()->capabilities();

// Collection reads are cursor-paginated and return an AccountingPage.
$page = Hub::accounting()->documents(['type' => 'sales_invoice']);

foreach ($page->items as $document) {
    // …
}

while ($page->hasMore()) {
    $page = Hub::accounting()->documents([
        'type' => 'sales_invoice',
        'cursor' => $page->nextCursor,
    ]);
}
```

## API surface

Prefer these from app code: `Facades\Hub`, `Contracts\*`, `Resources\*`,
`Booking\*`, `Backlog\*`, `Webhooks\*`, `Events\*`, and `Testing\*` in your test
suite. `Http\*` is package-internal (BFF / Saloon).

| SDK | Hub |
|---|---|
| `Hub::accounts()->create(...)` | `POST /v1/accounts` |
| `Hub::integrations()->list(...)` | `GET /v1/integrations` |
| `Hub::connectSessions()->create(...)` | `POST /v1/connect-sessions` |
| `Hub::oauth()->init($provider, ...)` | `POST /v1/oauth/{provider}/init` |
| `Hub::connections()->create($accountId, $provider)` | `POST /v1/connections` |
| `Hub::connections()->get($id)` | `GET /v1/connections/{id}` |
| `Hub::connections()->delete($id)` | `DELETE /v1/connections/{id}` |
| `Hub::accounting()->validateDocument(...)` | `POST /v1/accounting/documents/validate` |
| `Hub::accounting()->createDocument(..., $idempotencyKey)` | `POST /v1/accounting/documents` |
| `Hub::accounting()->deleteDocument($externalId, $type)` | `DELETE /v1/accounting/documents/{external_id}` (`accounting:write`) |
| `Hub::accounting()->documents(...)` | `GET /v1/accounting/documents` |
| `Hub::accounting()->bankStatements(...)` | `GET /v1/accounting/bank-statements` |
| `Hub::accounting()->ledgerAccounts(...)` | `GET /v1/accounting/ledger-accounts` |
| `Hub::accounting()->taxCodes(...)` | `GET /v1/accounting/tax-codes` |
| `Hub::accounting()->customers(...)` | `GET /v1/accounting/customers` |
| `Hub::accounting()->suppliers(...)` | `GET /v1/accounting/suppliers` |
| `Hub::accounting()->capabilities()` | `GET /v1/accounting/capabilities` |
| `Hub::accounting()->referenceData()` | `GET /v1/accounting/reference-data` |
| `Hub::accounting()->mapping()` / `updateMapping(...)` | `GET` / `PUT /v1/accounting/mapping` |
| `Hub::accounting()->sync(...)` | `POST /v1/accounting/sync` |
| `Hub::itheorie()->courses($page, $limit)` | `GET /v1/itheorie/courses` |
| `Hub::itheorie()->course($id)` | `GET /v1/itheorie/courses/{course}` |
| `Hub::itheorie()->createPurchase($payload, $idempotencyKey)` | `POST /v1/itheorie/purchases` |
| `Hub::itheorie()->purchase($id)` | `GET /v1/itheorie/purchases/{purchase}` |
| `Hub::itheorie()->student($accessCode)` | `GET /v1/itheorie/students/{accessCode}` |
| `Hub::itheorie()->studentDetailed($accessCode)` | `GET /v1/itheorie/students/{accessCode}/detailed` |
| `Hub::extraction()->putProfileVersion($key, $version, $schema, $instructions)` | `PUT /v1/extraction/profiles/{key}/versions/{version}` |
| `Hub::extraction()->runText($profile, $version, $text, $idempotencyKey)` | `POST /v1/extraction/runs` |
| `Hub::extraction()->runFile($profile, $version, $contents, $filename, $idempotencyKey)` | `POST /v1/extraction/runs` (multipart) |
| `Hub::extraction()->getRun($runId)` | `GET /v1/extraction/runs/{run}` |

`$provider` is a free string (Hub discovery `key`) — no SDK allowlist.
Account context uses `X-Account-Id` / `account_external_id` from
`ResolvesAccountId` or an explicit argument.

`itheorie()` is the exception to that last line: it sends no account context at
all. Hub buys theory access codes under one reseller agreement of its own, so
there is no connection to scope a call to, and binding `ResolvesAccountId`
changes nothing about these six calls.

Two methods a reader may look for and not find. There is no `purchases()` list:
Hub deliberately exposes no list endpoint, because one reseller serves every
consumer and an unfiltered list would hand out other consumers' names, e-mail
addresses, phone numbers and auto-login links. Read your own purchases from your
own records. There is no `token()` either — Hub holds that credential now, which
is the whole point of routing through it.

`courses()` returns Hub's `{"links": ..., "data": [...]}` envelope rather than a
bare list, so paging information survives the call.

### Document extraction

Hub reads data out of a document (a timesheet, say) according to a profile your
app supplies: a JSON Schema (draft 2020-12) of the output you want, plus
optional `instructions` in plain text. Hub knows nothing about timesheets or
invoices, so checking the content is your app's job.

Extraction needs no credentials from the customer. Switch it on per Account
with a plain connection; a second active one answers `409 connection_exists`:

```php
Hub::connections()->create($accountId, 'extraction');
```

A profile version belongs to your Consumer, not to an Account, so it sends no
account header. A version is immutable: change the schema or instructions under
a new version number. Sending the same version again is safe and answers `200`
instead of `201`; a different schema under an existing version answers `409
profile_version_conflict`.

```php
Hub::extraction()->putProfileVersion('timesheet', 1, [
    'type' => 'object',
    'properties' => [
        'employee' => ['type' => 'string'],
        'days' => ['type' => 'array', 'items' => ['type' => ['string', 'null']]],
    ],
    'required' => ['employee'],
], instructions: 'Dagcodes: ziek, vrij, A, AP.');
```

Allow `null` on every field that can be blank or unreadable on paper. Without
it the model guesses a value, or the run fails on schema validation.

Runs and the webhooks are Account-scoped and send `X-Account-Id`, from
`ResolvesAccountId` or an explicit `accountId:` argument.

A run answers `202` with `{run_id, status}` straight away. The SDK never
invents an `Idempotency-Key`; derive it from something stable on your side, so
a retry returns the same `run_id` instead of starting a second run:

```php
$run = Hub::extraction()->runText('timesheet', 1, $pastedText, idempotencyKey: "timesheet-{$upload->id}");

$run = Hub::extraction()->runFile(
    'timesheet',
    1,
    file_get_contents($path),
    'weekstaat.pdf',
    idempotencyKey: "timesheet-{$upload->id}",
);
```

`runFile()` accepts PDF, JPEG, PNG, XLSX and CSV, up to 10 MB and 5 PDF pages.
Hub decides the type from the content, not the extension.

Fetch a run with `getRun()`. It always returns `run_id`, `status` (`queued`,
`completed` or `failed`), `failure_reason`, `profile`, `version`, `readers` and
`billable_pages`. For 24 hours after a run completes it also carries `result`;
after that only status and metadata remain. A PDF, JPEG or PNG is read twice by
separate models and adds `pages` and `signals`: `differs` (`{pointer,
alternatives}` per cell where the readings disagree), `rows_only_in_second_read`,
and `review` (JSON Pointers into `result` that a person should check).
`readers` and `billable_pages` are `null` while the status is `queued`. A run of
another Account answers `404 run_not_found`. An existing run answers `404
connection_not_found` once the Account's extraction Connection is revoked. Both
throw `NotFoundException`; check `$e->error` to tell them apart. Fetching costs
nothing. If you missed a webhook, fetch the
run instead of starting a new one under a new key, which is a second paid run:

```php
$run = Hub::extraction()->getRun($runId);
```

Hub sends a webhook when a run ends. It only reports that the run is done; fetch
the result with `getRun()` within 24 hours. Claim both events in your job:

```php
use Emeq\HubSdk\Webhooks\HubWebhookEnvelope;
use Emeq\HubSdk\Webhooks\HubWebhookEvent;
use Emeq\HubSdk\Webhooks\ProcessHubWebhookJob;

class ProcessHubWebhook extends ProcessHubWebhookJob
{
    protected function handles(): array
    {
        return [
            HubWebhookEvent::EXTRACTION_RUN_COMPLETED,
            HubWebhookEvent::EXTRACTION_RUN_FAILED,
        ];
    }

    protected function onEvent(HubWebhookEnvelope $envelope, ?string $eventId, ?string $requestId): void
    {
        // completed: Hub::extraction()->getRun($envelope->data['run_id'], $envelope->accountId)
        // failed: $envelope->data['reason']
    }
}
```

`extraction.run.completed` carries `run_id`, `profile`, `version`, `readers`
and `billable_pages`, never the result. `extraction.run.failed` carries `run_id`,
`profile`, `version` and `reason`. Treat an unknown `reason` as a plain
failure; the list can grow. After a Hub worker restart a run's webhook can
arrive twice with the same event id, which the package's deduplication already
drops.

Every request carries `User-Agent: emeq-hub-sdk/{version} php/{version}
laravel/{version}` and `X-Emeq-Sdk-Version`, so Hub can tell which consumers run
which release. `Support\SdkIdentity::version()` reads the same value from
Composer's installed manifest.

`HubConnector` reads `hub.base_url`, `hub.pat` and `hub.timeout` when it sends,
not when the container built it. Config swapped per tenant at runtime therefore
takes effect on the next call — the connector stays a singleton, so a mock
client registered on it still intercepts everything.

## Errors

Failed Hub responses throw `Emeq\HubSdk\Exceptions\HubException` (or a subclass).
Envelope fields map to public properties: `error`, `category`, `requestId`, `status`.

| HTTP / category | Exception |
|---|---|
| 401 / `AUTHENTICATION_ERROR` | `AuthenticationException` |
| 403 / `AUTHORIZATION_ERROR` | `AuthorizationException` |
| 404 | `NotFoundException` |
| 422 / `VALIDATION_ERROR` | `ValidationException` |
| 429 | `RateLimitException` |
| 409 `purchase_in_flight` | `PurchaseInFlight` |
| 409 `document_delete_rejected` | `DocumentDeleteRejected` (`upstreamError`, `upstreamStatus`) |
| ≥ 500 | `ServerException` |
| Missing `EMEQ_HUB_*`, unresolvable account id, bad `return_path`, non-lockable cache store | `MissingConfigurationException` (503) |

Every SDK failure is a `HubException` — configuration mistakes included, so
`catch (HubException $e)` around SDK calls is sufficient.

Log `requestId` when present; it matches Hub `X-Request-Id` / envelope `request_id`.

`RateLimitException::$retryAfter` carries Hub's `Retry-After` in seconds, whether
Hub sent a number of seconds or an HTTP date. A date already in the past reads as
`0`, never as a negative wait.

### A purchase that costs money

`createPurchase()` buys a theory access code. It is billed and cannot be undone,
so the rules around it are stricter than anywhere else in this SDK.

The SDK never invents an `Idempotency-Key`. You pass one, and you derive it from
something stable on your side — an order id, not a timestamp. The same key always
returns the first purchase and never buys a second code, days later included.

`PurchaseInFlight` (409) means an earlier attempt with that key died without a
known outcome. **Do not retry it.** A code may already have been bought and
charged; someone has to look. It carries `retryable: false` and is deliberately
not a `ServerException`, so a generic retry policy leaves it alone.

A `ServerException` (502/504) is the opposite: the outcome is unknown, but
re-sending *the same key* is safe. It answers `PurchaseInFlight` instead of
buying twice. Sending a **fresh** key after such a failure is what buys a second
code, so never generate a new one on retry.

### What the BFF routes answer

The opt-in routes never pass a Hub status code through to the browser. A Hub 401
means *your PAT* was rejected, not that the signed-in user lost their session —
answering 401 there would make a client log its user out over a server-side
credential problem.

| Hub answered | Route answers |
|---|---|
| 429 | `503` + `Retry-After` |
| anything else that failed | `502` |
| local configuration error | `503` |

The body keeps `message`, `error` and `request_id`, and adds `hub_status` with
what Hub actually said, so the original status stays available for debugging
without steering the client's auth handling.

## Pitfalls

- **PAT in the browser** — always call Hub from your Laravel backend via this SDK.
- **Account id from the client** — never trust `X-Account-Id` / `account_external_id` from the request; derive via `ResolvesAccountId`.
- **`Hub::connections()` is PAT-scoped, not account-scoped** — Hub resolves `/v1/connections/{id}` against the Consumer behind your token and ignores account context, so `get()` / `delete()` reach every connection of every account you own. Verify ownership yourself; never forward a connection id straight from a request.
- **Connection ids are the `con_…` public id** — the value `integrations()->list()`, `oauth()->init()` and the `connection_revoked` webhook hand back. Hub's numeric key is internal; do not store it. (Hub only started accepting the public id here in August 2026 — older Hub deployments return a 500.)
- **`documents()` needs a `type`** — Hub rejects the collection read without it (`400 invalid_query`, category `VALIDATION_ERROR`, so the SDK raises `ValidationException`). Valid: `sales_invoice`, `purchase_invoice`, `income`, `expense`, `credit_note`.
- **Hardcoded providers** — render what `integrations()->list()` returns; no `if ($provider === 'exact')`.
- **Partner SDKs in the consumer** — do not require `emeq/exact-api` here; those are Hub-internal.
- **`return_url`** — snake_case on the wire; build the URL server-side from your host (open-redirect guard on the Hub).
- **Idempotency** — `createDocument` requires a key that survives a retry. Stable means *same document, same key*, not one key per process: `external_id` is the canonical document key and the intended source. A fresh `Str::uuid()` per call cancels the header out — the retry after a timeout gets a new key and books the same document a second time.
- **A validation finding's `blocking` field is optional — absent is not `false`.** Some `warning`-severity findings reject the booking anyway (Hub says so in their own `message`); `blocking` is the field meant to replace parsing that text. Read it via `Emeq\HubSdk\Resources\Finding::isBlocking($finding)`, which answers `null` — never a guessed `false` — when the key is missing (an undeployed or older Hub) or not a boolean. Never derive it from `severity` yourself.

## Testing your integration

`HubConnector` is a container singleton and a plain `Saloon\Http\Connector`, so
Saloon's global mock client intercepts every SDK call:

```php
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::global([
        '*/v1/integrations' => MockResponse::make([['key' => 'exact']], 200),
        '*/v1/accounting/documents' => MockResponse::make(['id' => 'doc_1'], 201),
    ]);
});

// global() is `??=` — a leaked client silently ignores the next test's mock data.
afterEach(fn () => MockClient::destroyGlobal());
```

- `Saloon::fake()` does **not** exist here — this package requires
  `saloonphp/saloon` only, not `saloonphp/laravel-plugin`.
- Key mocks on the URL, not on `Http\Request\*` classes: that namespace is
  package-internal.

### Canonical fixtures

Inventing accounting payloads makes a test green against a shape Hub never
sends. `Emeq\HubSdk\Testing\HubMock` ships responses captured from a live Hub
against a connected provider, redacted — the SDK's own tests read the same
files:

```php
use Emeq\HubSdk\Testing\HubMock;

MockClient::global(HubMock::accounting()); // every read plus sync, at once

// Or one endpoint, mixed with your own mocks:
MockClient::global([
    '*/v1/accounting/mapping' => HubMock::mapping(),
    '*/v1/accounting/documents/validate' => HubMock::validateDocument(valid: false),
    '*/v1/accounting/capabilities' => HubMock::unauthenticated(),
]);

// Booking shares its URL with the list read, so key it explicitly:
MockClient::global(['*/v1/accounting/documents' => HubMock::createDocument()]);

// The raw payload, to assert against or to build a variant from:
$mapping = HubMock::fixture('mapping')['mapping'];
```

iTheorie has its own map:

```php
MockClient::global(HubMock::itheorie()); // all five reads at once

// The conflict a purchase flow has to handle, on its own:
MockClient::global(['*/v1/itheorie/purchases*' => HubMock::itheoriePurchaseInFlight()]);
```

These five come from Hub's request stack driven with synthetic input rather
than from a live purchase: buying one costs money, and a real response carries
personal data and an auto-login link. Two shapes they settle, both easy to
hand-write wrong:

- A course `offer` carries bare floats — `"current_price": 9.7` — while a
  purchase `price` keeps the partner's object with a **string** amount:
  `{"amount": "9.70", "currency": "EUR"}`. They are not the same money shape.
- `access_code` and `expires_at` can both be `null` on a `200`. Render "valid
  until" only when it is filled.

`student()` and `studentDetailed()` answer the same shape, so one fixture serves
both; the detailed call only fills `progression` where the plain one leaves it
`null`.

What the captures show, and what a hand-written mock tends to get wrong:

- `validateDocument()` answers `200` either way — read `valid`, never the HTTP
  status. A clean document still returns findings: a matched relation comes back
  as `info`, so `findings === []` is not the success test.
- **Severity does not decide whether a finding refuses the booking; `blocking`
  does.** The captured `validate-findings.json` holds an `exact.vat_code.unmapped`
  of severity `warning` that blocks, next to an `exact.relation.new` of severity
  `info` that does not — the relation is created during the booking. `summary`
  counts them separately (`errors`, `warnings`, `infos`, `blocking`).
- `referenceData()` is grouped by kind — `{gl: [...], vat: [...], journal: [...]}`.
  Items carry no `kind` of their own, and `attrs` is `[]` when empty but an
  object when filled.
- `mapping()` is wrapped in `{mapping: …}`, and `vat_codes` holds composite keys
  like `reverse_charge:21` next to plain rates.
- A document read is a thin projection of the provider's record: `issue_date`,
  `party.name` and `lines` can all come back empty.
- `createDocument()` answers `201` with the provider's own identifiers —
  `external_ref` and `external_number`. Store them; they are how the booking is
  found back in the administration. A retry with the same `Idempotency-Key`
  returns a byte-identical body, so the document is booked once.
- `POST` and `GET` on `/accounting/documents` share a URL, and Saloon matches
  mocks on the URL alone. `HubMock::accounting()` maps it to the list read, so a
  test that books has to key that pattern itself.

Fixtures are a snapshot, not a contract — they go stale when Hub changes.
`tools/capture-fixtures.php` in this repository re-captures them; its write
cases sit behind `--allow-write` because they book for real.

**Re-capture before tagging a release.** Stale fixtures fail quietly: every test
stays green against a Hub that has moved on. That is how `sync()` shipped having
never worked — it surfaced only once real responses were captured. Alongside it,
refresh the route coverage in
[`docs/hub-api-coverage.md`](docs/hub-api-coverage.md) § Refreshing it.

Extraction has its own map too, with the connection create included:

```php
MockClient::global(HubMock::extraction());
```

### Testing inbound webhooks

`Emeq\HubSdk\Testing\FakeHubWebhook` builds a signed envelope, so a consumer
test no longer hand-rolls `hash_hmac('sha256', $body, $secret)` next to an
invented payload array:

```php
use Emeq\HubSdk\Testing\FakeHubWebhook;

$fake = FakeHubWebhook::salesInvoiceChanged(accountId: '47');

$this->postJson(
    '/webhooks/emeq-hub',
    json_decode($fake->body(), true),
    $fake->headers(config('hub.webhook.secret')),
)->assertOk();
```

`event()` builds any canonical event; `connectionRevoked()` and
`salesInvoiceChanged()` are canned shortcuts for the two most-used ones.
`extractionRunCompleted()` and `extractionRunFailed()` build the two extraction
webhooks with the `data` shapes from Hub's contract.
`body()` is the exact raw JSON that gets signed — decode it yourself rather
than re-encoding, or the signature in `headers()` will not match what you
post. `data` on the canned factories is illustrative: this package does not
parse it, and Hub's real payload there is the provider's own webhook body,
passed through unchanged.

## AI prompt — wire this SDK into your Laravel app

A ready-to-paste prompt for your coding agent (Cursor, Claude Code, …) that
installs and configures the SDK against your tenant model:
[`docs/agent-prompt.md`](docs/agent-prompt.md).

## Growth model

| Change | Where |
|---|---|
| New partner | Hub only — SDK unchanged |
| New canonical `/v1` endpoint | This SDK (+ tag) |
| Partner HTTP/auth/DTOs | `emeq/<partner>-api` (Hub-internal) |

## Further reading

- [Hub API coverage](docs/hub-api-coverage.md) — which `/v1/*` endpoints this SDK
  wraps and which are still backlog, grouped by area
- [Architecture boundaries](https://github.com/yusufkaracaburun/emeq-hub/blob/master/docs/architecture-boundaries.md) — who owns what across Hub, this SDK and
  your app, and the test that decides it: who has to act when this changes?
- [Consumer onboarding](https://github.com/yusufkaracaburun/emeq-hub/blob/master/docs/consumer-onboarding.md) — Hub admin + consumer invariants (B1–B4)
- [Consumer integration guide](https://github.com/yusufkaracaburun/emeq-hub/blob/master/docs/consumer-integration-guide.md) — flows, payloads, accounting, webhooks, agent prompts
- Hub OpenAPI UI: `{EMEQ_HUB_BASE}/docs/api` — same spec committed as
  [`api.json`](https://github.com/yusufkaracaburun/emeq-hub/blob/master/api.json),
  so contract changes are visible as a diff. Request bodies are derived from Hub
  form requests and are reliable; response schemas are still thin for endpoints
  that proxy a provider body.

Contributing to this package: [`CONTEXT.md`](CONTEXT.md) for the domain language
and structural rules, [`docs/adr/`](docs/adr/) for the decisions behind them.

## Requirements

- PHP 8.3+
- Laravel 13

## Local development

```bash
composer install
composer test        # Pest on Orchestra Testbench
composer analyse     # Larastan
composer format      # Pint
```

Laravel Boost wiring, the `artisan` shim and the symlinked agent artefacts:
[`docs/local-development.md`](docs/local-development.md).
