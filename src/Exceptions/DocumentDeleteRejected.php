<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Exceptions;

use Throwable;

class DocumentDeleteRejected extends HubException
{
    /** @param  array<string, list<string>>  $validationErrors */
    public function __construct(
        string $message,
        string $error = 'document_delete_rejected',
        string $category = 'UNKNOWN_ERROR',
        ?string $requestId = null,
        ?int $status = null,
        ?Throwable $previous = null,
        ?int $retryAfter = null,
        ?bool $retryable = null,
        array $validationErrors = [],
        public readonly ?string $upstreamError = null,
        public readonly ?int $upstreamStatus = null,
    ) {
        parent::__construct($message, $error, $category, $requestId, $status, $previous, $retryAfter, $retryable, $validationErrors);
    }
}
