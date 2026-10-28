<?php

declare(strict_types=1);

use Emeq\HubSdk\Booking\BatchBookingResult;
use Emeq\HubSdk\Booking\BookableDocument;
use Emeq\HubSdk\Booking\BookingRunner;
use Emeq\HubSdk\Booking\Contracts\ResolvesBookableDocument;
use Emeq\HubSdk\Booking\HubDocument;
use Emeq\HubSdk\Booking\Resources\BatchResultResource;
use Emeq\HubSdk\Booking\Resources\CheckResultResource;
use Emeq\HubSdk\Events\DocumentBooked;
use Emeq\HubSdk\Events\DocumentBookingFailed;
use Emeq\HubSdk\Exceptions\DocumentNotAuthorized;
use Emeq\HubSdk\Exceptions\DocumentNotBookable;
use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Http\Request\Accounting\CreateDocumentRequest;
use Emeq\HubSdk\Http\Request\Accounting\ValidateDocumentRequest;
use Emeq\HubSdk\Testing\HubMock;
use Emeq\HubSdk\Tests\Doubles\FakeBookableDocuments;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

function sendable(array $overrides = [], ?Closure $attachments = null): BookableDocument
{
    return new BookableDocument(array_merge([
        'type' => 'sales_invoice',
        'external_id' => 'inv-1',
        'party' => ['role' => 'debtor', 'kind' => 'company', 'name' => 'Acme', 'external_id' => 'party-acme'],
        'lines' => [['description' => 'Werk', 'amount' => 100.0, 'tax_rate' => 21.0]],
    ], $overrides), $attachments);
}

function runner(array $map): BookingRunner
{
    app()->instance(ResolvesBookableDocument::class, new FakeBookableDocuments($map));

    return app(BookingRunner::class);
}

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('turns a missing document into 404 and an unauthorised one into 403', function (): void {
    $runner = runner([
        'invoice:gone' => new ModelNotFoundException,
        'invoice:theirs' => new DocumentNotAuthorized,
    ]);

    expect($runner->bookOne('invoice', 'gone')->status)->toBe(404)
        ->and($runner->bookOne('invoice', 'theirs')->status)->toBe(403)
        ->and($runner->checkOne('invoice', 'gone')->status)->toBe(404)
        ->and($runner->checkOne('invoice', 'gone')->message)->toBe('This document no longer exists.')
        ->and($runner->checkOne('invoice', 'theirs')->status)->toBe(403)
        ->and($runner->checkOne('invoice', 'theirs')->checked())->toBeFalse();
});

it('reports an unmappable document with the mapper\'s own reason', function (): void {
    $runner = runner(['invoice:draft' => new DocumentNotBookable('Invoice 7 cannot be booked: it is a draft.')]);

    $outcome = $runner->bookOne('invoice', 'draft');

    expect($outcome->status)->toBe(422)
        ->and($outcome->message)->toBe('Invoice 7 cannot be booked: it is a draft.')
        ->and($outcome->record)->toBeNull();
});

it('books what it resolves and reads the ledger row back', function (): void {
    app(HubConnector::class)->withMockClient(new MockClient([
        CreateDocumentRequest::class => HubMock::createDocument(),
    ]));

    $outcome = runner(['invoice:inv-1' => sendable()])->bookOne('invoice', 'inv-1');

    expect($outcome->booked)->toBeTrue()
        ->and($outcome->status)->toBe(200)
        ->and($outcome->record?->status)->toBe(HubDocument::STATUS_POSTED)
        ->and(HubDocument::query()->count())->toBe(1);
});

it('hands the caller what Hub reported about the relation, on the outcome and in the resource', function (): void {
    app(HubConnector::class)->withMockClient(new MockClient([
        CreateDocumentRequest::class => HubMock::createDocumentWithWarnings(),
    ]));

    $outcome = runner(['invoice:inv-1' => sendable()])->bookOne('invoice', 'inv-1');

    expect($outcome->warnings)->toHaveCount(1)
        ->and($outcome->warnings[0]['code'])->toBe('relation.matched_by_name');

    $resource = (new BatchResultResource(new BatchBookingResult('invoice', 'inv-1', $outcome)))
        ->toArray(Request::create('/'));

    expect($resource['booking']['warnings'])->toBe($outcome->warnings);
});

it('answers 503 when Hub could not be reached, so the caller may retry', function (): void {
    app(HubConnector::class)->withMockClient(new MockClient([
        CreateDocumentRequest::class => MockResponse::make([
            'error' => 'upstream_unavailable',
            'message' => 'Upstream is down.',
            'category' => 'SERVER_ERROR',
        ], 503),
    ]));

    $outcome = runner(['invoice:inv-1' => sendable()])->bookOne('invoice', 'inv-1');

    expect($outcome->mayRetry())->toBeTrue()
        ->and($outcome->status)->toBe(503)
        ->and($outcome->message)->not->toContain('Upstream is down.')
        ->and($outcome->reason)->toContain('Upstream is down.');

    $record = HubDocument::query()->sole();

    expect($record->status)->toBe(HubDocument::STATUS_UNAVAILABLE);
});

it('sends attachments only when asked', function (): void {
    $attachment = ['filename' => 'f.pdf', 'mime_type' => 'application/pdf', 'content' => 'Zm9v'];

    $mock = new MockClient([CreateDocumentRequest::class => HubMock::createDocument()]);
    app(HubConnector::class)->withMockClient($mock);

    runner(['invoice:inv-1' => sendable(attachments: fn () => [$attachment])])
        ->bookOne('invoice', 'inv-1', withAttachment: false);

    $mock->assertSent(fn (CreateDocumentRequest $request): bool => ! array_key_exists('attachments', $request->body()->all()));
});

it('hands back Hub\'s verdict on a check', function (): void {
    app(HubConnector::class)->withMockClient(new MockClient([
        ValidateDocumentRequest::class => HubMock::validateDocument(valid: false),
    ]));

    $outcome = runner(['invoice:inv-1' => sendable()])->checkOne('invoice', 'inv-1');

    expect($outcome->checked())->toBeTrue()
        ->and($outcome->status)->toBe(200)
        ->and($outcome->message)->toBeNull()
        ->and($outcome->mayRetry())->toBeFalse();

    $resource = (new CheckResultResource($outcome))->toArray(Request::create('/'));

    expect($resource['checked'])->toBeTrue()
        ->and($resource['valid'])->toBeFalse()
        ->and($resource['findings'])->not->toBeEmpty();
});

it('reports a Hub error on a check without claiming the document is wrong', function (): void {
    app(HubConnector::class)->withMockClient(new MockClient([
        ValidateDocumentRequest::class => HubMock::notFound(),
    ]));

    $outcome = runner(['invoice:inv-1' => sendable()])->checkOne('invoice', 'inv-1');

    expect($outcome->checked())->toBeFalse()
        ->and($outcome->status)->toBe(502)
        ->and($outcome->mayRetry())->toBeFalse()
        ->and($outcome->message)->toBe('Connection niet gevonden.');
});

it('separates a Hub that could not answer from a document that is wrong', function (): void {
    app(HubConnector::class)->withMockClient(new MockClient([
        ValidateDocumentRequest::class => MockResponse::make()->throw(new RuntimeException('cURL error 28')),
    ]));

    $outcome = runner(['invoice:inv-1' => sendable()])->checkOne('invoice', 'inv-1');

    expect($outcome->status)->toBe(503)
        ->and($outcome->mayRetry())->toBeTrue();
});

it('lets a throttled check be repeated instead of blaming Hub for it', function (int $status): void {
    app(HubConnector::class)->withMockClient(new MockClient([
        ValidateDocumentRequest::class => MockResponse::make([
            'error' => 'rate_limited',
            'message' => 'Too many requests.',
            'category' => 'RATE_LIMIT',
        ], $status, ['Retry-After' => '9']),
    ]));

    $outcome = runner(['invoice:inv-1' => sendable()])->checkOne('invoice', 'inv-1');

    expect($outcome->status)->toBe(503)
        ->and($outcome->mayRetry())->toBeTrue()
        ->and($outcome->retryAfter)->toBe(9)
        ->and($outcome->checked())->toBeFalse();

    $resource = (new CheckResultResource($outcome))->toArray(Request::create('/'));

    expect($resource['retry_after'])->toBe(9)
        ->and($resource['may_retry'])->toBeTrue();
})->with([429, 503]);

it('passes the wait Hub asked for through to the batch report', function (): void {
    app(HubConnector::class)->withMockClient(new MockClient([
        CreateDocumentRequest::class => MockResponse::make([
            'error' => 'rate_limited',
            'message' => 'Too many requests.',
            'category' => 'RATE_LIMIT',
        ], 429, ['Retry-After' => '30']),
    ]));

    $results = runner(['invoice:inv-1' => sendable()])
        ->book([['module' => 'invoice', 'id' => 'inv-1']]);

    expect($results[0]->outcome->retryAfter)->toBe(30);

    $resource = (new BatchResultResource($results[0]))->toArray(Request::create('/'));

    expect($resource['retry_after'])->toBe(30)
        ->and($resource['may_retry'])->toBeTrue()
        ->and($resource['status'])->toBe(503);
});

function rateLimited(array $headers = []): MockResponse
{
    return MockResponse::make([
        'code' => 'rate_limited',
        'message' => 'The bookkeeping rate budget is spent.',
    ], 429, $headers);
}

it('reports a rate-limited document as rate limited, with the wait Hub asked for or a minute', function (array $headers, int $seconds): void {
    app(HubConnector::class)->withMockClient(new MockClient([
        CreateDocumentRequest::class => rateLimited($headers),
    ]));

    $outcome = runner(['invoice:inv-1' => sendable()])->bookOne('invoice', 'inv-1');

    expect($outcome->rateLimited)->toBeTrue()
        ->and($outcome->booked)->toBeFalse()
        ->and($outcome->mayRetry())->toBeTrue()
        ->and($outcome->retryAfter)->toBe($seconds)
        ->and(HubDocument::query()->sole()->status)->toBe(HubDocument::STATUS_UNAVAILABLE);
})->with([
    'with Retry-After' => [['Retry-After' => '12'], 12],
    'without Retry-After' => [[], 60],
]);

function fiveInvoices(): array
{
    $map = [];
    $requested = [];

    foreach (['inv-1', 'inv-2', 'inv-3', 'inv-4', 'inv-5'] as $id) {
        $map["invoice:{$id}"] = sendable(['external_id' => $id]);
        $requested[] = ['module' => 'invoice', 'id' => $id];
    }

    return [$map, $requested];
}

it('stops a batch at the first rate limit and sends nothing after it', function (): void {
    Event::fake([DocumentBooked::class, DocumentBookingFailed::class]);

    $mock = new MockClient([
        HubMock::createDocument(),
        HubMock::createDocument(),
        rateLimited(['Retry-After' => '42']),
    ]);
    app(HubConnector::class)->withMockClient($mock);

    [$map, $requested] = fiveInvoices();

    $results = runner($map)->book($requested);

    $mock->assertSentCount(3);

    Event::assertDispatchedTimes(DocumentBooked::class, 2);
    Event::assertDispatchedTimes(DocumentBookingFailed::class, 3);
    Event::assertDispatched(DocumentBookingFailed::class, fn (DocumentBookingFailed $event): bool => $event->id === 'inv-5' && $event->outcome->rateLimited);

    expect($results)->toHaveCount(5)
        ->and($results[0]->outcome->booked)->toBeTrue()
        ->and($results[1]->outcome->booked)->toBeTrue()
        ->and($results[0]->outcome->retryAfter)->toBeNull()
        ->and($results[1]->outcome->retryAfter)->toBeNull()
        ->and($results[1]->outcome->rateLimited)->toBeFalse();

    foreach ([2, 3, 4] as $index) {
        expect($results[$index]->id)->toBe($requested[$index]['id'])
            ->and($results[$index]->outcome->rateLimited)->toBeTrue()
            ->and($results[$index]->outcome->booked)->toBeFalse()
            ->and($results[$index]->outcome->mayRetry())->toBeTrue()
            ->and($results[$index]->outcome->retryAfter)->toBe(42);
    }

    expect(HubDocument::query()->pluck('status', 'external_id')->all())->toBe([
        'inv-1' => HubDocument::STATUS_POSTED,
        'inv-2' => HubDocument::STATUS_POSTED,
        'inv-3' => HubDocument::STATUS_UNAVAILABLE,
    ]);
});

it('marks rate-limited rows in the batch report and leaves booked rows unmarked', function (): void {
    app(HubConnector::class)->withMockClient(new MockClient([
        HubMock::createDocument(),
        rateLimited(['Retry-After' => '42']),
    ]));

    $results = runner([
        'invoice:inv-1' => sendable(['external_id' => 'inv-1']),
        'invoice:inv-2' => sendable(['external_id' => 'inv-2']),
    ])->book([['module' => 'invoice', 'id' => 'inv-1'], ['module' => 'invoice', 'id' => 'inv-2']]);

    [$booked, $limited] = array_map(
        fn (BatchBookingResult $result): array => (new BatchResultResource($result))->toArray(Request::create('/')),
        $results,
    );

    expect($booked['rate_limited'])->toBeFalse()
        ->and($booked['booked'])->toBeTrue()
        ->and($limited['rate_limited'])->toBeTrue()
        ->and($limited['retry_after'])->toBe(42)
        ->and($limited['message'])->toBe($results[1]->outcome->message);
});

it('keeps going after an outage, since only a rate limit holds for the rest of the batch', function (): void {
    $mock = new MockClient([
        HubMock::createDocument(),
        HubMock::createDocument(),
        MockResponse::make(['error' => 'upstream_unavailable', 'message' => 'Upstream is down.', 'category' => 'SERVER_ERROR'], 503),
        HubMock::createDocument(),
        HubMock::createDocument(),
    ]);
    app(HubConnector::class)->withMockClient($mock);

    [$map, $requested] = fiveInvoices();

    $results = runner($map)->book($requested);

    $mock->assertSentCount(5);

    expect($results[2]->outcome->status)->toBe(503)
        ->and($results[2]->outcome->rateLimited)->toBeFalse()
        ->and($results[4]->outcome->booked)->toBeTrue();
});

it('retries a rate-limited document under the key it was first sent with', function (): void {
    $first = new MockClient([CreateDocumentRequest::class => rateLimited(['Retry-After' => '5'])]);
    app(HubConnector::class)->withMockClient($first);

    $runner = runner(['invoice:inv-3' => sendable(['external_id' => 'inv-3'])]);

    expect($runner->bookOne('invoice', 'inv-3')->rateLimited)->toBeTrue();

    $second = new MockClient([CreateDocumentRequest::class => HubMock::createDocument()]);
    app(HubConnector::class)->withMockClient($second);

    expect($runner->bookOne('invoice', 'inv-3')->booked)->toBeTrue()
        ->and($first->getLastRequest()->headers()->get('Idempotency-Key'))->toBe('inv-3')
        ->and($second->getLastRequest()->headers()->get('Idempotency-Key'))->toBe('inv-3');
});

it('refuses to check a document that can never be sent', function (): void {
    $outcome = runner(['invoice:draft' => new DocumentNotBookable('Invoice 7 is a draft.')])
        ->checkOne('invoice', 'draft');

    expect($outcome->status)->toBe(422)
        ->and($outcome->mayRetry())->toBeFalse()
        ->and($outcome->message)->toBe('Invoice 7 is a draft.');
});

it('stops a batch once its time budget is spent', function (): void {
    config()->set('hub.booking.batch_seconds', 0);

    $runner = runner([
        'invoice:a' => new ModelNotFoundException,
        'invoice:b' => new ModelNotFoundException,
        'invoice:c' => new ModelNotFoundException,
    ]);

    $requested = [
        ['module' => 'invoice', 'id' => 'a'],
        ['module' => 'invoice', 'id' => 'b'],
        ['module' => 'invoice', 'id' => 'c'],
    ];

    expect($runner->book($requested))->toHaveCount(1)
        ->and($runner->check($requested))->toHaveCount(1);
});

it('labels every batch result with the document it came from', function (): void {
    app(HubConnector::class)->withMockClient(new MockClient([
        CreateDocumentRequest::class => HubMock::createDocument(),
    ]));

    $results = runner([
        'invoice:inv-1' => sendable(),
        'transaction:gone' => new ModelNotFoundException,
    ])->book([
        ['module' => 'invoice', 'id' => 'inv-1'],
        ['module' => 'transaction', 'id' => 'gone'],
    ]);

    expect($results)->toHaveCount(2)
        ->and($results[0]->module)->toBe('invoice')
        ->and($results[0]->outcome->booked)->toBeTrue()
        ->and($results[1]->id)->toBe('gone')
        ->and($results[1]->outcome->status)->toBe(404);

    $resource = (new BatchResultResource($results[1]))->toArray(Request::create('/'));

    expect($resource)->toMatchArray([
        'module' => 'transaction',
        'id' => 'gone',
        'booked' => false,
        'status' => 404,
        'may_retry' => false,
        'booking' => null,
    ]);
});
