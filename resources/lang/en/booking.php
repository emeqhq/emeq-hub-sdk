<?php

declare(strict_types=1);

/*
 * Copy for the outcomes this package decides itself — the cases where Hub gave
 * no answer to show. Publish `hub-translations` to reword them.
 *
 * What Hub did answer is shown in Hub's own words. Those name the relation or
 * the ledger account that is missing and say what to do about it, which a line
 * here cannot. One source, and it is Hub: a new error code has the right text
 * there immediately, with no SDK release and nothing for a consumer to update.
 */

return [

    'not_found' => 'This document no longer exists.',
    'not_allowed' => 'You may not edit this document, so you may not book it either.',
    'temporarily_unavailable' => 'The bookkeeping is briefly unreachable. Nothing was booked; try again shortly.',
    'already_in_progress' => 'A booking of this document is already running. Wait for it to finish.',
    'rate_limited' => 'The bookkeeping is taking no more bookings for now. Nothing was booked; try again later.',

    'error' => [
        // Last resort for a row without a message — a row this package did not
        // write, because those always carry one.
        'unknown' => 'The bookkeeping returned an unknown error.',
    ],

];
