<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Exceptions;

class RateLimitException extends HubException
{
    public function retryAfterSeconds(): int
    {
        return $this->retryAfter ?? 60;
    }
}
