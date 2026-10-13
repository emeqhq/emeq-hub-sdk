<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Request\Extraction;

use Emeq\HubSdk\Http\Concerns\HasAccountIdHeader;
use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetRunRequest extends Request
{
    use HasAccountIdHeader;

    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $runId,
        private readonly string $accountId,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/extraction/runs/'.rawurlencode($this->runId);
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->accountIdHeaders($this->accountId);
    }
}
