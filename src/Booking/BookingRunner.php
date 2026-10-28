<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking;

use Closure;
use Emeq\HubSdk\Booking\Contracts\ResolvesBookableDocument;
use Emeq\HubSdk\Events\DocumentBooked;
use Emeq\HubSdk\Events\DocumentBookingFailed;
use Emeq\HubSdk\Exceptions\BookingAlreadyInProgress;
use Emeq\HubSdk\Exceptions\BookingTemporarilyUnavailable;
use Emeq\HubSdk\Exceptions\DocumentNotAuthorized;
use Emeq\HubSdk\Exceptions\DocumentNotBookable;
use Emeq\HubSdk\Exceptions\HubException;
use Emeq\HubSdk\Exceptions\RateLimitException;
use Emeq\HubSdk\Exceptions\ServerException;
use Emeq\HubSdk\Hub;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Config;
use Throwable;

class BookingRunner
{
    public function __construct(
        protected readonly ResolvesBookableDocument $documents,
        protected readonly DocumentBooker $booker,
        protected readonly Hub $hub,
    ) {}

    /**
     * @param  list<array{module: string, id: string}>  $requested
     * @return list<CheckOutcome>
     */
    public function check(array $requested): array
    {
        return $this->withinBudget(
            $requested,
            fn (string $module, string $id): CheckOutcome => $this->checkOne($module, $id),
        );
    }

    /**
     * @param  list<array{module: string, id: string}>  $requested
     * @return list<BatchBookingResult>
     */
    public function book(array $requested, bool $withAttachment = true): array
    {
        $limited = null;

        return $this->withinBudget(
            $requested,
            function (string $module, string $id) use ($withAttachment, &$limited): BatchBookingResult {
                if ($limited !== null) {
                    return new BatchBookingResult($module, $id, $this->announce($module, $id, $limited, $this->subjectOf($module, $id)));
                }

                $outcome = $this->bookOne($module, $id, $withAttachment);

                if ($outcome->rateLimited) {
                    $limited = $outcome;
                }

                return new BatchBookingResult($module, $id, $outcome);
            },
        );
    }

    public function checkOne(string $module, string $id): CheckOutcome
    {
        try {
            $document = $this->documents->resolve($module, $id);
        } catch (ModelNotFoundException) {
            return new CheckOutcome($module, $id, 404, null, BookingMessages::line('not_found'));
        } catch (DocumentNotAuthorized) {
            return new CheckOutcome($module, $id, 403, null, BookingMessages::line('not_allowed'));
        } catch (DocumentNotBookable $e) {
            return new CheckOutcome($module, $id, 422, null, $e->getMessage());
        }

        try {
            return new CheckOutcome($module, $id, 200, $this->hub->accounting()->validateDocument($document->document), null);
        } catch (RateLimitException|ServerException $e) {
            return new CheckOutcome($module, $id, 503, null, BookingMessages::line('temporarily_unavailable'), $e->retryAfter);
        } catch (HubException $e) {
            return new CheckOutcome($module, $id, 502, null, $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return new CheckOutcome($module, $id, 503, null, BookingMessages::line('temporarily_unavailable'));
        }
    }

    public function bookOne(string $module, string $id, bool $withAttachment = true): BookingOutcome
    {
        try {
            $document = $this->documents->resolve($module, $id);
        } catch (ModelNotFoundException) {
            return $this->announce($module, $id, BookingOutcome::notFound());
        } catch (DocumentNotAuthorized) {
            return $this->announce($module, $id, BookingOutcome::notAllowed());
        } catch (DocumentNotBookable $e) {
            return $this->announce($module, $id, BookingOutcome::refused($e->getMessage()));
        }

        try {
            $record = $this->booker->book(
                $document->document,
                $withAttachment ? $document->attachments : null,
            );
        } catch (BookingAlreadyInProgress $e) {
            return $this->announce($module, $id, BookingOutcome::alreadyInProgress($e->getMessage(), $e->retryAfter), $document->subject);
        } catch (BookingTemporarilyUnavailable $e) {
            $outcome = $e->getPrevious() instanceof RateLimitException
                ? BookingOutcome::rateLimited($e->getPrevious()->retryAfterSeconds(), $e->getMessage())
                : BookingOutcome::unavailable($e->getMessage(), $e->retryAfter);

            return $this->announce($module, $id, $outcome, $document->subject);
        } catch (HubException $e) {
            return $this->announce($module, $id, BookingOutcome::upstreamFailure($e->getMessage()), $document->subject);
        }

        return $this->announce($module, $id, BookingOutcome::from($record), $document->subject);
    }

    protected function subjectOf(string $module, string $id): mixed
    {
        try {
            return $this->documents->resolve($module, $id)->subject;
        } catch (ModelNotFoundException|DocumentNotAuthorized|DocumentNotBookable) {
            return null;
        }
    }

    protected function announce(string $module, string $id, BookingOutcome $outcome, mixed $subject = null): BookingOutcome
    {
        event($outcome->booked
            ? new DocumentBooked($module, $id, $outcome, $subject)
            : new DocumentBookingFailed($module, $id, $outcome, $subject));

        return $outcome;
    }

    /**
     * @template TResult
     *
     * @param  list<array{module: string, id: string}>  $requested
     * @param  Closure(string, string): TResult  $handle
     * @return list<TResult>
     */
    protected function withinBudget(array $requested, Closure $handle): array
    {
        $deadline = microtime(true) + $this->timeBudget();

        $results = [];

        foreach ($requested as $document) {
            $results[] = $handle($document['module'], $document['id']);

            if (microtime(true) >= $deadline) {
                break;
            }
        }

        return $results;
    }

    protected function timeBudget(): float
    {
        $seconds = Config::get('hub.booking.batch_seconds');

        return is_numeric($seconds) ? (float) $seconds : 60.0;
    }
}
