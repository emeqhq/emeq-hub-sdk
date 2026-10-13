<?php

declare(strict_types=1);

use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Exceptions\NotFoundException;
use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Http\Request\Extraction\GetRunRequest;
use Emeq\HubSdk\Http\Request\Extraction\PutHintsRequest;
use Emeq\HubSdk\Http\Request\Extraction\PutProfileVersionRequest;
use Emeq\HubSdk\Http\Request\Extraction\StartFileRunRequest;
use Emeq\HubSdk\Http\Request\Extraction\StartTextRunRequest;
use Emeq\HubSdk\Hub;
use Emeq\HubSdk\Tests\Doubles\FixedAccountId;
use Saloon\Data\MultipartValue;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('puts a profile version with its schema and instructions', function (): void {
    $mock = new MockClient([
        PutProfileVersionRequest::class => MockResponse::make(['key' => 'timesheet', 'version' => 1], 201),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    $schema = ['type' => 'object', 'properties' => ['employee' => ['type' => 'string']]];

    $profile = app(Hub::class)->extraction()->putProfileVersion('timesheet', 1, $schema, 'Dagcodes: ziek, vrij.');

    expect($profile)->toBe(['key' => 'timesheet', 'version' => 1]);

    $mock->assertSent(function (PutProfileVersionRequest $request) use ($schema): bool {
        return $request->getMethod() === Method::PUT
            && $request->resolveEndpoint() === '/extraction/profiles/timesheet/versions/1'
            && $request->body()?->all() === ['schema' => $schema, 'instructions' => 'Dagcodes: ziek, vrij.'];
    });
});

it('treats an unchanged profile version as success and leaves out absent instructions', function (): void {
    $mock = new MockClient([
        PutProfileVersionRequest::class => MockResponse::make(['key' => 'timesheet', 'version' => 2], 200),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    $profile = app(Hub::class)->extraction()->putProfileVersion('timesheet', 2, ['type' => 'object']);

    expect($profile)->toBe(['key' => 'timesheet', 'version' => 2]);

    $mock->assertSent(function (PutProfileVersionRequest $request): bool {
        return $request->body()?->all() === ['schema' => ['type' => 'object']];
    });
});

it('puts the account hints with the bound account header', function (): void {
    app()->bind(ResolvesAccountId::class, fn (): ResolvesAccountId => new FixedAccountId('school1'));

    $mock = new MockClient([
        PutHintsRequest::class => MockResponse::make(['hints' => 'FK = Fatima Karim.']),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    $hints = app(Hub::class)->extraction()->putHints('FK = Fatima Karim.');

    expect($hints)->toBe(['hints' => 'FK = Fatima Karim.'])
        ->and($mock->getLastPendingRequest()?->headers()->get('X-Account-Id'))->toBe('school1');

    $mock->assertSent(function (PutHintsRequest $request): bool {
        return $request->getMethod() === Method::PUT
            && $request->resolveEndpoint() === '/extraction/hints'
            && $request->body()?->all() === ['hints' => 'FK = Fatima Karim.'];
    });
});

it('clears the hints by sending null for an explicit account', function (): void {
    $mock = new MockClient([
        PutHintsRequest::class => MockResponse::make(['hints' => null]),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    app(Hub::class)->extraction()->putHints(null, accountId: 'school2');

    expect($mock->getLastPendingRequest()?->headers()->get('X-Account-Id'))->toBe('school2');

    $mock->assertSent(function (PutHintsRequest $request): bool {
        return $request->body()?->all() === ['hints' => null];
    });
});

it('starts a text run with the caller idempotency key and the account header', function (): void {
    $mock = new MockClient([
        StartTextRunRequest::class => MockResponse::make(['run_id' => '01JABCD', 'status' => 'queued'], 202),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    $run = app(Hub::class)->extraction()->runText('timesheet', 1, 'Fatima ma 8 di 8', 'run-42', accountId: 'school1');

    expect($run)->toBe(['run_id' => '01JABCD', 'status' => 'queued'])
        ->and($mock->getLastPendingRequest()?->headers()->get('Idempotency-Key'))->toBe('run-42')
        ->and($mock->getLastPendingRequest()?->headers()->get('X-Account-Id'))->toBe('school1');

    $mock->assertSent(function (StartTextRunRequest $request): bool {
        return $request->getMethod() === Method::POST
            && $request->resolveEndpoint() === '/extraction/runs'
            && $request->body()?->all() === ['profile' => 'timesheet', 'version' => 1, 'text' => 'Fatima ma 8 di 8'];
    });
});

it('starts a file run as multipart with profile, version and the named file', function (): void {
    app()->bind(ResolvesAccountId::class, fn (): ResolvesAccountId => new FixedAccountId('school1'));

    $mock = new MockClient([
        StartFileRunRequest::class => MockResponse::make(['run_id' => '01JABCE', 'status' => 'queued'], 202),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    $run = app(Hub::class)->extraction()->runFile('timesheet', 1, '%PDF-1.7 weekstaat', 'weekstaat.pdf', 'run-43');

    $pending = $mock->getLastPendingRequest();

    expect($run)->toBe(['run_id' => '01JABCE', 'status' => 'queued'])
        ->and($pending?->headers()->get('Idempotency-Key'))->toBe('run-43')
        ->and($pending?->headers()->get('X-Account-Id'))->toBe('school1')
        ->and($pending?->headers()->get('Content-Type'))->toStartWith('multipart/form-data; boundary=');

    $psr = $pending?->createPsrRequest();
    $boundary = str_replace('multipart/form-data; boundary=', '', (string) $psr?->getHeaderLine('Content-Type'));

    expect((string) $psr?->getBody())
        ->toContain('--'.$boundary)
        ->toContain('name="file"; filename="weekstaat.pdf"');

    $mock->assertSent(function (StartFileRunRequest $request): bool {
        $parts = array_map(
            fn (MultipartValue $part): array => [$part->name, $part->value, $part->filename],
            $request->body()->all(),
        );

        return $request->getMethod() === Method::POST
            && $request->resolveEndpoint() === '/extraction/runs'
            && $parts === [
                ['profile', 'timesheet', null],
                ['version', '1', null],
                ['file', '%PDF-1.7 weekstaat', 'weekstaat.pdf'],
            ];
    });
});

it('gets a completed run with its result under the bound account', function (): void {
    app()->bind(ResolvesAccountId::class, fn (): ResolvesAccountId => new FixedAccountId('school1'));

    $body = [
        'run_id' => '01JABCD',
        'status' => 'completed',
        'failure_reason' => null,
        'profile' => 'timesheet',
        'version' => 1,
        'readers' => ['ministral-14b-latest'],
        'billable_pages' => 1,
        'result' => ['employee' => 'Fatima', 'days' => [8, 8]],
    ];

    $mock = new MockClient([
        GetRunRequest::class => MockResponse::make($body),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    $run = app(Hub::class)->extraction()->getRun('01JABCD');

    expect($run)->toBe($body)
        ->and($mock->getLastPendingRequest()?->headers()->get('X-Account-Id'))->toBe('school1');

    $mock->assertSent(function (GetRunRequest $request): bool {
        return $request->getMethod() === Method::GET
            && $request->resolveEndpoint() === '/extraction/runs/01JABCD';
    });
});

it('gets a queued run without result fields for an explicit account', function (): void {
    $body = [
        'run_id' => '01JABCE',
        'status' => 'queued',
        'failure_reason' => null,
        'profile' => 'timesheet',
        'version' => 1,
        'readers' => null,
        'billable_pages' => null,
    ];

    $mock = new MockClient([
        GetRunRequest::class => MockResponse::make($body),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    $run = app(Hub::class)->extraction()->getRun('01JABCE', accountId: 'school2');

    expect($run)->toBe($body)
        ->and($run)->not->toHaveKey('result')
        ->and($mock->getLastPendingRequest()?->headers()->get('X-Account-Id'))->toBe('school2');
});

it('throws not found for a run of another account', function (): void {
    $mock = new MockClient([
        GetRunRequest::class => MockResponse::make([
            'error' => 'run_not_found',
            'message' => 'Run niet gevonden voor dit Account.',
        ], 404),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    expect(fn () => app(Hub::class)->extraction()->getRun('01JOTHER', accountId: 'school1'))
        ->toThrow(NotFoundException::class);
});
