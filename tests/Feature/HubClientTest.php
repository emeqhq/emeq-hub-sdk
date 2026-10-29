<?php

declare(strict_types=1);

use Emeq\HubSdk\Exceptions\AuthenticationException;
use Emeq\HubSdk\Exceptions\DocumentDeleteRejected;
use Emeq\HubSdk\Exceptions\HubException;
use Emeq\HubSdk\Exceptions\MissingConfigurationException;
use Emeq\HubSdk\Exceptions\NotFoundException;
use Emeq\HubSdk\Exceptions\RateLimitException;
use Emeq\HubSdk\Exceptions\ValidationException;
use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Http\Request\Accounting\DeleteDocumentRequest;
use Emeq\HubSdk\Http\Request\Accounting\GetAccountingRequest;
use Emeq\HubSdk\Http\Request\ConnectSessions\CreateConnectSessionRequest;
use Emeq\HubSdk\Http\Request\Integrations\ListIntegrationsRequest;
use Emeq\HubSdk\Http\Request\OAuth\InitOAuthRequest;
use Emeq\HubSdk\Hub;
use Emeq\HubSdk\Support\SdkIdentity;
use Emeq\HubSdk\Testing\HubMock;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('lists integrations without hardcoding providers', function (): void {
    $mock = new MockClient([
        ListIntegrationsRequest::class => MockResponse::make([
            [
                'key' => 'exact',
                'label' => 'Exact Online',
                'connectable' => true,
                'status' => 'disconnected',
            ],
            [
                'key' => 'moneybird',
                'label' => 'Moneybird',
                'connectable' => true,
                'status' => 'disconnected',
            ],
        ], 200),
    ]);

    $connector = app(HubConnector::class);
    $connector->withMockClient($mock);

    $list = app(Hub::class)->integrations()->list('tenant-1');

    expect($list)->toHaveCount(2)
        ->and($list[1]['key'])->toBe('moneybird');

    $mock->assertSent(function (ListIntegrationsRequest $request): bool {
        return $request->query()->all() === ['account_external_id' => 'tenant-1'];
    });
});

it('accepts any provider string on oauth init (no SDK allowlist)', function (): void {
    $mock = new MockClient([
        InitOAuthRequest::class => MockResponse::make([
            'connection_id' => '99',
            'redirect_url' => 'https://partner.example/consent',
        ], 200),
    ]);

    $connector = app(HubConnector::class);
    $connector->withMockClient($mock);

    $result = app(Hub::class)->oauth()->init('future-partner', accountExternalId: 'tenant-1');

    expect($result['redirect_url'])->toBe('https://partner.example/consent');

    $mock->assertSent(function (InitOAuthRequest $request): bool {
        return $request->resolveEndpoint() === '/oauth/future-partner/init';
    });
});

it('maps hub error envelope to typed exceptions', function (): void {
    $mock = new MockClient([
        ListIntegrationsRequest::class => MockResponse::make([
            'error' => 'unauthenticated',
            'category' => 'AUTHENTICATION_ERROR',
            'message' => 'Missing token',
            'request_id' => 'req_1',
        ], 401),
    ]);

    $connector = app(HubConnector::class);
    $connector->withMockClient($mock);

    try {
        app(Hub::class)->integrations()->list();
        $this->fail('Expected AuthenticationException');
    } catch (AuthenticationException $e) {
        expect($e->error)->toBe('unauthenticated')
            ->and($e->requestId)->toBe('req_1')
            ->and($e->status)->toBe(401);
    }
});

it('maps validation errors', function (): void {
    $mock = new MockClient([
        InitOAuthRequest::class => MockResponse::make([
            'error' => 'validation_error',
            'category' => 'VALIDATION_ERROR',
            'message' => 'The external id field is required.',
            'request_id' => 'req_2',
        ], 422),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    expect(fn () => app(Hub::class)->oauth()->init('exact', 'tenant-1'))
        ->toThrow(ValidationException::class);
});

it('sends accounting list requests with account header and query', function (): void {
    $mock = new MockClient([
        GetAccountingRequest::class => HubMock::documents(),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    $page = app(Hub::class)->accounting()->documents(['type' => 'sales_invoice'], 'tenant-1');

    expect($page->items)->toBe(HubMock::fixture('documents')['data']);

    $mock->assertSent(function (GetAccountingRequest $request): bool {
        return $request->resolveEndpoint() === '/accounting/documents'
            && $request->query()->all() === ['type' => 'sales_invoice'];
    });
});

it('keeps the cursor from a paginated accounting collection', function (): void {
    $mock = new MockClient([
        GetAccountingRequest::class => HubMock::ledgerAccounts(),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    $page = app(Hub::class)->accounting()->ledgerAccounts([], 'tenant-1');

    $fixture = HubMock::fixture('ledger-accounts');

    expect($page->items)->toBe($fixture['data'])
        ->and($page->nextCursor)->toBe($fixture['next_cursor'])
        ->and($page->hasMore())->toBeTrue();
});

it('reports the last page as having no cursor', function (): void {
    $mock = new MockClient([
        GetAccountingRequest::class => HubMock::customers(),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    $page = app(Hub::class)->accounting()->customers([], 'tenant-1');

    expect($page->nextCursor)->toBeNull()
        ->and($page->hasMore())->toBeFalse();
});

it('throws a catchable HubException when no account id can be resolved', function (): void {
    try {
        app(Hub::class)->accounting()->documents();
        $this->fail('Expected MissingConfigurationException');
    } catch (HubException $e) {
        expect($e)->toBeInstanceOf(MissingConfigurationException::class)
            ->and($e->error)->toBe('missing_account_id')
            ->and($e->category)->toBe('CONFIGURATION_ERROR');
    }
});

it('lists the integrations catalog without an account id', function (): void {
    $mock = new MockClient([
        ListIntegrationsRequest::class => MockResponse::make([
            ['key' => 'exact', 'label' => 'Exact Online', 'connectable' => true],
        ], 200),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    expect(app(Hub::class)->integrations()->list())->toHaveCount(1);

    $mock->assertSent(function (ListIntegrationsRequest $request): bool {
        return $request->query()->all() === [];
    });
});

it('is mockable from a consumer app through a global mock client on url patterns', function (): void {
    $mock = MockClient::global([
        '*/v1/accounting/documents' => MockResponse::make(['id' => 'doc_1'], 201),
    ]);

    $document = ['type' => 'sales_invoice', 'external_id' => 'invoice-42'];

    $created = app(Hub::class)->accounting()->createDocument(
        $document,
        idempotencyKey: $document['external_id'],
        accountId: 'tenant-1',
    );

    expect($created)->toBe(['id' => 'doc_1']);

    expect($mock->getLastPendingRequest()?->headers()->get('Idempotency-Key'))
        ->toBe('invoice-42');
});

it('maps a rate-limited booking to the wait Hub asked for, or a minute without one', function (array $headers, int $seconds): void {
    MockClient::global([
        '*/v1/accounting/documents' => MockResponse::make([
            'code' => 'rate_limited',
            'message' => 'The bookkeeping rate budget is spent.',
        ], 429, $headers),
    ]);

    try {
        app(Hub::class)->accounting()->createDocument(['type' => 'sales_invoice', 'external_id' => 'inv-1'], 'inv-1', 'tenant-1');
    } catch (RateLimitException $e) {
        expect($e->error)->toBe('rate_limited')
            ->and($e->retryAfterSeconds())->toBe($seconds);

        return;
    }

    $this->fail('Expected a RateLimitException.');
})->with([
    'with Retry-After' => [['Retry-After' => '12'], 12],
    'without Retry-After' => [[], 60],
]);

it('maps unknown provider as not found when hub returns 404', function (): void {
    $mock = new MockClient([
        InitOAuthRequest::class => MockResponse::make([
            'error' => 'unknown_provider',
            'category' => 'NOT_FOUND',
            'message' => 'Unknown provider',
            'request_id' => 'req_3',
        ], 404),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    expect(fn () => app(Hub::class)->oauth()->init('nope', 'tenant-1'))
        ->toThrow(NotFoundException::class);
});

it('carries the field-level messages a rejected document came back with', function (): void {
    $mock = new MockClient([
        InitOAuthRequest::class => MockResponse::make([
            'error' => 'validation_error',
            'category' => 'VALIDATION_ERROR',
            'retryable' => false,
            'message' => 'The given data was invalid.',
            'request_id' => 'req_3',
            'errors' => [
                'party.vat_number' => ['Geen geldig btw-nummer.'],
                'lines.0.amount' => ['Bedrag is verplicht.', 'Bedrag moet numeriek zijn.'],
            ],
        ], 422),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    try {
        app(Hub::class)->oauth()->init('exact', 'tenant-1');
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->retryable)->toBeFalse()
            ->and($e->validationErrors)->toBe([
                'party.vat_number' => ['Geen geldig btw-nummer.'],
                'lines.0.amount' => ['Bedrag is verplicht.', 'Bedrag moet numeriek zijn.'],
            ]);
    }
});

it('treats a Hub that never sent retryable as having no opinion', function (): void {
    $mock = new MockClient([
        InitOAuthRequest::class => MockResponse::make([
            'error' => 'validation_error',
            'category' => 'VALIDATION_ERROR',
            'message' => 'Nope.',
        ], 422),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    try {
        app(Hub::class)->oauth()->init('exact', 'tenant-1');
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->retryable)->toBeNull()
            ->and($e->validationErrors)->toBe([]);
    }
});

it('hands the consumer log the value that ties a failure to a Hub log line', function (): void {
    $mock = new MockClient([
        ListIntegrationsRequest::class => MockResponse::make([
            'error' => 'unauthenticated',
            'category' => 'AUTHENTICATION_ERROR',
            'message' => 'Missing token',
            'request_id' => 'req_4',
        ], 401),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    try {
        app(Hub::class)->integrations()->list();
        $this->fail('Expected AuthenticationException');
    } catch (HubException $e) {
        expect($e->context())->toBe([
            'hub_request_id' => 'req_4',
            'hub_error' => 'unauthenticated',
            'hub_category' => 'AUTHENTICATION_ERROR',
            'hub_status' => 401,
        ]);
    }
});

it('reads base url and PAT when it sends, not when the container built it', function (): void {
    $connector = app(HubConnector::class);

    expect($connector->resolveBaseUrl())->toBe('https://hub.example.test/v1');

    config()->set('hub.base_url', 'https://hub-tenant-b.example.test/');
    config()->set('hub.pat', 'tenant-b-pat');
    config()->set('hub.timeout', 45);

    expect(app(HubConnector::class))->toBe($connector)
        ->and($connector->resolveBaseUrl())->toBe('https://hub-tenant-b.example.test/v1')
        ->and($connector->config()->get('timeout'))->toBe(45);

    $mock = new MockClient([ListIntegrationsRequest::class => MockResponse::make([], 200)]);
    $connector->withMockClient($mock);

    app(Hub::class)->integrations()->list('tenant-b');

    expect($mock->getLastPendingRequest()->headers()->get('Authorization'))->toBe('Bearer tenant-b-pat');
});

it('names itself and its version on every request it sends', function (): void {
    $mock = new MockClient([ListIntegrationsRequest::class => MockResponse::make([], 200)]);
    app(HubConnector::class)->withMockClient($mock);

    app(Hub::class)->integrations()->list('tenant-1');

    $headers = $mock->getLastPendingRequest()->headers();

    expect($headers->get('User-Agent'))->toStartWith('emeq-hub-sdk/')
        ->and($headers->get('User-Agent'))->toContain(' php/'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION)
        ->and($headers->get('User-Agent'))->toContain(' laravel/')
        ->and($headers->get(SdkIdentity::VERSION_HEADER))->toBe(SdkIdentity::version())
        ->and($headers->get(SdkIdentity::VERSION_HEADER))->not->toBe('unknown');
});

it('sends categories, mode and actor on a connect session when given', function (): void {
    $mock = new MockClient([
        CreateConnectSessionRequest::class => MockResponse::make(['url' => 'https://hub.example.test/connect'], 200),
    ]);
    app(HubConnector::class)->withMockClient($mock);

    app(Hub::class)->connectSessions()->create(
        accountExternalId: 'tenant-1',
        categories: [['key' => 'fuel', 'label' => 'Brandstof', 'type' => 'expense']],
        mode: 'view',
        actor: ['name' => 'Jan', 'email' => 'jan@example.test'],
    );

    $mock->assertSent(fn (CreateConnectSessionRequest $request): bool => $request->body()->all() === [
        'account_external_id' => 'tenant-1',
        'categories' => [['key' => 'fuel', 'label' => 'Brandstof', 'type' => 'expense']],
        'mode' => 'view',
        'actor' => ['name' => 'Jan', 'email' => 'jan@example.test'],
    ]);
});

it('leaves categories, mode and actor out of a connect session when not given', function (): void {
    $mock = new MockClient([
        CreateConnectSessionRequest::class => MockResponse::make(['url' => 'https://hub.example.test/connect'], 200),
    ]);
    app(HubConnector::class)->withMockClient($mock);

    app(Hub::class)->connectSessions()->create(accountExternalId: 'tenant-1');

    $mock->assertSent(fn (CreateConnectSessionRequest $request): bool => $request->body()->all() === [
        'account_external_id' => 'tenant-1',
    ]);
});

it('deletes a booked document by type and external id', function (): void {
    $mock = new MockClient([
        DeleteDocumentRequest::class => MockResponse::make('', 204),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    app(Hub::class)->accounting()->deleteDocument('INV-2026-001', 'sales_invoice', 'tenant-1');

    $mock->assertSent(function (DeleteDocumentRequest $request): bool {
        return $request->getMethod() === Method::DELETE
            && $request->resolveEndpoint() === '/accounting/documents/INV-2026-001'
            && $request->query()->all() === ['type' => 'sales_invoice']
            && $request->headers()->get('X-Account-Id') === 'tenant-1';
    });
});

it('encodes a slash in the external id', function (): void {
    $mock = new MockClient([
        DeleteDocumentRequest::class => MockResponse::make('', 204),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    app(Hub::class)->accounting()->deleteDocument('INV/2026/001', 'credit_note', 'tenant-1');

    $mock->assertSent(function (DeleteDocumentRequest $request): bool {
        return $request->resolveEndpoint() === '/accounting/documents/INV%2F2026%2F001';
    });
});

it('throws not found for an unknown or already deleted document', function (): void {
    $mock = new MockClient([
        DeleteDocumentRequest::class => MockResponse::make([
            'error' => 'document_not_found',
            'message' => 'Document not found.',
        ], 404),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    try {
        app(Hub::class)->accounting()->deleteDocument('INV-2026-001', 'sales_invoice', 'tenant-1');
        test()->fail('Expected NotFoundException');
    } catch (NotFoundException $e) {
        expect($e->error)->toBe('document_not_found')
            ->and($e->status)->toBe(404);
    }
});

it('exposes the upstream reason when the package rejects the delete', function (): void {
    $mock = new MockClient([
        DeleteDocumentRequest::class => MockResponse::make([
            'external_id' => 'INV-2026-001',
            'error' => 'document_delete_rejected',
            'message' => 'Het boekhoudpakket weigerde de boeking te verwijderen. De boeking staat er nog.',
            'upstream_error' => 'Entry is reconciled.',
            'upstream_status' => 400,
        ], 409),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    try {
        app(Hub::class)->accounting()->deleteDocument('INV-2026-001', 'sales_invoice', 'tenant-1');
        test()->fail('Expected DocumentDeleteRejected');
    } catch (DocumentDeleteRejected $e) {
        expect($e->error)->toBe('document_delete_rejected')
            ->and($e->status)->toBe(409)
            ->and($e->upstreamError)->toBe('Entry is reconciled.')
            ->and($e->upstreamStatus)->toBe(400);
    }
});

it('leaves the upstream fields empty when hub omits or mistypes them', function (): void {
    $mock = new MockClient([
        DeleteDocumentRequest::class => MockResponse::make([
            'error' => 'document_delete_rejected',
            'message' => 'Het boekhoudpakket weigerde de boeking te verwijderen. De boeking staat er nog.',
            'upstream_status' => '400',
        ], 409),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    try {
        app(Hub::class)->accounting()->deleteDocument('INV-2026-001', 'sales_invoice', 'tenant-1');
        test()->fail('Expected DocumentDeleteRejected');
    } catch (DocumentDeleteRejected $e) {
        expect($e->upstreamError)->toBeNull()
            ->and($e->upstreamStatus)->toBeNull();
    }
});
