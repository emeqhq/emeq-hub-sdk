# Hub API coverage

Which `/v1/*` endpoints of emeq-hub this SDK wraps, and which it does not yet.
This doubles as a progress doc: every ⬜ row is backlog.

**As of** 2026-10-13 · **Hub** `a12d7ea` · **SDK** `0.31.0`

Re-checked against Hub `a12d7ea`. Since the stamp before last (`9c1561e`) the
Hub added two provider surfaces behind their own feature flags: iTheorie (6
routes, wrapped in 0.28.0) and document extraction (4 routes: 3 wrapped in
0.30.0, `GET /v1/extraction/runs/{run}` in 0.31.0). 0.30.0 also wrapped
`POST /v1/connections`, which extraction needs to switch on per
Account. Hub also has a signed, non-`/v1` surface
for its own connect drawer (`/connect/{account}/{provider}/manage*`); it is not
client API and is deliberately absent from this table.

Two gaps worth naming, neither of them a missing wrapper:

- **`X-Connection-Id` is unreachable.** Hub requires it once an Account has more
  than one accounting connection and answers `409 multiple_accounting_connections`
  with a `connections[]` list otherwise. No request class here sends the header,
  so such an account cannot book through this SDK. (`hub.booking.connection` is a
  *database* connection — unrelated, and easy to confuse.)
- **`candidates[]` and `connections[]` are dropped.** `HubException` parses only
  the 422 field bag, so the list Hub sends with `409 relation_ambiguous` — the one
  a user needs to resolve it — does not survive.

The Hub publishes its own OpenAPI at <https://hub.emeq.nl/docs/api>. That is the
server side and says nothing about what this SDK supports — hence this document.

## Contents

- [Refreshing it](#refreshing-it)
- [Legend](#legend)
- [Status](#status)
- [Platform](#platform)
- [Accounts and connections](#accounts-and-connections)
- [Accounting](#accounting)
- [Exact pass-through](#exact-pass-through)
- [iTheorie](#itheorie)
- [Document extraction](#document-extraction)
- [Billing](#billing)
- [Reaching what is not wrapped](#reaching-what-is-not-wrapped)
- [Suggested order](#suggested-order)

## Refreshing it

```bash
# Hub side: every route
docker compose exec app php artisan route:list --path=v1

# SDK side: what we actually call
grep -rn -A 3 'function resolveEndpoint' src/Http/Request
```

## Legend

| | |
|---|---|
| ✅ | wrapped, reachable through a resource method |
| ⬜ | not wrapped — reachable through `Hub::connector()` |
| — | deliberately out of scope (not client surface) |

## Status

Scoped to the providers a consumer app uses through this SDK: Exact, iTheorie
and document extraction. The Hub also exposes a Mollie surface (payments,
Connect and account subscriptions), a Snelstart pass-through and a DataForSEO
surface; they sit behind their own feature flags and are left out here until
they are actually in use.

| Area | Endpoints | Wrapped |
|---|---:|---:|
| Platform | 1 | 0 |
| Accounts and connections | 8 | 8 |
| Accounting | 13 | 13 |
| Exact pass-through | 5 | 0 |
| Billing | 1 | 0 |
| iTheorie | 6 | 6 |
| Document extraction | 4 | 4 |
| **Total (in scope)** | **38** | **31** |

Counts exclude the OAuth callbacks (browser redirects, listed below for
completeness) and `/v1/admin/billing/*` (Emeq-internal, behind `emeq.admin`).

## Platform

| | Endpoint | Ability | SDK | |
|---|---|---|---|---|
| GET | `/v1/ping` | — | | ⬜ |

## Accounts and connections

The order a consumer app walks these: create the account → show available
providers → connect session or OAuth init → read connection state.

| | Endpoint | Ability | SDK | |
|---|---|---|---|---|
| POST | `/v1/accounts` | — | `accounts()->create()` | ✅ |
| GET | `/v1/integrations` | `integrations:manage` \| `consumer:manage-accounts` | `integrations()->list()` | ✅ |
| POST | `/v1/connect-sessions` | `integrations:manage` \| `consumer:manage-accounts` | `connectSessions()->create()` | ✅ |
| POST | `/v1/oauth/{provider}/init` | `integrations:manage` | `oauth()->init()` | ✅ |
| POST | `/v1/oauth/exact/init` | `integrations:manage` \| `exact:write` | via `oauth()->init('exact')` | ✅ |
| POST | `/v1/connections` | — | `connections()->create()` | ✅ |
| GET | `/v1/connections/{connection}` | — | `connections()->get()` | ✅ |
| DELETE | `/v1/connections/{connection}` | — | `connections()->delete()` | ✅ |
| GET | `/v1/oauth/exact/callback` | public | | — |

OAuth providers do not need `connections()->create()`, because `init` creates
the row itself. It is for providers without an OAuth flow, such as `extraction`.

## Accounting

Provider-independent, and fully covered. Abilities are enforced in the
controllers rather than on the route: reads want `accounting:read` (or
`:write`), writes want `accounting:write`.

| | Endpoint | Ability | SDK | |
|---|---|---|---|---|
| POST | `/v1/accounting/documents` | `accounting:write` | `accounting()->createDocument()` | ✅ |
| POST | `/v1/accounting/documents/validate` | `accounting:read` | `accounting()->validateDocument()` | ✅ |
| GET | `/v1/accounting/documents` | `accounting:read` | `accounting()->documents()` | ✅ |
| GET | `/v1/accounting/bank-statements` | `accounting:read` | `accounting()->bankStatements()` | ✅ |
| GET | `/v1/accounting/ledger-accounts` | `accounting:read` | `accounting()->ledgerAccounts()` | ✅ |
| GET | `/v1/accounting/tax-codes` | `accounting:read` | `accounting()->taxCodes()` | ✅ |
| GET | `/v1/accounting/customers` | `accounting:read` | `accounting()->customers()` | ✅ |
| GET | `/v1/accounting/suppliers` | `accounting:read` | `accounting()->suppliers()` | ✅ |
| GET | `/v1/accounting/capabilities` | `accounting:read` | `accounting()->capabilities()` | ✅ |
| GET | `/v1/accounting/reference-data` | `accounting:read` | `accounting()->referenceData()` | ✅ |
| GET | `/v1/accounting/mapping` | `accounting:read` | `accounting()->mapping()` | ✅ |
| PUT | `/v1/accounting/mapping` | `accounting:write` | `accounting()->updateMapping()` | ✅ |
| POST | `/v1/accounting/sync` | `accounting:write` | `accounting()->sync()` | ✅ |

`POST /v1/accounting/documents` requires an `Idempotency-Key`, which is why
`createDocument()` takes it as a required argument. The six list endpoints
paginate by cursor and return an `AccountingPage`.

## Exact pass-through

Provider-specific and division-aware. All of it behind the `exact` feature flag.

| | Endpoint | Ability | SDK | |
|---|---|---|---|---|
| GET | `/v1/exact/gl-accounts` | `exact:read` \| `exact:write` | | ⬜ |
| GET | `/v1/exact/vat-codes` | `exact:read` \| `exact:write` | | ⬜ |
| GET | `/v1/exact/relations` | `exact:read` \| `exact:write` | | ⬜ |
| GET | `/v1/exact/journals` | `exact:read` \| `exact:write` | | ⬜ |
| GET | `/v1/exact/{path}` | `exact:read` \| `exact:write` | | ⬜ |

The first four overlap functionally with `ledger-accounts`, `tax-codes` and
`customers`/`suppliers` from the accounting layer. Anything that wants to stay
provider-independent should use those; this route exists for Exact-specific
fields.

## iTheorie

Behind the `itheorie` feature flag. `POST /purchases` requires an
`Idempotency-Key` and has its own throttle.

| | Endpoint | Ability | SDK | |
|---|---|---|---|---|
| GET | `/v1/itheorie/courses` | `itheorie:read` \| `itheorie:write` | `itheorie()->courses()` | ✅ |
| GET | `/v1/itheorie/courses/{course}` | `itheorie:read` \| `itheorie:write` | `itheorie()->course()` | ✅ |
| POST | `/v1/itheorie/purchases` | `itheorie:write` | `itheorie()->createPurchase()` | ✅ |
| GET | `/v1/itheorie/purchases/{purchase}` | `itheorie:read` \| `itheorie:write` | `itheorie()->purchase()` | ✅ |
| GET | `/v1/itheorie/students/{accessCode}` | `itheorie:read` \| `itheorie:write` | `itheorie()->student()` | ✅ |
| GET | `/v1/itheorie/students/{accessCode}/detailed` | `itheorie:read` \| `itheorie:write` | `itheorie()->studentDetailed()` | ✅ |

## Document extraction

Behind the `extraction` feature flag. Switch it on per Account with
`connections()->create($accountId, 'extraction')`. A run answers `202` with a
`run_id`; Hub sends `extraction.run.completed` or `extraction.run.failed` when
it ends, and `GET` on the run returns its status and, for 24 hours, the result.

| | Endpoint | Ability | SDK | |
|---|---|---|---|---|
| PUT | `/v1/extraction/profiles/{key}/versions/{version}` | `extraction:write` | `extraction()->putProfileVersion()` | ✅ |
| POST | `/v1/extraction/runs` | `extraction:write` | `extraction()->runText()` / `runFile()` | ✅ |
| PUT | `/v1/extraction/hints` | `extraction:write` | `extraction()->putHints()` | ✅ |
| GET | `/v1/extraction/runs/{run}` | `extraction:write` | `extraction()->getRun()` | ✅ |

## Billing

| | Endpoint | Ability | SDK | |
|---|---|---|---|---|
| GET | `/v1/billing/subscription` | `billing:read` \| `billing:write` | | ⬜ |

## Reaching what is not wrapped

`Hub::connector()` is public, so every unwrapped endpoint is reachable without
waiting for a release:

```php
use Saloon\Enums\Method;
use Saloon\Http\Request;

$response = Hub::connector()->send(new class('acme-42') extends Request {
    protected Method $method = Method::GET;

    public function __construct(private readonly string $accountId) {}

    public function resolveEndpoint(): string
    {
        return '/exact/gl-accounts';
    }

    protected function defaultHeaders(): array
    {
        return ['X-Account-Id' => $this->accountId];
    }
});
```

Mind that header. `X-Account-Id` does not live on the connector; resources set it
per request through `HasAccountIdHeader`. Leave it out and the Hub cannot resolve
an Account, and therefore no Connection — which affects everything under
`/accounting/*` and `/exact/*`.

Error handling does carry over: `MapHubErrors` is registered as response
middleware on the connector (`HubConnector.php:28`), so a raw request throws the
same exception tree a wrapped one does.

## Suggested order

1. `GET /v1/billing/subscription` — one endpoint, and the consumer app needs to
   know whether a subscription is active anyway
2. Exact pass-through — deliberately last: anyone who needs these wants
   provider-specific behaviour by definition, and is better served by
   `connector()` than by a wrapper that implies provider neutrality
