<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Request\Accounting;

use Emeq\HubSdk\Http\Concerns\HasAccountIdHeader;
use Saloon\Enums\Method;
use Saloon\Http\Request;

class DeleteDocumentRequest extends Request
{
    use HasAccountIdHeader;

    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly string $externalId,
        private readonly string $type,
        private readonly string $accountId,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/accounting/documents/'.rawurlencode($this->externalId);
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->accountIdHeaders($this->accountId);
    }

    /** @return array<string, string> */
    protected function defaultQuery(): array
    {
        return ['type' => $this->type];
    }
}
