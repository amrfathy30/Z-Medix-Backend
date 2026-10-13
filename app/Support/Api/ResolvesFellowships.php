<?php

namespace App\Support\Api;

use App\Exceptions\Fellowship\FellowshipAccessException;
use App\Models\Fellowship;

/**
 * One answer to "may this student see this Fellowship?", shared by every
 * Fellowship endpoint.
 *
 * An inactive Fellowship is reported as not found rather than forbidden: it is
 * not study content a student is being refused, it is content that is not on
 * offer, and the Question Bank does not list it either.
 */
trait ResolvesFellowships
{
    protected function assertFellowshipAvailable(Fellowship $fellowship): void
    {
        if (! $fellowship->is_active) {
            throw FellowshipAccessException::fellowshipUnavailable();
        }
    }
}
