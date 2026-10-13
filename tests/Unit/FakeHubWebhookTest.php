<?php

declare(strict_types=1);

use Emeq\HubSdk\Contracts\ResolvesWebhookAccount;
use Emeq\HubSdk\Testing\FakeHubWebhook;
use Emeq\HubSdk\Webhooks\HubWebhookAction;
use Emeq\HubSdk\Webhooks\HubWebhookEnvelope;
use Emeq\HubSdk\Webhooks\HubWebhookEvent;
use Emeq\HubSdk\Webhooks\HubWebhookHeaders;
use Emeq\HubSdk\Webhooks\SpatieWebhookClientConfig;
use Illuminate\Http\Request;
use Spatie\WebhookClient\SignatureValidator\DefaultSignatureValidator;
use Spatie\WebhookClient\WebhookConfig;

beforeEach(function (): void {
    $this->app->instance(ResolvesWebhookAccount::class, new class implements ResolvesWebhookAccount
    {
        public function prepare(string $accountId): bool
        {
            return true;
        }
    });
});

test('body is exactly what the envelope encodes, so a consumer can decode what they signed', function (): void {
    $fake = FakeHubWebhook::event(
        HubWebhookEvent::SALES_INVOICE_CHANGED,
        accountId: '47',
        data: ['external_ref' => 'inv-1'],
    );

    expect(HubWebhookEnvelope::tryFromRaw($fake->body())?->toArray())->toBe($fake->envelope()->toArray());
});

test('headers carry a signature the packaged validator accepts', function (): void {
    $fake = FakeHubWebhook::event(HubWebhookEvent::SALES_INVOICE_CHANGED, accountId: '47');
    $secret = 'test-secret';

    $config = new WebhookConfig(SpatieWebhookClientConfig::make($secret));
    $request = Request::create('/webhooks/emeq-hub', 'POST', content: $fake->body());
    foreach ($fake->headers($secret) as $name => $value) {
        $request->headers->set($name, $value);
    }

    expect((new DefaultSignatureValidator)->isValid($request, $config))->toBeTrue();
});

test('a body signed for one secret is rejected under another', function (): void {
    $fake = FakeHubWebhook::event(HubWebhookEvent::CONNECTION_REVOKED, accountId: '47');

    $config = new WebhookConfig(SpatieWebhookClientConfig::make('right-secret'));
    $request = Request::create('/webhooks/emeq-hub', 'POST', content: $fake->body());
    foreach ($fake->headers('wrong-secret') as $name => $value) {
        $request->headers->set($name, $value);
    }

    expect((new DefaultSignatureValidator)->isValid($request, $config))->toBeFalse();
});

test('headers carry distinct event and request ids by default', function (): void {
    $headers = FakeHubWebhook::event(HubWebhookEvent::CONNECTION_REVOKED, accountId: '47')->headers('secret');

    expect($headers[HubWebhookHeaders::EVENT_ID])->not->toBe($headers[HubWebhookHeaders::REQUEST_ID])
        ->and($headers[HubWebhookHeaders::EVENT_ID])->not->toBeEmpty();
});

test('event and request ids can be pinned for deterministic assertions', function (): void {
    $headers = FakeHubWebhook::event(
        HubWebhookEvent::CONNECTION_REVOKED,
        accountId: '47',
        eventId: 'evt-fixed',
        requestId: 'req-fixed',
    )->headers('secret');

    expect($headers[HubWebhookHeaders::EVENT_ID])->toBe('evt-fixed')
        ->and($headers[HubWebhookHeaders::REQUEST_ID])->toBe('req-fixed');
});

test('connectionRevoked builds the canonical disconnect envelope', function (): void {
    $envelope = FakeHubWebhook::connectionRevoked('47', connectionId: 'con_1')->envelope();

    expect($envelope->event)->toBe(HubWebhookEvent::CONNECTION_REVOKED)
        ->and($envelope->accountId)->toBe('47')
        ->and($envelope->data['connection_id'])->toBe('con_1');
});

test('salesInvoiceChanged builds the canonical change envelope and honours hubAuthored', function (): void {
    $ownWrite = FakeHubWebhook::salesInvoiceChanged('47', hubAuthored: true)->envelope();
    $externalChange = FakeHubWebhook::salesInvoiceChanged('47')->envelope();

    expect($ownWrite->event)->toBe(HubWebhookEvent::SALES_INVOICE_CHANGED)
        ->and($ownWrite->hubAuthored)->toBeTrue()
        ->and($externalChange->hubAuthored)->toBeFalse();
});

test('salesInvoiceChanged can stage an echo of the consumers own write', function (): void {
    $echo = FakeHubWebhook::salesInvoiceChanged(
        '47',
        hubAuthored: true,
        entityId: 'guid-1',
        hubLastWroteAt: '2026-08-12T10:00:00+00:00',
    )->envelope();

    expect($echo->entityId)->toBe('guid-1')
        ->and($echo->action)->toBe(HubWebhookAction::UPDATED)
        ->and($echo->hubLastWroteAt)->toBe('2026-08-12T10:00:00+00:00');
});

test('extractionRunCompleted carries only run metadata, the result is fetched with getRun', function (): void {
    $envelope = FakeHubWebhook::extractionRunCompleted('47', runId: 'run_1')->envelope();

    expect($envelope->event)->toBe(HubWebhookEvent::EXTRACTION_RUN_COMPLETED)
        ->and($envelope->provider)->toBe('extraction')
        ->and($envelope->accountId)->toBe('47')
        ->and($envelope->data)->toBe([
            'run_id' => 'run_1',
            'profile' => 'timesheet',
            'version' => 1,
            'readers' => ['ministral-14b-latest', 'mistral-medium-2604'],
            'billable_pages' => 1,
        ]);
});

test('extractionRunFailed carries the run and its reason', function (): void {
    $envelope = FakeHubWebhook::extractionRunFailed('47', runId: 'run_2', reason: 'invalid_json')->envelope();

    expect($envelope->event)->toBe(HubWebhookEvent::EXTRACTION_RUN_FAILED)
        ->and($envelope->provider)->toBe('extraction')
        ->and($envelope->data)->toBe([
            'run_id' => 'run_2',
            'profile' => 'timesheet',
            'version' => 1,
            'reason' => 'invalid_json',
        ]);
});
