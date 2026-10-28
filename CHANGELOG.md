# Changelog

## [0.33.0] — 2026-10-28

### Added

- **`BookingOutcome::rateLimited()` and `$outcome->rateLimited`.** A `429` from
  Hub on `createDocument()` now gives this outcome instead of `unavailable()`.
  It keeps status `503`, so `mayRetry()` stays true, and `retryAfter` is
  always set: Hub's `Retry-After`, or 60 seconds without one.
- **`RateLimitException::retryAfterSeconds()`** returns `$retryAfter`, or 60
  when Hub sent no readable `Retry-After`. `$retryAfter` itself is unchanged.

### Changed

- **`BookingRunner::book()` stops at the first rate limit.** Before, every
  document after a `429` was still sent and failed the same way. Now that
  document and every one after it come back as `rateLimited()` with the same
  wait, and the later ones are not sent to Hub. Results for documents before
  it are unchanged. The rate-limited document is stored as
  `STATUS_UNAVAILABLE`; the later ones are not checked, get no ledger row and
  still fire `DocumentBookingFailed` with the `rateLimited()` outcome, so a
  batch keeps firing one event per requested document. A `5xx` does not stop
  the batch. A rate-limited
  outcome carries the new `rate_limited` line (`hub::booking.rate_limited`)
  as its message instead of `temporarily_unavailable`. The line names no
  duration, because `Retry-After` can be hours; build the wait from
  `retryAfter`.

## [0.32.0] — 2026-10-16

### Removed

- **BREAKING: `Hub::extraction()->putHints()`, `PutHintsRequest` and
  `HubMock::extractionHints()`**, with the `extraction-hints` fixture.
  `HubMock::extraction()` no longer maps `*/v1/extraction/hints`. Hub drops
  `PUT /v1/extraction/hints`: hints had no measured effect on extraction.
  Remove every call; profile `instructions` remain the way to guide a run.

## [0.31.1] — 2026-10-16

### Deprecated

- **`Hub::extraction()->putHints()` and `PutHintsRequest`.** Hints have no
  measured effect on extraction and will be removed in a later release. The
  call still works unchanged; `HubMock::extractionHints()` stays for now.

## [0.31.0] — 2026-10-13

### Added

- **`Hub::extraction()->getRun($runId)`** wraps `GET /v1/extraction/runs/{run}`
  and sends `X-Account-Id` like the other run calls. It returns the decoded body:
  `run_id`, `status`, `failure_reason`, `profile`, `version`, `readers`,
  `billable_pages`, and for 24 hours after completion the result fields
  (`result`, plus `signals` and `pages` for a PDF, JPEG or PNG). `404
  run_not_found` throws `NotFoundException`. `HubMock::extraction()` now also
  answers it, and `extractionRunShow()` serves the `extraction-run-show` fixture.

### Changed

- **Webhook payload contract: `extraction.run.completed` no longer carries the
  result** (since Hub #162). `data` is `{run_id, profile, version, readers,
  billable_pages}`; fetch `result`, `pages` and `signals` with `getRun()` within
  24 hours. Hub already sends this shape, so only the fake lagged behind:
  `FakeHubWebhook::extractionRunCompleted()` now builds the same shape and
  drops `result`, `signals` and `pages` (test helper, can break consumer tests
  that read `data['result']`). `extraction.run.failed` was already
  `{run_id, profile, version, reason}` and is unchanged.

## [0.30.0] — 2026-10-09

### Added

- **`Hub::extraction()`, document extraction against your own profiles.** Four
  methods: `putProfileVersion()` (`PUT /v1/extraction/profiles/{key}/versions/{n}`),
  `putHints()` (`PUT /v1/extraction/hints`), `runText()` and `runFile()` (both
  `POST /v1/extraction/runs`). A profile version is Consumer-scoped and sends no
  account header; hints and runs are Account-scoped and send `X-Account-Id`
  from `ResolvesAccountId` or an explicit `$accountId`, like `accounting()`.
  `putProfileVersion()` returns the body on both `201` (created) and `200`
  (already there, unchanged). Runs answer `202` with `{run_id, status}`; the
  result only arrives by webhook. The SDK never invents an `Idempotency-Key`.
  `runFile()` sends `multipart/form-data` with `profile`, `version` and `file`,
  and takes the file as a string of bytes plus a filename.

- **`Connections::create($accountId, $provider)`** wraps `POST /v1/connections`.
  It is how a provider without OAuth, such as `extraction`, is switched on for
  an Account. The provider is a free string.

- **`HubWebhookEvent::EXTRACTION_RUN_COMPLETED` and `EXTRACTION_RUN_FAILED`**
  (`extraction.run.completed`, `extraction.run.failed`). Before this release
  both decoded as `UNMAPPED`. Claim them in `handles()`; there is no dedicated
  event class.

- **Test support.** `HubMock::extraction()` maps the connection create and all
  four extraction calls, with `extractionProfileVersion()`, `extractionHints()`,
  `extractionRun()` and `connection()` for single endpoints.
  `FakeHubWebhook::extractionRunCompleted()` and `extractionRunFailed()` build
  signed envelopes with the contract's `data` shapes.

## [0.29.0] — 2026-09-30

### Added

- **Connect sessions carry categories, mode and actor.** `ConnectSessions::create()`
  takes optional `categories`, `mode` (`manage` / `view`) and `actor`, sent to
  `POST /v1/connect-sessions` only when given. A new optional contract,
  `ResolvesConnectSessionContext::context(?Authenticatable $user): array`, feeds
  them to the `connect-session` route for the logged-in user; an exception it
  throws (e.g. `abort(403)`) propagates, so Laravel answers 403. Unbound, the
  route behaves as before.

## [0.28.2] — 2026-09-30

### Changed

- **`mapping_failed` is a rejection.** `DocumentBooker` records a document the
  Hub cannot map as `rejected` with its code instead of `failed`, and no longer
  reports it: a missing ledger mapping is a setup gap, not an application error.

## [0.28.1] — 2026-09-15

### Fixed

- **`registerHubWebhookClientConfig()` no longer logs on every request.** Spatie's
  webhook-client ships a scaffold `default` config entry with an empty
  `process_webhook_job`; the package correctly drops it to avoid an `InvalidConfig`
  crash, but logged that drop at `info` level on every incoming webhook, forever,
  in any consumer that never fills in the placeholder — pure noise with no
  action attached. The known placeholder is no longer logged; a genuinely
  unexpected unprocessable entry (any other name) still logs, now at `warning`.

## [0.28.0] — 2026-09-06

### Added

- **`Hub::itheorie()`, the first resource that carries no account context.** Hub
  buys theory access codes under one reseller agreement of its own, so there is
  no connection to scope a call to and no `X-Account-Id` to send. Every other
  resource in this package takes `HasAccountIdHeader`; these six requests
  deliberately do not, and a test proves the header stays absent even when the
  consumer binds a `ResolvesAccountId`. That test is the point: `emeq/system`
  always binds one, so without it a keyless route would ride along on a valid
  account context in every suite that could catch the mistake.

  Six methods: `courses()`, `course()`, `createPurchase()`, `purchase()`,
  `student()` and `studentDetailed()`. `courses()` returns Hub's
  `{"links": …, "data": […]}` envelope rather than a bare list, so paging
  information survives the call.

  Two methods are absent on purpose. There is no `purchases()` list — Hub
  exposes no list endpoint, because one reseller serves every consumer and an
  unfiltered list would hand out other consumers' names, e-mail addresses, phone
  numbers and auto-login links. And there is no `token()`: Hub holds that
  credential now, which is why callers route through it at all.

- **`PurchaseInFlight`, so an irreversible purchase is never retried by
  reflex.** `createPurchase()` costs money and cannot be undone. A `409
  purchase_in_flight` means an earlier attempt with that idempotency key died
  without a known outcome — a code may already have been bought and charged.
  Until now that answer arrived as a bare `HubException` and was only
  recognisable by comparing `$e->error` to a string, which is thin protection on
  a billed path.

  The new arm in `HubException::fromEnvelope()` matches on status **and** error
  together. `match (true)` treats comma-separated conditions as alternatives, so
  a comma there would have turned every 409 into a `PurchaseInFlight`, including
  the `idempotency_request_in_progress` and `document_sync_in_progress` that
  `DocumentBooker` handles as transient. A test asserts that other conflicts
  still arrive as a plain `HubException`.

  It extends `HubException`, not `RateLimitException` or `ServerException`.
  Those two are caught first in `DocumentBooker::attemptBooking()`, which writes
  `STATUS_UNAVAILABLE` and drives a consumer's backlog list — an iTheorie
  conflict has no business leaving documents hanging there. A case in
  `DocumentBookerTest` fails the moment that inheritance moves.

- **`HubMock::itheorie()` and five fixtures.** Not captured from a live purchase
  but from Hub's own request stack driven with synthetic input — real
  controllers, real normalisation, real envelope, invented payload. Buying a
  code costs €9.70 and cannot be undone, and a real purchase response holds a
  name, an e-mail address, a phone number and a `direct_login_url` that is
  bearer-equivalent, so capturing one would commit a live credential to satisfy
  a rule about accuracy. `CONTEXT.md` now records this route as the sanctioned
  one whenever a response carries personal data or a credential.

  They still settle two shapes a hand-written mock gets wrong
  — a course `offer` holds bare floats (`"current_price": 9.7`) while a purchase
  `price` keeps the partner's object with a *string* amount
  (`{"amount": "9.70"}`), and both `access_code` and `expires_at` can be `null`
  on a `200`.

  `student()` and `studentDetailed()` answer the same shape, so one fixture
  serves both.

### Not added, on purpose

- No retry helper around `createPurchase()`. Re-sending the *same* key after a
  502 or 504 is safe and answers `PurchaseInFlight`; generating a *fresh* key is
  what buys a second code. That difference belongs in the caller's own code,
  where the order id lives, not behind a convenience method in this package.

## [0.27.0] — 2026-09-03

### Added

- **`HubDocument::STATUS_UNAVAILABLE`.** A rate limit or a 5xx from Hub left no
  row behind — `DocumentBooker::attemptBooking()` threw
  `BookingTemporarilyUnavailable` without calling `store()`, on the assumption
  that "nothing was decided" meant nothing worth keeping. In practice a
  throttled batch run (Hub's rate-gate firing under burst load) left documents
  indistinguishable from ones nobody had ever tried to book: same row, same
  absence of a row. Nothing surfaced the difference until someone re-ran the
  batch by hand.

  `attemptBooking()` now stores the attempt with `STATUS_UNAVAILABLE` — Hub's
  own `error`, `category` and `request_id` carried through, same as the
  `HubException` branch beside it — before throwing. `BacklogStatus::all()`
  includes it, so a consumer's backlog list and summary counts pick it up for
  free through the existing status-driven filtering.

## [0.26.0] — 2026-08-18

### Fixed

- **A document the bookkeeping deleted can be booked again.**
  `DocumentBooker::attemptBooking()` returned any row that already read
  `posted` without contacting Hub. Since 0.25.0 the backlog hands a deleted
  document back on purpose, so the consumer offered a Book action that reported
  success and carried the *first* booking's `external_number` — nothing reached
  the bookkeeping and the row kept its `accounting_change_action = deleted`.

  The short circuit now asks `HubDocument::wasDeletedFromAccounting()`. A
  document the bookkeeping only edited is still never resent.

- **A re-sent document travels under a key of its own.** The
  `Idempotency-Key` was the document's `external_id`, so a second send within
  Hub's retention window replayed the first answer instead of reaching
  `AccountingSyncRunner`, which has handled a vanished upstream entity since it
  learned to probe. After a deletion the key becomes
  `{external_id}:{accounting_change_event_id}` — derived from the deletion, so a
  retry of the same re-send still carries the key the first attempt used, and
  falls back to the deletion's timestamp when Hub sent no event id.

- **A fresh posting wipes what the bookkeeping did.** `accounting_changed_at`,
  `accounting_change_action` and `accounting_change_event_id` are cleared when a
  document reaches `posted`, in `store()` and in `mergeWithWinner()`. Without
  this the row stayed marked as deleted after a successful re-book, so it kept
  its badge and stayed in the `accounting_changed` tab. Left out on a schema
  that never learned the columns.

## [0.25.1] — 2026-08-18

### Fixed

- **The backlog reads a schema without the change columns again.** 0.25.0
  guarded `PostedDocuments::excluding()` against a consumer that never ran the
  `accounting_change_*` migration, but three reads in `BacklogRepository` still
  named those columns unconditionally: the `summary()` count, the `select` and
  `accounting_changed` filter in `filtered()`, and `latestBookings()`. A
  consumer on the older schema got `no such column` off the backlog rather than
  a list.

  All three now ask `AccountingChangeRecorder::marksChanges()`, the same check
  the recorder and `excluding()` use. Without the columns the backlog lists as
  it always did, the `accounting_changed` filter returns nothing and its summary
  counts zero — what `hub:doctor` already told consumers to expect. With the
  columns present nothing changes.

## [0.25.0] — 2026-08-18

### Fixed

- **A document the bookkeeping only edited stays out of the backlog.**
  `PostedDocuments::excluding()` read any `accounting_changed_at` as a reason to
  hand a posted document back to the backlog as still-to-book. In Exact that
  stamp lands on routine work — matching a bank line against an outstanding
  entry is one — so every booked invoice eventually returned to the list, and
  booking it a second time would write a second entry into the administration.

  Only `accounting_change_action = 'deleted'` now returns a posted document:
  the entry is gone from the bookkeeping, so there is genuinely something left
  to book. An edit stays out of the backlog and shows on the document's own
  badge, which reads `hub_documents` directly and did not change.

  A consumer that hangs a "changed in the bookkeeping" tab off the backlog will
  find it holds deletions only. Rename it, or drop it.

  The rule is skipped where the change columns are missing, through the same
  `AccountingChangeRecorder::marksChanges()` check the recorder already uses, so
  a schema without them keeps excluding on `status` alone.

## [0.24.1] — 2026-08-18

### Fixed

- **The two route actions carry their docblocks again.** `7f7fbc3` stripped
  prose from the package and took `IntegrationController::index()` and
  `connectSession()` with it. Those were not comments for a reader of this
  package: `emeq/system` generates its OpenAPI document from its route handlers,
  these two included, and reads the first docblock line as `summary` and the
  rest as `description`. Both endpoints published with neither until now — a
  loss that only surfaced when that consumer upgraded to 0.24.0 and its
  pre-commit hook regenerated the file.

  The docblock on `connectSessionResponse()` is back for the same reason: it
  records why the response is narrowed to `string|null`, which is what keeps the
  generated schema and the consumer's TypeScript off `any`.

  `.ai/rules/comments.md` now names this carve-out next to the one it already
  made for `config/hub.php` — both are files whose comments are read by someone
  outside this repository.

## [0.24.0] — 2026-08-18

A hardening round. Nothing new to build with — the same calls, closer to what
they already claimed to do. Two findings are multi-database bugs a single-tenant
consumer never meets; the rest closes gaps between what the config file promises
and what the code read.

**Consumers with a front end must read "BFF routes no longer pass Hub's status
code through" below — the only change here that a caller can see.**

### Added

- **`php artisan hub:doctor`.** Reads the configuration this application booted
  with and reports per check: base URL, PAT, timeout, both lock stores and their
  windows, the `ResolvesAccountId` / `ResolvesWebhookAccount` bindings, the
  `hub_documents` table and its optional columns on the connection
  `hub.booking.connection` names, the `webhook-client` entry and its signing
  secret, `webhook_calls`, the BFF middleware stack and the OAuth return path.
  `--ping` also calls Hub with the configured PAT. Exit code is non-zero on a
  failure; warnings pass.

  The lock checks take and release a throwaway lock rather than asking the store
  whether it implements `LockProvider`: Laravel's `database` store answers yes
  and then fails on the first booking when `cache_locks` was never migrated.

  Every production incident this package has had was config-shaped — a ledger on
  the wrong connection, `webhook_calls` missing, a cache store that cannot lock.
  Each was findable before deploying, by a command that did not exist.

- **Every request names the SDK.** `User-Agent: emeq-hub-sdk/{version}
  php/{version} laravel/{version}` plus `X-Emeq-Sdk-Version`. Hub could not
  previously tell which consumer ran which release, which is the measurement any
  future deprecation depends on. `Support\SdkIdentity` exposes the same values.

- **`hub.webhook.lock_seconds`** (`EMEQ_HUB_WEBHOOK_LOCK_SECONDS`, default 30).
  The dedupe lock window was a hardcoded 30 while the booking lock next to it was
  configurable and validated. The published config also now records that
  deduplication reads `webhook_calls` history, so `webhook-client.delete_after_days`
  has to stay above Hub's ~3h retry span — Spatie's default of 30 days already is.

### Fixed

- **The ledger's schema probe froze across tenant databases.** `hub_documents`
  is asked once whether it carries the optional trace and accounting-change
  columns. That answer was cached in a single process-wide flag, so a worker
  serving several tenant databases through one connection name reused the first
  tenant's answer for all of them: a tenant whose migration had not run yet
  would have every booking fail on `request_id`, until the worker restarted.
  The cache is now keyed by connection *and* database name.

- **The backlog read the default connection while the ledger read
  `hub.booking.connection`.** `BacklogRepository` built its outer query on
  `DB::query()` and only the ledger subquery on the configured connection, so
  the moment those differed the join compiled into SQL aimed at the wrong
  database. Every backlog query now runs on the ledger's connection.

- **Two rules decided which ledger row was current.** `forExternalIds()` /
  `forBooking()` preferred a posted row over a newer attempt; the backlog's join
  took `MAX(id)`. One response could therefore list a document as `failed` while
  the record attached to it said `posted`. Both now read
  `HubDocument::currentIds()` — posted first, newest otherwise.

- **`Retry-After` as an HTTP date was discarded.** Only an integer count of
  seconds was parsed; a date-form header left `retryAfter` null and the caller
  guessing. Dates are now read too, and one already in the past reads as `0`
  rather than a negative wait.

- **A wildcard in the backlog's `search_term` was a pattern, not a character.**
  A user typing `%` matched every row and scanned the table. `%` and `_` are now
  escaped with an explicit `ESCAPE` clause.

### Changed

- **BFF routes no longer pass Hub's status code through.** `IntegrationController`
  answered with whatever Hub answered, so a rejected *PAT* reached the browser as
  `401` — which a client reads as "the signed-in user lost their session" and
  acts on by logging them out, over a server-side credential problem it cannot
  fix. A Hub 429 now becomes `503` with `Retry-After`; every other upstream
  failure becomes `502`; locally-raised configuration errors keep their `503`.
  The body keeps `message`, `error` and `request_id` and gains `hub_status`
  carrying what Hub actually said.

  **Migration:** a front end branching on the status of `/integrations` or
  `/integrations/connect-session` reads `hub_status` from the body instead.

- **`HubConnector` reads its configuration when it sends.** Base URL, PAT and
  timeout were baked in when the container first built the singleton, so config
  changed afterwards — per tenant, or in a test — was ignored for the rest of the
  process. Constructor arguments are now optional and still win when passed; the
  binding stays a singleton, so a mock client registered on it still intercepts
  every call.

- **The lock rules have one home.** `Support\HubLocks` holds the store
  resolution, the booking window's `lock_seconds > timeout` rule and the webhook
  window; `DocumentBooker` and `HubWebhookDeduplicator` delegate to it. Their
  public and protected methods are unchanged.

## [0.23.0] — 2026-08-18

Hub's relation ladder is live, so the fixtures this package ships as its test
double were re-captured against it. No source change — but a consumer testing
against `HubMock` was testing against a Hub that no longer exists.

### Changed

- **`validate-clean.json` and `validate-findings.json` re-captured.** Both now
  carry `blocking` on every finding and in `summary`, the field
  `Finding::isBlocking()` was written for in 0.20.0 and had no fixture to read.
  The failing payload teaches what severity cannot: `exact.vat_code.unmapped` is
  a `warning` that refuses the booking, `exact.relation.new` is an `info` that
  does not.

- **`exact.relation.new` flipped from `warning` to `info`.** The old fixture said
  *"de boeking wordt geweigerd — voeg de relatie toe of laat automatisch
  aanmaken inschakelen"*, which is the world before the ladder: a booking with an
  unknown relation was refused unless a flag said otherwise. That flag went in
  0.21.0. Hub now creates the relation during the booking and says so.

- **`mapping.json` carries `gl_accounts.sales_default` and `purchase_default`**
  next to `_default`. The SDK passes mapping through untouched; the fixture was
  simply older than the keys.

### Added

- A `HubMock` test that pins the blocking flag Hub decides — the known answer of
  `Finding::isBlocking()`, which until now only had its "unknown" answer covered.

## [0.22.0] — 2026-08-18

Three things this package defined but left to every consumer to build: the
writer for its own accounting-change columns, a way to watch a booking happen,
and a switch to shut the webhook endpoint.

Nothing here requires a consumer to change code.

### Added

- **`AccountingChangeRecorder`** — marks a posted ledger row when an
  `accounting.*.changed` webhook names a document this consumer booked. The
  three columns it writes (`accounting_changed_at`, `accounting_change_action`,
  `accounting_change_event_id`) shipped in 0.18.0 with a reader and no writer:
  `BacklogRepository`'s `accounting_changed` filter, `PostedDocuments::excluding()`
  and both resources have read them ever since, while each consumer transcribed
  the join out of `docs/webhooks.md`. That is the same argument `PostedDocuments`
  ships on — it is account-scoped ledger SQL, and getting the scope wrong is
  silent.

  Wired as a listener on `HubWebhookReceived`, so a consumer that subclasses
  `ProcessHubWebhookJob` — every multi-DB one does — keeps the marker without
  remembering to call `parent::`. Turn it off with
  `EMEQ_HUB_BOOKING_RECORD_ACCOUNTING_CHANGES=false`; widen or narrow the echo
  window with `EMEQ_HUB_BOOKING_ECHO_WINDOW_SECONDS` (default 300).

  Suppresses the echo of your own write two ways: `isOwnEcho()`, and proximity
  to the row's own `booked_at`. The second exists because the first goes quiet
  in more ways than "a human did this", and a marker that appears on every
  document you book teaches people to ignore it.

  Provider-agnostic by construction: it reads `entity_id` / `action` off the
  envelope and never the raw partner payload (ADR-0001). Override `entityKey()`
  to rescue a provider Hub cannot map an id for.

  A ledger without the three columns is left alone rather than failing the
  delivery — a consumer that books nothing never published the migration, and a
  throw there earns five Hub retries over roughly three hours.

- **`DocumentBooked` and `DocumentBookingFailed`**, dispatched by
  `BookingRunner` for a single booking and for every document in a batch. There
  was no event on the booking path at all, so observing an outcome meant
  wrapping the runner — and because the runner hands back no model, wrapping it
  meant re-finding the record just to name it.

  Both carry `module`, `id`, the `BookingOutcome` and a `subject`.
  `DocumentBookingFailed` covers outcomes that are not alike: read
  `$outcome->status` and `$outcome->mayRetry()` rather than treating a 422 like
  a 503. `check()` dispatches nothing — it books no document.

- **`BookableDocument::$subject`** — an optional third constructor argument
  carrying the record a document was mapped from onto both events. Untyped:
  this package knows nothing about consumer models. Existing call sites are
  unaffected.

- **`HubWebhooksEnabled` middleware and `hub.webhook.enabled`**
  (`EMEQ_HUB_WEBHOOK_ENABLED`, default `true`). Put it on the webhook route to
  be able to shut the endpoint for the window in which the code is deployed but
  `webhook_calls` has not reached every database that has to hold it. Default
  open, and absent config reads as open, so an existing consumer sees no change.

### Changed

- **`relation.relinked` documented** as a fourth Hub warning code, emitted when
  a mirrored relation turns out to be gone from the administration. Warnings are
  passed through unread, so it already reached `$outcome->warnings` without a
  release — the README and ADR-0004 simply listed three.

- `composer.json` branch alias and the install lines in `README.md` /
  `docs/agent-prompt.md` said `0.14`; the tagged line is `0.21`.

## [0.21.0] — 2026-08-17

Hub retired the per-document flag that asked a consumer to decide whether the
bookkeeping should create a relation it does not know yet — that decision now
runs a deterministic ladder on Hub's side. This release drops the flag and
carries the report of what Hub did instead.

### Removed

- **BREAKING: `$createRelation` off `DocumentBooker::book()` and
  `BookingRunner::book()` / `bookOne()`**, and the `party.create_if_missing`
  it used to set. Hub no longer reads the field: relation resolution is now a
  fixed ladder (mirror, chamber of commerce, VAT number, name, create) that
  runs the same way whether a caller asked for it or not — see the Hub's
  `relation-resolution-ladder` decision. Drop the argument at every call
  site; the pin that keeps a retry sending the same relation key
  (`party.external_id` off the ledger row) is unaffected and still applies.

### Added

- **`warnings` on the ledger record and on `BookingOutcome`.** The checkbox
  that used to ask permission for creating a relation is gone, so Hub reports
  after the fact what it did instead: `relation.created`,
  `relation.matched_by_name`, `relation.name_differs`. Read via
  `$record->warnings` straight off `DocumentBooker::book()`, or
  `$outcome->warnings` off `BookingRunner::bookOne()`; both also reach
  `BookingResource` and therefore `BatchResultResource` /
  `BacklogDocumentResource`'s nested `booking`. Not a column: only the
  attempt that booked the document can report them, so a row read back later
  carries none rather than a stale answer.

- **`HubMock::createDocumentWithWarnings()`** for a consumer's own tests. Not
  a live capture — Hub had not shipped `warnings[]` on this endpoint when this
  release was cut — so it is shaped from the Hub's own decision doc rather
  than a redacted response. Swap it for a real capture once Hub ships the
  field.

### Changed

- **BREAKING: `party.kind` (`company` | `person`) and `party.external_id`
  are now required on every booked document; `party.relation_id` is a new
  optional pin that skips Hub's matching ladder outright.** `kind` types the
  party rather than flagging behaviour — it says which keys exist to match
  on, not what Hub may do. A `company` needs a chamber-of-commerce or VAT
  number or Hub refuses the document; a `person` has neither and leans on the
  mirror alone. Update mappers, test helpers and fixtures that build a
  `party` block accordingly.

## [0.20.0] — 2026-08-17

A failed booking now names the Hub request that decided it, and Hub decides
whether a retry is worth making instead of this package guessing from a list.

Nothing here requires a consumer to change code, and the migration is optional.

### Added

- **`request_id` and `category` on `hub_documents`.** A failed row now carries the
  value that ties it to a line in Hub's own log; without it, working out why
  yesterday's batch stopped is a search by timestamp across two systems. Both are
  cleared once the document is posted — a trace that outlives its outcome points
  somewhere unrelated.

  Publish `add_trace_to_hub_documents_table` to get them on an existing ledger; a
  fresh `create_hub_documents_table` already has them, and running both is safe.
  **Optional**: without the migration the package books exactly as before and
  simply stores no trace. See `HubDocument::withoutMissingTrace()`.

- **`request_id` and `category` on `BookingResource`**, and therefore inside
  `BacklogDocumentResource`'s `booking` too. Storing the trace without exposing it
  would move the search rather than end it — a support question needs to be able
  to quote the value. `category` travels with it so a frontend can tell "the
  connection is broken" from "this document is wrong" without knowing every code.

- **`HubException::context()`.** Laravel's exception handler calls this and merges
  the result into the log record, so `hub_request_id`, `hub_error`,
  `hub_category` and `hub_status` reach your log or Sentry without configuring
  anything.

- **`HubException::$retryable`** — Hub's own answer to "is sending this again
  worth it", `null` against a Hub that predates the field. Null means *no
  opinion*, not *no*.

- **`HubException::$validationErrors`** — Hub's per-field messages on a 422, which
  the envelope always carried and this package used to discard. A rejected
  document now says which field it was instead of leaving you to guess from one
  sentence.

### Removed

- **The eight `booking.error.*` lines for Hub's own error codes**, leaving only
  `error.unknown`. They were unreachable for any row this package writes:
  `BookingOutcome::from()` prefers Hub's message, and Hub always sends one — the
  code itself when nothing better exists. So they never rendered, and where they
  would have, they were worse. Hub says `"Grootboek-code '8000' niet in de mirror
  — draai POST /v1/accounting/sync."`; the line here said "the bookkeeping does
  not know this relation or code yet".

  Keeping copy for Hub's codes in a package also prices every wording change at
  an SDK release plus an update per consumer, for a sentence Hub can already fix
  by deploying. One source, and it is Hub.

  **If you published `hub-translations`,** nothing changes: your file keeps its
  keys and they still win. That is now the documented way to take one code back —
  per code, not wholesale.

### Changed

- **`DocumentBooker` asks Hub instead of consulting a list.** `TRANSIENT_ERRORS`
  and `REJECTIONS` still exist as the fallback for a Hub without `retryable`, but
  a current Hub decides. A code this release has never heard of is now classified
  correctly rather than silently recorded as a permanent failure — which is what a
  hardcoded list does the moment Hub adds one.

## [0.19.0] — 2026-08-17

Hub now says *which* entity changed and *when it last wrote it*, so a consumer can
tell its own echo apart from a bookkeeper's correction. This release exposes both
and retires the field that could not.

### Removed

- **`caused_by_hub` / `HubWebhookEnvelope::$causedByHub`.** The 0.18.0 docs already
  warned it did not mean what it says: it was an `exists()` on "has Hub ever written
  this entity", with no time component, so every later notification for a
  Hub-booked document carried `true` — including a bookkeeper hand-editing it weeks
  afterwards. Hub no longer sends the key. Replace a filter on it with
  `$envelope->isOwnEcho()`; replace a read of it with `$envelope->hubAuthored`,
  which carries the same fact under a name that does not overpromise.

### Added

- **`HubWebhookEnvelope::$entityId`** — the provider's own id for the changed
  entity, the same id Hub returned when you booked it. The join key between an
  incoming change and your own ledger row, with no provider-specific parsing of
  `data`. Null when the provider carries no id this release can read: Exact and
  Mollie supply one, Snelstart does not (its payload shape is still an open
  question with the partner, and inventing a field name would be worse than
  admitting the gap).

- **`HubWebhookEnvelope::$action`**, typed as the new **`HubWebhookAction`** enum —
  `CREATED`, `UPDATED`, `DELETED`, `UNMAPPED`. Mirrors `HubWebhookEvent`: an action
  a later Hub release names decodes to `UNMAPPED` instead of throwing, so a new
  provider needs no SDK release. Absent and `UNMAPPED` differ on purpose — `null`
  means the provider sends no action at all (Mollie), `UNMAPPED` means it sent one
  this release has no case for.

- **`HubWebhookEnvelope::$hubAuthored` and `$hubLastWroteAt`** — whether Hub ever
  wrote this entity, and when it last did. Hub reports both facts and draws no line
  itself; the echo window belongs to the consumer.

- **`HubWebhookEnvelope::isOwnEcho(int $seconds = 300)`** — the line, applied.
  `true` only when Hub authored the entity *and* the delivery landed within
  `$seconds` after Hub's own write. Anything it cannot establish reads as `false`:
  a missing field errs toward looking at an event rather than dropping it.

- **`FakeHubWebhook::event()` / `::salesInvoiceChanged()`** take `entityId`,
  `action` and `hubLastWroteAt`, so a consumer test can stage the echo and the
  later human correction as two distinct deliveries. `salesInvoiceChanged()`
  defaults `action` to `UPDATED`, matching what Exact actually sends.

### Changed

- **`FakeHubWebhook`'s `causedByHub:` argument is now `hubAuthored:`.** A named
  argument in an existing test needs renaming; a positional one does not.

## [0.18.0] — 2026-08-17

`emeq/system` is the first consumer to wire up inbound webhooks end to end, and
hit four gaps every future consumer would hit too. This release closes them.

### Fixed

- **The first inbound webhook 500s for any consumer that did not publish
  `webhook-client.php`.** Spatie's `WebhookClientServiceProvider` merges in a
  placeholder config entry (`['name' => 'default', 'process_webhook_job' => '',
  …]`) and maps every entry through `new WebhookConfig()`, which throws
  `InvalidConfig` on an empty `process_webhook_job` — precisely what that
  placeholder carries. `HubServiceProvider::registerHubWebhookClientConfig()`
  now drops every config entry whose `process_webhook_job` is an empty string
  while it rewrites `webhook-client.configs`, and logs
  `hub.webhook.dropped_unprocessable_config` with the names it dropped. Nothing
  functional is lost: an entry in that state could never have processed a
  webhook anyway.

### Added

- **`ProcessHubWebhookJob::handles()` / `onEvent()`.** Previously every event
  other than `connection.revoked` routed to `onIgnored()`, so a consumer
  acting on, say, `accounting.sales_invoice.changed` had to override
  `onIgnored()` and claim the event before calling `parent::` — a naming lie,
  and a listener-based consumer got `hub.webhook.ignored` logged for an event
  it handled. Override `handles(): array` to name the `HubWebhookEvent` cases
  you act on; `processEnvelope()` now routes those to `onEvent()` instead of
  `onIgnored()`, and dispatches the new `HubWebhookHandled` event alongside it
  — mirroring the existing `HubWebhookReceived` / `HubWebhookIgnored` pair.
  `connection.revoked` still always goes to `onConnectionRevoked()`, regardless
  of `handles()`. A job that overrides neither method behaves exactly as
  before — `handles()` defaults to `[]`, so `onIgnored()` still gets everything.

- **Accounting-change columns on `hub_documents`.** A consumer that books a
  document and later receives an `accounting.*.changed` webhook for it now has
  somewhere to record that the bookkeeping changed it afterwards:
  `accounting_changed_at` (timestamp), `accounting_change_action` (string, 32)
  and `accounting_change_event_id` (string, 64 — a sha256 of the raw delivery
  body, since Exact carries no notification id of its own), all nullable, plus
  an index on `['account_id', 'external_ref']` — the lookup every delivery
  performs. These land directly in the published `create_hub_documents_table`
  stub rather than a separate `add_` migration: `hub_documents` has not shipped
  to production in any known consumer yet, so there is nothing to patch.

  **If you already ran the previous version of this stub**, add the three
  columns and the index yourself — this is not purely additive for you:

  ```php
  Schema::table('hub_documents', function (Blueprint $table): void {
      $table->timestamp('accounting_changed_at')->nullable();
      $table->string('accounting_change_action', 32)->nullable();
      $table->string('accounting_change_event_id', 64)->nullable();
      $table->index(['account_id', 'external_ref']);
  });
  ```

  `HubDocument` carries the three columns in `$fillable`, with
  `accounting_changed_at` cast to `datetime`. `Booking\Resources\BookingResource`
  exposes `accounting_changed_at` (ISO 8601, nullable) and
  `accounting_change_action`. `accounting_change_event_id` is not exposed — it
  is a server-side correlation id, no screen reads it.

- **`Emeq\HubSdk\Testing\FakeHubWebhook`.** `HubMock` covered 17 API responses
  and zero webhooks, so every consumer hand-rolled
  `hash_hmac('sha256', $body, $secret)` next to an invented envelope array —
  knowledge this package already owned in `SpatieWebhookClientConfig`,
  `HubWebhookHeaders` and `HubWebhookEnvelope::toArray()`.
  `FakeHubWebhook::event()` builds any canonical event; `connectionRevoked()`
  and `salesInvoiceChanged()` are canned shortcuts for the two most common
  ones. `body()` is the exact raw JSON a consumer must sign; `headers($secret)`
  returns `Signature` (HMAC-SHA256 over that body), `X-Emeq-Event-Id` and
  `X-Emeq-Request-Id` — verified in this release's tests against the packaged
  `DefaultSignatureValidator`, not just against its own formula. No JSON
  fixture files: `Testing/fixtures/*.json` is reserved for responses captured
  from a live Hub, and a webhook delivery is not one — see `CONTEXT.md`.

- **The backlog surfaces a document the bookkeeping changed after it was
  booked.** `PostedDocuments::excluding()` previously dropped every `posted`
  document from a consumer's backlog source query unconditionally; it now
  keeps one whose `accounting_changed_at` is set, because that document needs
  attention again. Without this change nothing downstream could ever match —
  the document was never in the query to begin with.

  `BacklogRepository` gains an `accounting_changed` filter param (composes
  with `status` rather than replacing it — a changed document is `posted`
  *and* changed, not a new `BacklogStatus` case) and `summary()` gains an
  `accounting_changed` count, a sibling of `by_status` rather than a widened
  grouping (folding it in would either double-count the document or steal it
  from a bucket `by_status` does not carry, since `posted` is not one of
  {@see BacklogStatus::all()}'s cases). `BacklogSummary::$accountingChanged`
  defaults to `0` for any code constructing it directly.
  `BacklogDocumentResource` exposes `accounting_changed_at` (ISO 8601) and
  `accounting_change_action` on the row itself, alongside the existing
  `status` — a frontend does not have to read into `booking` for the marker.

### Documentation

- **`caused_by_hub` does not mean "Hub caused this change".** It is computed
  from whether Hub has *ever* authored the entity, not whether Hub wrote *this*
  delivery's change — verified against Hub's own `ExactEchoDetector`. A
  bookkeeper hand-editing a Hub-booked invoice in the provider's UI weeks later
  still arrives flagged `true`, and the previous wording in `docs/webhooks.md`
  told consumers to drop exactly those events — the ones they most need to act
  on. Rewritten to state the real semantics and note that a Hub-side fix
  (`hub_last_wrote_at`) is proposed but unshipped; this SDK assumes nothing
  about its shape yet.

## [0.17.0] — 2026-08-17

Exact approved the App Center scope that had been blocking webhook registration, so
Hub now subscribes to eight bookkeeping topics instead of two. Four of those cover
entities Hub writes itself, which means a booking you request travels back to you as
a change notification. This release carries the vocabulary for the new events and the
marker that tells your own writes apart from the bookkeeper's.

### Added

- **`Webhooks\HubWebhookEvent::PURCHASE_INVOICE_CHANGED`**,
  **`JOURNAL_ENTRY_CHANGED`**, **`DOCUMENT_CHANGED`** and **`LEDGER_ACCOUNT_CHANGED`**
  — `accounting.purchase_invoice.changed`, `accounting.journal_entry.changed`,
  `accounting.document.changed` and `accounting.ledger_account.changed`. Before this
  release those decoded to `UNMAPPED`, which the guide tells consumers to ignore, so
  the events arrived but read as noise.

- **`Webhooks\HubWebhookEnvelope::$causedByHub`** — `true` when Hub established that
  the change traces back to a write it made on your behalf. Do not write back on
  those; that is a loop. `false` means not established rather than definitely
  external, because Hub sets the flag only on positive evidence. `toArray()` emits
  `caused_by_hub` only when true, mirroring the wire format.

## [0.16.0] — 2026-08-16

Found while working out what five consumer apps booking at the same time
actually do to Hub. For correctness the answer was reassuring — Hub guards a
booking twice over, with an Idempotency-Key claim and a per-connection claim
behind it. For throughput it was not: Hub rate-limits per consumer, and this SDK
threw away every word Hub said about how long to wait.

### Added

- **`Exceptions\HubException::$retryAfter`** — the `Retry-After` Hub sets on the
  429 from its rate limiter and on the 409 that means "this document is already
  on its way", in seconds. Only the delay form is read; the HTTP-date the RFC
  also allows would have to be trusted against this machine's clock, and a wrong
  wait either hammers Hub or strands the document.

- **`Exceptions\BookingTemporarilyUnavailable::$retryAfter`** (and so
  `BookingAlreadyInProgress`), **`Booking\BookingOutcome::$retryAfter`** and
  **`Booking\CheckOutcome::$retryAfter`**, exposed as `retry_after` on
  `BatchResultResource` and `CheckResultResource`.

  Without this a consumer could only retry on a fixed interval, which is exactly
  how a fleet of consumers synchronises itself into one throttled herd. The SDK
  still does not retry on its own: waiting inside the call pins a request worker
  for the duration. README shows the queue-job shape instead.

### Changed

- **A throttled or unreachable Hub during `check()` answers 503, not 502.**
  `bookOne()` already made this split; `checkOne()` flattened a rate limit into
  "Hub could not answer", which reads as nobody's problem and is not retryable.
  The same condition now gets the same answer on both paths.

- **`hub.booking.lock_seconds` must exceed `hub.timeout`**, and booking refuses
  to run when it does not. A lock that expires while the send is still in flight
  lets a second attempt start alongside the first — the one situation the lock
  exists to prevent. Defaults (40 vs 30) already satisfy it.

### Fixed

- **`document_sync_in_progress` is no longer recorded as a failed booking.** Hub
  has two guards and each has its own word for "wait": the Idempotency-Key claim
  says `idempotency_request_in_progress`, the per-connection claim behind it says
  `document_sync_in_progress`. Only the first was known here, so the second fell
  through to the failure branch — writing a permanent no into the ledger, and
  reporting it, for a document nobody had refused.

- **Losing the race to the ledger no longer throws.** Two attempts that both
  decide the same document — reachable once the booking lock stops covering the
  send — both insert, and the ledger's identity index lets one of them in. The
  loser now reads the winning row and keeps the better of the two answers rather
  than surfacing a `UniqueConstraintViolationException`. `posted` is always the
  better one; demoting it would hide a document that is in the bookkeeping.

### Documentation

- **How to resolve an `unknown` row.** Offering the same document again,
  unchanged, is safe: Hub replays the response stored against that idempotency
  key, and once that has expired its per-connection guard recognises the document
  by content fingerprint and answers `deduplicated`. Change the content in
  between and you get `document_already_posted`, which is also correct. The SDK
  never does this for you — only the consumer knows the document is unchanged.

## [0.15.0] — 2026-08-16

A 503 said one sentence for three different situations, and the sentence named
none of them. Found in a consumer's batch run, where two overlapping runs over
the same documents produced a screenful of "or the bookkeeping is briefly
unreachable" for documents that were, in fact, being booked fine.

### Added

- **`Exceptions\BookingAlreadyInProgress`**, a narrower
  `BookingTemporarilyUnavailable` thrown when this exact document is already on
  its way: the booking lock is held by a concurrent attempt, or Hub answered
  `idempotency_request_in_progress`. Existing `catch (BookingTemporarilyUnavailable)`
  keeps catching both.

- **`Booking\BookingOutcome::alreadyInProgress()`**, the 503 that tells the user
  to wait for the run they already started rather than go and look at the
  bookkeeping. Still `mayRetry()`.

- **`Booking\BookingOutcome::$reason`**, the exception text behind a 503, for
  the consumer's log. It is not the user's message: it names an external id and
  speaks English. `BookingRunner` fills it, and a consumer that logs its
  outcomes can finally tell a held lock from a throttled Hub — previously both
  arrived as a 503 with no trace of which.

### Changed

- **`Booking\BookingOutcome::unavailable()` takes a reason, not a message
  override.** The user-facing copy is now always the translated line.

  **Breaking:** a caller passing a string to override the copy no longer does
  so. No consumer did.

- **`hub::booking.temporarily_unavailable` no longer covers two situations.**
  It now means only "the bookkeeping is unreachable"; the new
  `hub::booking.already_in_progress` covers a document that is already being
  booked. Consumers that published `hub-translations` must add the new key.

## [0.14.0] — 2026-08-16

Found while wiring 0.13.0 into its first consumer.

### Changed

- **`Booking\CheckOutcome` carries a `status`**, the same vocabulary
  {@see BookingOutcome} already used: 200 when Hub answered, 404 / 403 / 422 for
  a document that is missing, not this user's or not mappable, 502 when Hub
  failed to answer and 503 when the check broke for an unrelated reason. It
  previously flattened all of those into a message plus a `retryable` flag, so a
  caller could not tell "your document is wrong" (the user's to fix) from "Hub
  could not answer" (nobody's) — and every consumer would have had to
  reconstruct that split from the message text.

  **Breaking:** the `retryable` property is gone; use `mayRetry()`, which reads
  the status. The constructor takes `status` as its third argument.

- **`Booking\Resources\CheckResultResource` exposes `status` and `may_retry`**,
  so a batch report can group what can be repeated without inspecting copy.

## [0.13.0] — 2026-08-16

The booking core moves out of the first consumer and into the SDK. Everything
here was running in production in `emeq/system`; what stayed behind is the part
that knows an app's data model (mappers, backlog sources, attachment renderers).
Nothing existing changed — this release is additive.

### Added

- **`Booking\HubDocument` + a `create_hub_documents_table` migration stub** —
  the ledger of what this consumer sent and what Hub answered, keyed the way Hub
  defines a document's identity: `(account_id, type, external_id)`. Why it lives
  in the consumer rather than being read back from Hub:
  [ADR-0003](docs/adr/0003-the-booking-ledger-lives-in-the-consumer.md).
  Publish-only, per ADR-0002. Reads `hub.booking.connection` and declares no
  connection of its own — a ledger on the wrong database answers "not booked
  yet" and the next run posts a duplicate into a real administration.

- **`Booking\DocumentBooker`** — books one canonical document and records the
  outcome. Takes a document array, not a model: mapping stays in the consumer.
  What it encodes, and what a second consumer would otherwise have to rediscover
  by posting duplicates into someone's bookkeeping:
  - `429`, `5xx` and `idempotency_request_in_progress` say nothing about the
    document, so they write **no row** and throw
    `BookingTemporarilyUnavailable`. "No row" and "a row saying failed" are the
    difference between a safe retry and a wrong one.
  - `document_already_posted`, `idempotency_key_reuse` and `upstream_rejected`
    are answers about this document — `rejected`, not `failed`, and not reported
    as an error.
  - A dropped connection records `unknown`: Hub may have posted it anyway, so
    nothing may resend it automatically.
  - A document already `posted` is never sent again. Identical content is a
    no-op and changed content is refused, which would demote a posted row.
  - `party_external_id` is pinned to whatever the first attempt used, so
    unlinking a party from its parent record cannot open a second relation
    upstream.
  - Concurrent attempts on one document fail fast instead of queueing, behind a
    lock scoped to `(account, external_id)` — not to type, because one invoice
    is a `sales_invoice` or a `credit_note` depending on its live total.
  - Attachments are rendered inside that error handling (pass a closure), so a
    renderer that throws records `attachment_render_failed` instead of losing
    the attempt.

- **`Booking\BookingOutcome`** — maps a decided ledger row to what a caller
  should say and with which status: booked (200), refused (422), needs a human
  (422 + `needsManualCheck`), retry later (503), Hub failed (502). Only 503
  answers `mayRetry()`.

- **`Booking\BookingLedger`** — account-scoped ledger reads, so no caller has to
  remember that an unscoped read shows another administration's bookings.

- **`Exceptions\DocumentNotBookable`** — base class for a consumer's own
  "this can never be sent" exceptions (a draft, a missing party), so the SDK can
  tell them apart from "did not book this time" without knowing their names.

- **`Support\DutchVatNumber`** — normalises and validates a Dutch VAT number
  under both check-digit schemes the Belastingdienst has issued (eleven-test and
  mod-97), adding the country code Dutch bookkeeping commonly leaves off.
  Anything that fails is dropped rather than sent: a wrong VAT number lands on
  the relation and ends up on returns.

- **`Booking\BookingRunner`** — checks and books by `(module, id)`, one or a
  batch, behind `Booking\Contracts\ResolvesBookableDocument`. Owns the table
  nobody wants to rediscover: missing is 404, unauthorised is 403, unmappable is
  a refusal, an undecided send is 503, and anything unexpected is reported
  rather than shown raw. A batch stops once `hub.booking.batch_seconds` is
  spent, so a run cannot outlive its request — safe to repeat with the
  remainder, because an already-posted document is never sent twice.

- **`Exceptions\DocumentNotAuthorized`** — the SDK's own word for "this user may
  not book it", so authorising stays entirely the consumer's (a gate, a policy,
  a role check) and this package gains no auth dependency.

- **`Backlog\BacklogRepository`** — "which of my documents are not booked yet",
  behind `Backlog\Contracts\ProvidesBacklogSources`. The consumer returns one
  query per module with nine named columns; this owns the left join against the
  ledger, the `not_booked` filter (the absence of a row, so it cannot ride the
  same `whereIn` as the others), sorting with stable tiebreakers, paging, and a
  summary counted over the whole filter rather than the page. Rows come back
  carrying their full ledger row, not just the joined status — a refusal's
  reason is the thing a backlog exists to show.

- **`Backlog\PostedDocuments`** — the "already in the bookkeeping" exclusion for
  consumer source queries. Ships here because getting its account scope wrong is
  silent: an unscoped exclusion hides another administration's postings from
  this one's backlog.

- **`Backlog\BacklogStatus` / `BacklogSummary`**, and JSON resources for both
  layers: `Booking\Resources\{BookingResource, BatchResultResource,
  CheckResultResource}` and `Backlog\Resources\{BacklogDocumentResource,
  BacklogSummaryResource}`. Routes, request validation and the response envelope
  stay the consumer's — those differ per app and always will.

- **Outcome copy in `en` and `nl`**, published with
  `vendor:publish --tag=hub-translations`, resolved in one place
  (`Booking\BookingMessages`) so republishing rewords every outcome at once.

- **`hub.booking.*` config** — `connection`, `lock_store`, `lock_seconds`,
  `batch_seconds`, `page_length`. The lock store must support atomic locks; one
  that cannot says so with a configuration error rather than letting two
  attempts post the same document.

### Not moved, deliberately

Mappers, backlog source queries, attachment renderers, controllers, form
requests and response envelopes stay in the consumer: they know a data model or
an app's auth, and generalising them would mean guessing at both. The Dutch
reverse-charge default (rate `0` on a shifted line meaning 21%) is a tenant-level
assumption, not a canonical one, and stays with the mapper that makes it.

## [0.12.1] — 2026-08-15

### Fixed

- **`IntegrationController::index()`'s docblock leaked into consumer API docs.**
  Scramble takes the first paragraph as an endpoint `summary`, so a consumer's
  generated OpenAPI carried this package's internal rationale about ADR-0001 as
  the public description of `GET /integrations`. Split into a one-line summary
  plus the rationale below it.

## [0.12.0] — 2026-08-15

Hub adds an additive `blocking` field to every `validateDocument()` finding.
This release adds the honest way to read it — including from a Hub that predates
the field — and closes an untyped leak this package's own consumer BFF had.

### Added

- **`Emeq\HubSdk\Resources\Finding::isBlocking()`** — reads a finding's
  `blocking` field without guessing at Hub's semantics. Some `warning`-severity
  findings reject the booking anyway (Hub says so in their own `message`);
  `blocking` is the field meant to replace parsing that text, and some Hub
  deployments will not send it yet. Absent or non-boolean answers `null` —
  never a guessed `false` — so a consumer cannot mistake "Hub hasn't told me"
  for "nothing blocks". `severity` and `valid` keep their existing meaning;
  nothing about them changed.

### Fixed

- **`IntegrationController::connectSession()` leaked `mixed` `url` /
  `expires_at`.** `$session['url'] ?? null` still typed as `mixed`, widening
  the consumer's generated schema for both fields to `any`. Narrowed with
  `is_string()`, same pattern `returnPath()` already used for config, behind
  an explicit `array{url: string|null, expires_at: string|null}` return type.

### Documentation

- **`IntegrationController::index()`** now states the shape it answers —
  `list<array<string, mixed>>`, the same type `Integrations::list()` already
  declares. Item keys stay untyped on purpose: Hub's discovery payload is
  data-driven per provider, and narrowing keys here would mean hard-coding a
  schema ADR-0001 deliberately keeps out of the SDK.

### Not done, on purpose

- **`validate-clean.json` and `validate-findings.json` were not recaptured.**
  Both predate `blocking`. Hand-editing the key into a "captured" fixture would
  turn honest test data into invented Hub semantics with a stamp of authority,
  so they stay as they are until `tools/capture-fixtures.php` reruns against a
  Hub that sends the field. Their current shape is also what an older Hub still
  answers, which is exactly the `null` path `Finding::isBlocking()` covers.

## [0.11.0] — 2026-08-13

Reverses the "not done, on purpose" call on test affordances from 0.10.1. That
call was right about the blocker — the SDK had no fixtures, only invented
payloads inline in its own tests — and wrong to leave it there. The blocker was
removable: capture the responses.

### Fixed

- **`Hub::accounting()->sync()` was dead on arrival.** `SyncAccountingRequest`
  promoted a readonly `$body`, which collides with the non-readonly `$body` that
  `HasJsonBody` declares — PHP fatals at class-load, so every call to `sync()`
  crashed the request. Exactly the trap `GetAccountingRequest` documents for
  `$query`, one class over, uncaught because nothing exercised `sync()`. The
  property is `$payload` now, and the mock factory below covers the call.

### Added

- **`Emeq\HubSdk\Testing\HubMock`** — canonical `MockResponse` factories for the
  accounting reads, `createDocument()`, `sync()`, the validation endpoint and
  the error envelopes, plus `HubMock::accounting()` for wiring the lot into
  `MockClient::global()` in one line, and `HubMock::fixture()` for the raw
  payload. New public namespace, hence the minor bump.
- **`src/Testing/fixtures/*.json`** — 16 responses captured from a live Hub
  against a connected provider, then redacted. Same files feed the SDK's own
  tests, so a consumer testing against `HubMock` tests against the shape this
  package treats as the truth.
- **`tools/capture-fixtures.php`** — the capture run itself, so the fixtures can
  be refreshed rather than hand-edited when they go stale. Reads and validation
  are side-effect free and run by default; booking and re-sync sit behind
  `--allow-write`. Development only, `export-ignore`d.

### Changed

- **The SDK's own accounting tests now read those fixtures** instead of the
  payloads they used to invent — the ones that asserted paging behaviour were
  asserting it against a shape Hub does not send.
- **README documents what the captures actually show.** `validateDocument()`
  answers `200` whether or not the document is valid, and a clean document still
  carries findings — a matched relation comes back as `info`, so `findings === []`
  is not the success test. `referenceData()` is grouped by kind with no `kind` on
  the items, `mapping()` is wrapped in `{mapping: …}` and holds composite
  `reverse_charge:21` keys, and a document read is a thin projection where
  `issue_date`, `party.name` and `lines` can all be empty.
- **New pitfall: `documents()` requires a `type`.** Hub answers a collection read
  without one with `400 invalid_query` / `VALIDATION_ERROR`, which the SDK raises
  as `ValidationException`.

- **The write path is captured too.** `createDocument()` answers `201` with the
  provider's own `external_ref` and `external_number` — neither is in the
  OpenAPI response schema, which says `{"type": "string"}` for this endpoint.
  Replaying the same `Idempotency-Key` returned a byte-identical body against a
  live Hub, so the idempotency contract the README states is now measured rather
  than asserted.
- **`POST` and `GET` on `/accounting/documents` share a URL**, and Saloon matches
  mocks on the URL alone, so one map cannot answer both. `accounting()` answers
  the read and a booking test keys the pattern itself. A test pins that
  behaviour, so the documentation of the trap cannot quietly stop being true.

### Not done, on purpose

- **Still no typed value objects for the canonical document.** 0.10.1 declined a
  hand-written mirror of the TypeScript definition in `emeq-app`; capturing real
  responses turned that from a judgement call into a measurement. That mirror is
  already drifted from the Hub's committed spec — it is missing
  `lines[].tax_treatment` and disagrees on the `issue_date` format — and the
  captured `reference-data` and `mapping` payloads contradict it further: items
  carry no `kind`, `vat_codes` holds composite keys, and `auto_create_relations`
  is not in the response at all. A PHP copy of an already-drifted copy would be
  the third answer to the same question. If types come, they get generated from
  `api.json`, where drift is a failing CI diff instead of a human error.

## [0.10.1] — 2026-08-13

Docs only — no API change. Both findings come from the first consumer building
against `Hub::accounting()`.

### Documentation

- **The idempotency example cancelled idempotency out.** The README showed
  `createDocument($payload, idempotencyKey: (string) Str::uuid())`. A fresh uuid
  per call means the retry after a timeout arrives with a new key, and Hub books
  the same document twice — the exact case the header exists for. The example now
  derives the key from `$payload['external_id']`, which the canonical contract
  names as the stable document key; the rule is stated in the pitfall list and in
  the `createDocument()` docblock, which said nothing about it.
- **How consumers mock the SDK.** 0.9.0 dropped `saloonphp/laravel-plugin`, so
  `Saloon::fake()` is gone and nothing documented what replaced it. New *Testing
  your integration* section: `MockClient::global([...])` keyed on URL patterns —
  `HubConnector` is a singleton `Saloon\Http\Connector`, so a global mock
  intercepts every call, and URL keys keep consumers out of the package-internal
  `Http\Request\*` namespace. Also warns that `global()` is `??=`, so a client
  left standing silently ignores the next test's mock data, and that the SDK
  ships no fixtures — shape mocks off the committed OpenAPI spec.

  Covered by a test that fails when the mocked URL stops matching, so the
  documented path cannot rot silently.

### Not done, on purpose

- **No typed value objects for the canonical document.** `createDocument()` /
  `validateDocument()` still take `array<string, mixed>`. A hand-written PHP
  mirror of the TypeScript definition in `emeq-app` would make a third copy of a
  contract that now has a committed owner (`api.json` in emeq-hub, CI-gated on
  drift), and that spec's request bodies are the reliable half — so generating
  DTOs later is cheap and mirroring by hand now is throwaway work.
- **No `Emeq\HubSdk\Testing` mock factories.** The proposal was to feed them from
  the fixtures the SDK's own tests use; those do not exist — the tests inline
  invented payloads. Canonical factories over invented shapes would only give
  consumers SDK fiction with a stamp of authority, and the spec cannot fix it
  (response schemas stay thin for provider pass-through). Blocked on capturing
  real Hub responses first.

## [0.10.0] — 2026-08-13

### Documentation

- **The Hub OpenAPI spec is now a committed artefact.** `/docs/api` was already
  live, but the exported `api.json` was gitignored and stale. Hub now commits it
  and fails CI on drift, so contract changes are visible as a diff. Noted in the
  further-reading block, with the caveat that request bodies are reliable and
  response schemas are still thin for provider pass-through endpoints.

- **README required `^0.7`.** On a 0.x that caret pins the minor, so consumers
  following the install block got 0.7.x — without the 0.9.0 route-auth guard.
  Both occurrences now require `^0.10`, and the `dev-master` branch alias moved
  to `0.10.x-dev`.
- **The `['name', 'id']` index claim is now conditional.** 0.9.0 documented it as
  one "the dedupe query needs". Measured on MySQL 5.7 / InnoDB with the real
  query: on a Hub-only `webhook_calls` the optimizer never picks it (`key=PRIMARY`
  at 50k and 200k rows, timings equal to having no index). It does pay off once
  the table carries several webhook configs — 21.5 ms → 12.4 ms at 50k rows with
  Hub at 20%. Documented as such.
- **README split.** 398 → 245 lines. Webhook wiring, connection placement and
  dedupe moved to `docs/webhooks.md`; the agent prompt to
  `docs/agent-prompt.md`; Boost / symlink tooling to
  `docs/local-development.md`. `docs/` is `export-ignore`d, so these are
  GitHub-only — the same choice `CONTEXT.md` already makes.

### Fixed

- **`webhookConfigName()` no longer hardcodes `emeq-hub`.** It reads
  `hub.webhook.name` — the value `HubServiceProvider` registers the Spatie config
  under. A consumer who renamed it had `alreadyProcessed()` filtering
  `webhook_calls.name` on a name no row carries, so dedupe matched nothing.
- **The auth-family check no longer accepts names Laravel cannot resolve to
  auth.** Aliases resolve by exact array key, so `AUTH:SANCTUM` was passed
  through as a class name; `auth.session` aliases `AuthenticateSession`, which
  invalidates sessions on password change and authenticates nobody. Both used to
  satisfy the guard. Accepted entries are now `auth`, `auth:*` and `auth.basic`.

### Changed

- **`HubWebhookDeduplicator::OPAQUE_EVENT_IDS` is a constructor argument.**
  Widening the sentinel list took a subclass to redefine a `protected const`;
  it is now the third constructor parameter, defaulting to `['no-id']`.
- **`HubRouteMiddleware::assertAuthenticated()` drops its
  `$allowUnauthenticated` flag.** The escape hatch is read once by the new
  `HubRouteMiddleware::validated()`, which is now the only way to obtain the
  configured stack — `routes/hub.php` no longer re-derives what
  `packageBooted()` validated.

## [0.9.1] — 2026-08-13

### Documentation

- **Which connection id to pass.** `Hub::connections()->get()` / `->delete()`
  take the `con_…` public id — the value `integrations()->list()`,
  `oauth()->init()` and the `connection_revoked` webhook all hand back. Hub's
  numeric primary key is accepted but internal.

  Hub itself only started accepting the public id on those two endpoints in
  August 2026; before that the documented connect flow returned a 500
  (`TypeError`, the controller type-hinted `int`). Nothing in the SDK changed —
  it already passed `string|int` straight through.

### Changed

- `dev-master` branch alias points at `0.9.x-dev`.

## [0.9.0] — 2026-08-12

Follow-up to the 2026-08-12 Laravel-extension audit. Five 🟠 and two 🟡, three of
them semver-breaking.

### Security

- **The BFF now refuses to boot without auth middleware.**
  `HubRouteMiddleware::assertNotEmpty()` claimed in its message that it was
  "refusing to register unauthenticated Hub BFF routes", but it only checked
  that the list was non-empty — `['api']` passed and registered an
  unauthenticated `POST …/integrations/connect-session`, which mints a Hub
  partner-OAuth handoff URL for whatever `ResolvesAccountId` returns. A new
  `assertAuthenticated()` requires an `auth`-family entry (`auth`, `auth:*`,
  `auth.*`).

  **Upgrading:** if your auth middleware is named outside that family
  (`tenant.auth`, a Sanctum wrapper), set `hub.routes.allow_unauthenticated` to
  `true` — or add `EMEQ_HUB_ROUTES_ALLOW_UNAUTHENTICATED=true`. Boot throws
  otherwise.

- **`Hub::connections()` is documented as PAT-scoped.** Hub resolves
  `/v1/connections/{id}` against the Consumer behind the token and reads no
  account context, so `get()` / `delete()` reach every connection of every
  account under that token. The SDK cannot narrow this — sending `X-Account-Id`
  would be ignored server-side and buy false confidence — so it is now stated in
  `Connections`, `CONTEXT.md` and the README pitfalls instead. Multi-tenant
  consumers must verify ownership before calling either method.

### Changed

- **Webhook dedupe moved to `HubWebhookDeduplicator`.** `ProcessHubWebhookJob`
  had grown to 370 LOC while also being the class consumers subclass, so every
  dedupe internal was a semver contract. The job keeps bind → resolve →
  dispatch; identity, locking and the `alreadyProcessed()` query move to a
  collaborator.

  **Upgrading:** `OPAQUE_EVENT_IDS`, `deduplicableEventId()`,
  `deduplicationLock()`, `deduplicationLockKey()` and `alreadyProcessed()` are
  gone from the job. Subclass `HubWebhookDeduplicator` and override the job's
  single `deduplicator()` hook instead. Lock keys and behaviour are unchanged.

- **`OAuthReturnUrl::fromConfigPath()` takes an origin string, not a `Request`.**
  It only ever needed `getSchemeAndHttpHost()`; taking it as a scalar keeps
  `Support/` free of `Illuminate\Http` and makes the class unit-testable.
  `IntegrationController` passes `$request->getSchemeAndHttpHost()`.

### Fixed

- **A failure the `failed()` hook could not record was swallowed.** Both early
  exits — `bindAccountContext()` returning false, and `resolveWebhookCall()`
  returning null — left `webhook_calls.exception` null, which is exactly the
  state `alreadyProcessed()` reads as "ran to completion", so Hub's redelivery
  was dropped. The 0.7.0 fix for that bug reproduced it in its own error paths.
  Both now log `hub.webhook.failure_unrecorded` at `error` level.

- **`routes/hub.php` carried a second, stale copy of the middleware default.**
  Its `config('hub.routes.middleware', ['api', 'auth:sanctum'])` fallback dropped
  the `throttle:60,1` that `config/hub.php` ships — and was unreachable anyway,
  since `packageBooted()` validates the same key before loading the file. The
  route file now reads the validated value and states where it is validated.

- **The published `webhook_calls` migration ships an index.**
  `alreadyProcessed()` filters `name` + `id` on every delivery, while holding the
  dedupe lock, on a table that only grows; the stub had no index at all.

  **Upgrading:** existing consumers already ran the old migration. Add the index
  yourself:

  ```php
  Schema::table('webhook_calls', function (Blueprint $table): void {
      $table->index(['name', 'id']);
  });
  ```

### Added

- `hub.routes.allow_unauthenticated` (env `EMEQ_HUB_ROUTES_ALLOW_UNAUTHENTICATED`,
  default `false`).

## [0.8.0] — 2026-08-12

Surface reduction ahead of 1.0. The package carried extension points that no
consumer uses and one dependency it never called; every one of them is a semver
contract, so they go before the contract freezes.

### Removed

- **`saloonphp/laravel-plugin` is no longer required.** The SDK never referenced
  it: no `Saloon\Laravel` import, no `Saloon::` call, in `src/` or `tests/`.
  `HubConnector` extends Saloon's framework-agnostic core and the tests mock with
  `Saloon\Http\Faking\MockClient`, also core. It was, on its own, the reason the
  package floor was Laravel 11+ (`illuminate/support: ^11.0 || ^12.39.0 ||
  ^13.0`). Consumers that want Saloon's Telescope/Pulse panels can require it
  themselves.

- **`hub.webhook.opaque_event_ids` config key** (added in 0.7.1). Hub is
  first-party, so its sentinel event ids are known at release time, not
  deployment time. The default moves to
  `ProcessHubWebhookJob::OPAQUE_EVENT_IDS`; subclasses override the constant.
  Behaviour is unchanged — `no-id` is still processed and never deduplicated.

### Changed

- **`ResolvesAccountDisplayName` is folded into `ResolvesAccountId`.** The
  interface held one nullable method, and every consumer implemented both on one
  class and bound it twice. `ResolvesAccountId` now declares
  `displayName(): ?string`; return null to let Hub name the account.
  `IntegrationController` takes one resolver instead of two.

  **Upgrading:** move `displayName()` onto your `ResolvesAccountId`
  implementation, drop `implements ResolvesAccountDisplayName`, and remove the
  second container binding.

## [0.7.1] — 2026-08-12

### Fixed

- **Webhook dedupe was scoped wider than one account.** The cache lock added in
  0.7.0 was keyed on config name + event id only, so on a cache store shared by
  all tenants one account's in-flight delivery blocked every other account's
  delivery of the same event id — and the loser is dropped as
  `concurrent_delivery`, not retried. `alreadyProcessed()` had the same gap on
  the single-DB default, where every account reads one `webhook_calls` table.
  Both halves are now keyed on account id + event id. Correctness no longer
  rests on Hub minting event ids that are unique across accounts.

  During a rolling deploy old and new workers take different lock keys, so a
  concurrent redelivery pair split across the two versions falls back to the
  unlocked check-then-act guard for the length of the rollout.

- **An event id that identifies nothing was treated as an identity.** Hub mints
  `X-Emeq-Event-Id` per delivery only when the partner supplies one; Snelstart's
  controller falls back to the literal string `no-id`, so unrelated events all
  arrive sharing it. The first one then swallowed every later one for that
  account, and concurrent ones contended on a single lock. Such values are now
  handled exactly like a missing header — processed, never deduplicated — and
  the list is configurable through `hub.webhook.opaque_event_ids` (default
  `['no-id']`) so a change on Hub's side does not need an SDK release. The raw
  header is still passed to the events for correlation.

## [0.7.0] — 2026-08-12

Architecture audit follow-up: all 23 findings from
`docs/reviews/2026-08-12-whole-repo-architecture-audit.md`, plus two blockers the
audit itself missed.

### Fixed

- **Every accounting `GET` was unusable.** `GetAccountingRequest` promoted a
  `readonly array $query`, redeclaring Saloon's non-readonly `Request::$query`,
  which is a fatal error at class load. The class sat in `phpstan excludePaths`
  and had no test, so nothing caught it. Constructor parameter renamed to
  `$queryParameters`; the exclusion is gone and the suite is clean without it.
- **A crashed webhook job suppressed its own redelivery.** `alreadyProcessed()`
  treats a row with a null `exception` as handled, but only Spatie's synchronous
  path ever wrote that column. `ProcessHubWebhookJob::failed()` now records the
  exception, with explicit `$tries` / `$backoff`.
- **Concurrent redeliveries of one event id both processed.** The dedupe guard
  now runs under a cache lock keyed on config name + event id.
- **A restored multi-DB job silently dropped its webhook.** `__unserialize()`
  rebuilds a `WebhookCall` holding only an id; the default `resolveWebhookCall()`
  returned it as-is, so the payload was null and the job logged
  `invalid_payload_in_job` and returned. It now reloads the row — after
  `bindAccountContext()`, so on the right connection.

### Changed

- **Breaking:** `HubWebhookEvent` is a backed enum and `HubWebhookEnvelope::$event`
  is an enum case. Case names are unchanged, so
  `$envelope->event === HubWebhookEvent::CONNECTION_REVOKED` still works; reading
  the event as a string needs `->value`. Unknown events decode to `UNMAPPED`.
- **Breaking:** an unresolvable account id throws `MissingConfigurationException`
  (503, catchable as `HubException`) instead of `InvalidArgumentException`.
  `Integrations::list()` documents why the catalog is account-optional.
- **Breaking:** a malformed `hub.oauth.return_path` raises
  `MissingConfigurationException` (503) instead of `ValidationException` (422) —
  it is a deployment mistake, not caller input. The `error` code is unchanged.
- **Breaking:** default route middleware is now
  `['api', 'auth:sanctum', 'throttle:60,1']`. `EMEQ_HUB_ROUTES_MIDDLEWARE` is
  comma-split and cannot express `throttle:60,1`; use a named limiter when
  overriding.
- **Breaking:** `ProcessHubWebhookJob` owns `$accountId` / `$webhookCallId` and
  `accountIdForHandle()` is gone; `SerializesHubWebhookByIds` is reduced to
  `__serialize` / `__unserialize`. Subclasses that overrode
  `accountIdForHandle()` move that logic to the constructor or
  `resolveWebhookCall()`.
- New `hub.webhook.lock_store` (`EMEQ_HUB_WEBHOOK_LOCK_STORE`). The store must
  support atomic locks — Laravel's `database` default needs the framework's
  `cache_locks` table — otherwise the job raises
  `MissingConfigurationException` rather than racing.
- Error-envelope decoding lives in one place (`Http\HubErrorResponse`); the
  response middleware and the connector's exception hook both delegate to it.
- **Breaking:** accounting collection getters (`documents()`, `bankStatements()`,
  `ledgerAccounts()`, `taxCodes()`, `customers()`, `suppliers()`) return an
  `AccountingPage` with `items` and `nextCursor` instead of a raw array. Hub
  answers these with `{data: [...], next_cursor: "…"}`; the previous union
  return type pushed that shape onto every caller.
- **Breaking:** `SpatieWebhookClientConfig::make()` takes `$signingSecret` as its
  required first argument and no longer falls back to `config()`.
- `IntegrationController` takes `Hub` and both resolver contracts as constructor
  parameters.
- Resources share an abstract `Resources\Resource` base.
- `HubWebhookHeaders::whereEventId()` owns the stored-header shape for queries.
- Untrusted JSON and consumer config are narrowed rather than cast: a non-scalar
  now reads as absent instead of becoming `"Array"`.
- Larastan raised from level 5 to 9.

### Removed

- The unreachable second `//` guard in `OAuthReturnUrl`, the empty
  `HubConnector::boot()` override, and the `HubManager` alias that named a class
  which does not exist.

### Development tooling

Nothing here ships: every path below is `export-ignore`d.

- Laravel Boost as a dev dependency, wired for a package repository. An
  `artisan` shim boots a bare app rooted at the package so Boost resolves
  `base_path()` here instead of inside `vendor/`. Guidelines, skills and MCP
  config are generated for the detected agents; app-only MCP tools are disabled
  in `config/boost.php`.
- Agent artefacts are deduplicated with symlinks: `.ai/skills/<name>` is the one
  copy of each skill, `CLAUDE.md` the one copy of the guidelines, `.mcp.json` the
  one MCP config.
- `docs/reviews/2026-08-12-whole-repo-architecture-audit.md` — the audit this
  release answers.
- `CONTEXT.md` and `docs/adr/` record the domain language, the layer rules and
  the two non-obvious decisions (provider-agnostic surface, publish-only
  migrations). Both are `export-ignore`d.

## [0.6.0] — 2026-08-12

### Changed

- **Breaking:** single publishable config — webhook wiring lives under
  `hub.webhook.{secret,name,profile,job}`. `HubServiceProvider` upserts the
  Hub entry into Spatie `webhook-client.configs` at boot.
- **Breaking:** removed publish tag `hub-webhook-client` and
  `config/webhook-client.php.stub`. `hub:install` publishes only `hub-config`
  + `hub-migrations`.
- **Breaking:** `hub.webhook_secret` → `hub.webhook.secret`.
- Multi-DB consumers override `hub.webhook.job` / `hub.webhook.profile` in
  `config/hub.php` instead of a separate Spatie config file.
- Branch alias `dev-master` → `0.6.x-dev`.
- README / AI prompt require `^0.6`.

## [0.5.1] — 2026-08-12

### Fixed

- `create_webhook_calls_table` migration stub now includes `down()` (`dropIfExists`) so consumers can roll back.

## [0.5.0] — 2026-08-12

### Added

- Publishable Spatie `webhook_calls` migration (`--tag=hub-migrations`).
- Publishable `webhook-client.php` stub (`--tag=hub-webhook-client`).
- `php artisan hub:install` (config + migrations + webhook-client + checklist).
- Domain events: `HubWebhookReceived`, `HubConnectionRevoked`, `HubWebhookIgnored`.
- Explicit `illuminate/{http,routing,database}` requirements.
- `.gitattributes` export-ignore for tests/tooling.
- Branch alias `dev-master` → `0.5.x-dev`.

### Changed

- Package short name `hub` → publish tags `hub-config` / `hub-migrations`
  (was `hub-sdk-*` on 0.4.0).
- `SpatieWebhookClientConfig` reads `config('hub.webhook_secret')` (config:cache-safe).
- Webhook dedupe uses JSON header query instead of loading all prior rows.
- `SerializesHubWebhookByIds` no longer serializes the transient queue `job`.
- Routes file renamed to `routes/hub.php`.
- README / AI prompt require `^0.5`.

## [0.4.0] — 2026-08-12

### Added

- Inbound Hub webhook helpers for Laravel consumers:
  - `HubWebhookEnvelope`, `HubWebhookHeaders`, `HubWebhookEvent`
  - `ResolvesWebhookAccount` contract
  - `HubWebhookProfile` + `ProcessHubWebhookJob` (Spatie webhook-client)
  - `SerializesHubWebhookByIds` for multi-DB queue workers
  - `SpatieWebhookClientConfig::make()` config builder
- Config: `hub.webhook_secret` (`EMEQ_HUB_WEBHOOK_SECRET`)
- Dependency: `spatie/laravel-webhook-client` ^3.4

## [0.3.0] — 2026-08-11

### Changed

- **BFF is Hub-portal-first.** Shipped routes are only:
  - `GET …/integrations` (optional status list)
  - `POST …/integrations/connect-session` (mint hosted `/connect` URL)
- Removed BFF `POST …/integrations/{provider}/connect` and
  `DELETE …/integrations/{connection}`. Connect / disconnect live on Hub’s
  hosted page — one UI for every consumer. Programmatic
  `Hub::oauth()->init()` / `Hub::connections()->delete()` remain on the facade.

### Migration

Replace in-app per-provider connect buttons with one CTA that
`POST`s `…/integrations/connect-session` and redirects to `url`.

## [0.2.1] — 2026-08-11

### Added

- `Hub::connectSessions()->create()` + BFF `POST …/integrations/connect-session`
  (Hub hosted `/connect/{account}` handoff)

## [0.2.0] — 2026-08-11

### Added

- Opt-in integration BFF routes (`EMEQ_HUB_ROUTES`): list / connect / destroy
- `ResolvesAccountDisplayName` contract for Hub account `display_name` on connect
- Config: `hub.routes.*`, `hub.oauth.return_path`

### Security / hardening

- `EMEQ_HUB_ROUTES` defaults to **false** (explicit opt-in)
- Refuse empty `hub.routes.middleware` (no unauthenticated BFF)
- Unbound `ResolvesAccountId` → JSON `503 missing_account_resolver`
- Validate `hub.oauth.return_path` (relative `/…` only; reject scheme / `//`)
- Always log Hub BFF errors; destroy only tenant-owned connections

## [0.1.0] — 2026-08-11

### Added

- Initial Laravel 13 + Saloon v4 consumer SDK for Hub `/v1`
- Provider-agnostic resources: accounts, integrations, OAuth init, connections, accounting
- Typed Hub error envelope mapping
- `ResolvesAccountId` contract for server-side tenant → Hub account binding
- README AI prompt (English) to install/configure the SDK in a Laravel consumer app
- README: API surface map, error table, pitfalls, links to Hub consumer docs (English)
