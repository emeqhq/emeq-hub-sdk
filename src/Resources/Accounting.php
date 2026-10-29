<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Resources;

use Emeq\HubSdk\Http\Request\Accounting\CreateDocumentRequest;
use Emeq\HubSdk\Http\Request\Accounting\DeleteDocumentRequest;
use Emeq\HubSdk\Http\Request\Accounting\GetAccountingRequest;
use Emeq\HubSdk\Http\Request\Accounting\PutMappingRequest;
use Emeq\HubSdk\Http\Request\Accounting\SyncAccountingRequest;
use Emeq\HubSdk\Http\Request\Accounting\ValidateDocumentRequest;

class Accounting extends Resource
{
    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function createDocument(array $document, string $idempotencyKey, ?string $accountId = null): array
    {
        $response = $this->connector->send(new CreateDocumentRequest(
            document: $document,
            accountId: $this->resolveAccountId($accountId),
            idempotencyKey: $idempotencyKey,
        ));

        return $this->json($response->json());
    }

    public function deleteDocument(string $externalId, string $type, ?string $accountId = null): void
    {
        $this->connector->send(new DeleteDocumentRequest(
            externalId: $externalId,
            type: $type,
            accountId: $this->resolveAccountId($accountId),
        ));
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function validateDocument(array $document, ?string $accountId = null): array
    {
        $response = $this->connector->send(new ValidateDocumentRequest(
            document: $document,
            accountId: $this->resolveAccountId($accountId),
        ));

        return $this->json($response->json());
    }

    /** @param  array<string, mixed>  $query */
    public function documents(array $query = [], ?string $accountId = null): AccountingPage
    {
        return $this->getList('/accounting/documents', $query, $accountId);
    }

    /** @param  array<string, mixed>  $query */
    public function bankStatements(array $query = [], ?string $accountId = null): AccountingPage
    {
        return $this->getList('/accounting/bank-statements', $query, $accountId);
    }

    /** @param  array<string, mixed>  $query */
    public function ledgerAccounts(array $query = [], ?string $accountId = null): AccountingPage
    {
        return $this->getList('/accounting/ledger-accounts', $query, $accountId);
    }

    /** @param  array<string, mixed>  $query */
    public function taxCodes(array $query = [], ?string $accountId = null): AccountingPage
    {
        return $this->getList('/accounting/tax-codes', $query, $accountId);
    }

    /** @param  array<string, mixed>  $query */
    public function customers(array $query = [], ?string $accountId = null): AccountingPage
    {
        return $this->getList('/accounting/customers', $query, $accountId);
    }

    /** @param  array<string, mixed>  $query */
    public function suppliers(array $query = [], ?string $accountId = null): AccountingPage
    {
        return $this->getList('/accounting/suppliers', $query, $accountId);
    }

    /** @return array<string, mixed> */
    public function capabilities(?string $accountId = null): array
    {
        return $this->getObject('/accounting/capabilities', $accountId);
    }

    /** @return array<string, mixed> */
    public function referenceData(?string $accountId = null): array
    {
        return $this->getObject('/accounting/reference-data', $accountId);
    }

    /** @return array<string, mixed> */
    public function mapping(?string $accountId = null): array
    {
        return $this->getObject('/accounting/mapping', $accountId);
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @return array<string, mixed>
     */
    public function updateMapping(array $mapping, ?string $accountId = null): array
    {
        $response = $this->connector->send(new PutMappingRequest(
            mapping: $mapping,
            accountId: $this->resolveAccountId($accountId),
        ));

        return $this->json($response->json());
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function sync(array $body = [], ?string $accountId = null): array
    {
        $response = $this->connector->send(new SyncAccountingRequest(
            accountId: $this->resolveAccountId($accountId),
            payload: $body,
        ));

        return $this->json($response->json());
    }

    /** @param  array<string, mixed>  $query */
    private function getList(string $path, array $query, ?string $accountId): AccountingPage
    {
        return AccountingPage::fromPayload($this->send($path, $query, $accountId));
    }

    /** @return array<string, mixed> */
    private function getObject(string $path, ?string $accountId): array
    {
        return $this->json($this->send($path, [], $accountId));
    }

    /** @param  array<string, mixed>  $query */
    private function send(string $path, array $query, ?string $accountId): mixed
    {
        return $this->connector->send(new GetAccountingRequest(
            path: $path,
            accountId: $this->resolveAccountId($accountId),
            queryParameters: $query,
        ))->json();
    }
}
