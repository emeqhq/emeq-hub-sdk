<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Request\Extraction;

use Emeq\HubSdk\Http\Concerns\HasAccountIdHeader;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/** @deprecated Hints have no measured effect on extraction and will be removed in a later release. */
class PutHintsRequest extends Request implements HasBody
{
    use HasAccountIdHeader;
    use HasJsonBody;

    protected Method $method = Method::PUT;

    public function __construct(
        private readonly ?string $hints,
        private readonly string $accountId,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/extraction/hints';
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->accountIdHeaders($this->accountId);
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return ['hints' => $this->hints];
    }
}
