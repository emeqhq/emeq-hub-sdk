<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking;

class BookingOutcome
{
    /**
     * @param  list<array<string, mixed>>  $warnings  what Hub did to the relation while booking — empty
     *                                                unless `$booked`, since only a posted document writes one
     */
    final private function __construct(
        public readonly bool $booked,
        public readonly int $status,
        public readonly ?string $message,
        public readonly ?HubDocument $record,
        public readonly bool $needsManualCheck = false,
        public readonly ?string $reason = null,
        public readonly ?int $retryAfter = null,
        public readonly array $warnings = [],
        public readonly bool $rateLimited = false,
    ) {}

    public static function from(HubDocument $record): static
    {
        if ($record->status === HubDocument::STATUS_UNKNOWN) {
            return static::unknown($record, $record->error_message ?: BookingMessages::forError($record->error));
        }

        if ($record->status !== HubDocument::STATUS_POSTED) {
            return static::refused($record->error_message ?: BookingMessages::forError($record->error), $record);
        }

        return static::booked($record);
    }

    public static function booked(HubDocument $record): static
    {
        return new static(true, 200, null, $record, warnings: $record->warnings);
    }

    public static function refused(string $message, ?HubDocument $record = null): static
    {
        return new static(false, 422, $message, $record);
    }

    public static function unknown(HubDocument $record, string $message): static
    {
        return new static(false, 422, $message, $record, needsManualCheck: true);
    }

    public static function unavailable(?string $reason = null, ?int $retryAfter = null): static
    {
        return new static(false, 503, BookingMessages::line('temporarily_unavailable'), null, reason: $reason, retryAfter: $retryAfter);
    }

    public static function alreadyInProgress(?string $reason = null, ?int $retryAfter = null): static
    {
        return new static(false, 503, BookingMessages::line('already_in_progress'), null, reason: $reason, retryAfter: $retryAfter);
    }

    public static function rateLimited(int $retryAfter, ?string $reason = null): static
    {
        return new static(false, 503, BookingMessages::line('rate_limited'), null, reason: $reason, retryAfter: $retryAfter, rateLimited: true);
    }

    public static function upstreamFailure(string $message): static
    {
        return new static(false, 502, $message, null);
    }

    public static function notFound(?string $message = null): static
    {
        return new static(false, 404, $message ?? BookingMessages::line('not_found'), null);
    }

    public static function notAllowed(?string $message = null): static
    {
        return new static(false, 403, $message ?? BookingMessages::line('not_allowed'), null);
    }

    public function mayRetry(): bool
    {
        return $this->status === 503;
    }
}
