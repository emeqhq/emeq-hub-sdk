<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking\Resources;

use Emeq\HubSdk\Booking\BatchBookingResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BatchResultResource extends JsonResource
{
    public function __construct(private readonly BatchBookingResult $result)
    {
        parent::__construct($result);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $outcome = $this->result->outcome;

        return [
            'module' => $this->result->module,
            'id' => $this->result->id,
            'booked' => $outcome->booked,
            'status' => $outcome->status,
            'may_retry' => $outcome->mayRetry(),
            'retry_after' => $outcome->retryAfter,
            'rate_limited' => $outcome->rateLimited,
            'needs_manual_check' => $outcome->needsManualCheck,
            'message' => $outcome->message,
            'booking' => BookingResource::maybe($outcome->record),
        ];
    }
}
