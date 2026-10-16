<?php

declare(strict_types=1);

use Emeq\HubSdk\Exceptions\AuthenticationException;
use Emeq\HubSdk\Exceptions\HubException;
use Emeq\HubSdk\Exceptions\NotFoundException;
use Emeq\HubSdk\Exceptions\PurchaseInFlight;
use Emeq\HubSdk\Exceptions\ValidationException;
use Emeq\HubSdk\Hub;
use Emeq\HubSdk\Resources\Finding;
use Emeq\HubSdk\Testing\HubMock;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

const ACCOUNT = 'tenant-1';

afterEach(function (): void {
    MockClient::destroyGlobal();
});

/** @return array<string, mixed> */
function mockedBody(MockResponse $response): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) $response->body(), true, flags: JSON_THROW_ON_ERROR);

    return $decoded;
}

it('answers every accounting read from the captured fixtures', function (): void {
    MockClient::global(HubMock::accounting());

    $accounting = app(Hub::class)->accounting();

    expect($accounting->capabilities(ACCOUNT))->toBe(HubMock::fixture('capabilities'))
        ->and($accounting->referenceData(ACCOUNT))->toBe(HubMock::fixture('reference-data'))
        ->and($accounting->mapping(ACCOUNT))->toBe(HubMock::fixture('mapping'));

    expect($accounting->documents(['type' => 'sales_invoice'], ACCOUNT)->items)
        ->toBe(HubMock::fixture('documents')['data']);
});

it('serves collection reads as pages that keep their cursor', function (): void {
    MockClient::global(HubMock::accounting());

    $accounting = app(Hub::class)->accounting();

    $ledger = $accounting->ledgerAccounts([], ACCOUNT);
    expect($ledger->hasMore())->toBeTrue()
        ->and($ledger->nextCursor)->toBe(HubMock::fixture('ledger-accounts')['next_cursor']);

    expect($accounting->customers([], ACCOUNT)->hasMore())->toBeFalse()
        ->and($accounting->suppliers([], ACCOUNT)->items)->toBe(HubMock::fixture('suppliers')['data'])
        ->and($accounting->bankStatements([], ACCOUNT)->items)->toBe([]);
});

it('distinguishes a clean validation from one with findings', function (): void {
    MockClient::global(HubMock::accounting());

    $clean = app(Hub::class)->accounting()->validateDocument(['type' => 'sales_invoice'], ACCOUNT);

    expect($clean['valid'])->toBeTrue()
        ->and($clean['findings'])->not->toBe([])
        ->and($clean['summary']['errors'])->toBe(0);

    $failing = mockedBody(HubMock::validateDocument(valid: false));

    expect($failing['valid'])->toBeFalse()
        ->and($failing['summary']['errors'])->toBeGreaterThan(0)
        ->and(array_column($failing['findings'], 'severity'))->toContain('error');
});

it('carries the blocking flag Hub decides, not one derived from severity', function (): void {
    $failing = mockedBody(HubMock::validateDocument(valid: false));

    $blocking = [];

    foreach ($failing['findings'] as $finding) {
        $blocking[$finding['code']] = Finding::isBlocking($finding);
    }

    expect($blocking['exact.vat_code.unmapped'])->toBeTrue()
        ->and($blocking['exact.relation.new'])->toBeFalse()
        ->and($failing['summary']['blocking'])->toBe(2);
});

it('books a document and replays the same key onto the same booking', function (): void {
    MockClient::global(['*/v1/accounting/documents' => HubMock::createDocument()]);

    $document = ['type' => 'sales_invoice', 'external_id' => 'invoice-1'];

    $booked = app(Hub::class)->accounting()->createDocument(
        $document,
        idempotencyKey: $document['external_id'],
        accountId: ACCOUNT,
    );

    $replayed = app(Hub::class)->accounting()->createDocument(
        $document,
        idempotencyKey: $document['external_id'],
        accountId: ACCOUNT,
    );

    expect($booked['status'])->toBe('posted')
        ->and($booked['external_ref'])->not->toBeNull()
        ->and($replayed)->toBe($booked);
});

it('answers a booking with the list read when the map has no room for both', function (): void {
    MockClient::global(HubMock::accounting());

    $booked = app(Hub::class)->accounting()->createDocument(
        ['type' => 'sales_invoice', 'external_id' => 'invoice-1'],
        idempotencyKey: 'invoice-1',
        accountId: ACCOUNT,
    );

    expect($booked)->toBe(HubMock::fixture('documents'))
        ->and($booked)->not->toBe(HubMock::fixture('create-document'));
});

it('reports what a re-sync pulled', function (): void {
    MockClient::global(HubMock::accounting());

    expect(app(Hub::class)->accounting()->sync([], ACCOUNT))
        ->toBe(HubMock::fixture('sync'));
});

it('maps the captured error envelopes to the documented exceptions', function (): void {
    $cases = [
        [HubMock::unauthenticated(), AuthenticationException::class],
        [HubMock::notFound(), NotFoundException::class],
        [HubMock::invalidQuery(), ValidationException::class],
    ];

    foreach ($cases as [$response, $expected]) {
        MockClient::destroyGlobal();
        MockClient::global(['*/v1/accounting/capabilities' => $response]);

        expect(fn () => app(Hub::class)->accounting()->capabilities(ACCOUNT))->toThrow($expected);
    }
});

it('serves each factory from its own fixture, with the captured status', function (): void {
    $factories = [
        'capabilities' => [HubMock::capabilities(), 200],
        'reference-data' => [HubMock::referenceData(), 200],
        'mapping' => [HubMock::mapping(), 200],
        'documents' => [HubMock::documents(), 200],
        'ledger-accounts' => [HubMock::ledgerAccounts(), 200],
        'tax-codes' => [HubMock::taxCodes(), 200],
        'customers' => [HubMock::customers(), 200],
        'suppliers' => [HubMock::suppliers(), 200],
        'bank-statements-empty' => [HubMock::bankStatements(), 200],
        'create-document' => [HubMock::createDocument(), 201],
        'create-document-with-warnings' => [HubMock::createDocumentWithWarnings(), 201],
        'sync' => [HubMock::sync(), 200],
        'validate-clean' => [HubMock::validateDocument(), 200],
        'validate-findings' => [HubMock::validateDocument(valid: false), 200],
        'error-unauthenticated' => [HubMock::unauthenticated(), 401],
        'error-not-found' => [HubMock::notFound(), 404],
        'error-invalid-query' => [HubMock::invalidQuery(), 400],
        'itheorie-courses' => [HubMock::itheorieCourses(), 200],
        'itheorie-course' => [HubMock::itheorieCourse(), 200],
        'itheorie-purchase' => [HubMock::itheoriePurchase(), 200],
        'itheorie-purchase-in-flight' => [HubMock::itheoriePurchaseInFlight(), 409],
        'itheorie-student' => [HubMock::itheorieStudent(), 200],
        'extraction-profile-version' => [HubMock::extractionProfileVersion(), 201],
        'extraction-run' => [HubMock::extractionRun(), 202],
        'extraction-run-show' => [HubMock::extractionRunShow(), 200],
        'connection' => [HubMock::connection(), 201],
    ];

    $served = [];

    foreach ($factories as $fixture => [$response, $status]) {
        expect(mockedBody($response))->toBe(HubMock::fixture($fixture))
            ->and($response->status())->toBe($status);

        $served[] = $fixture;
    }

    $shipped = array_map(
        fn (string $path): string => basename($path, '.json'),
        (array) glob(dirname((new ReflectionClass(HubMock::class))->getFileName()).'/fixtures/*.json'),
    );

    sort($shipped);
    sort($served);

    expect($shipped)->toBe($served);
});

it('refuses a fixture name it does not ship', function (): void {
    expect(fn () => HubMock::fixture('not-a-fixture'))
        ->toThrow(HubException::class);
});

it('answers every itheorie read from the captured fixtures', function (): void {
    MockClient::global(HubMock::itheorie());

    $itheorie = app(Hub::class)->itheorie();

    expect($itheorie->courses())->toBe(HubMock::fixture('itheorie-courses'))
        ->and($itheorie->course('01GC7ABB22TT7Y6883YPVHFCG5'))->toBe(HubMock::fixture('itheorie-course'))
        ->and($itheorie->purchase('01KC6P19PXV5RXM70B6BX78KR4'))->toBe(HubMock::fixture('itheorie-purchase'))
        ->and($itheorie->student('ABCD1234'))->toBe(HubMock::fixture('itheorie-student'))
        ->and($itheorie->studentDetailed('ABCD1234'))->toBe(HubMock::fixture('itheorie-student'));
});

it('serves a conflicted purchase as the exception a consumer has to handle', function (): void {
    MockClient::global(['*/v1/itheorie/purchases*' => HubMock::itheoriePurchaseInFlight()]);

    expect(fn () => app(Hub::class)->itheorie()->createPurchase(['course' => 'c-1'], 'order-4711'))
        ->toThrow(PurchaseInFlight::class);
});

it('answers every extraction call from the captured fixtures', function (): void {
    MockClient::global(HubMock::extraction());

    $extraction = app(Hub::class)->extraction();

    expect(app(Hub::class)->connections()->create(42, 'extraction'))->toBe(HubMock::fixture('connection'))
        ->and($extraction->putProfileVersion('timesheet', 1, ['type' => 'object']))->toBe(HubMock::fixture('extraction-profile-version'))
        ->and($extraction->runText('timesheet', 1, 'Fatima ma 8', 'run-1', ACCOUNT))->toBe(HubMock::fixture('extraction-run'))
        ->and($extraction->runFile('timesheet', 1, '%PDF-1.7', 'weekstaat.pdf', 'run-2', ACCOUNT))->toBe(HubMock::fixture('extraction-run'))
        ->and($extraction->getRun('01JABCD5X7Q2M9V3R8T6W4N1KZ', ACCOUNT))->toBe(HubMock::fixture('extraction-run-show'));
});
