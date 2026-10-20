<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPeriod;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands an admin the payment screenshot recorded on a subscription period.
 *
 * The screenshot lives on the private disk, so this route is the only way to
 * read it. It is registered through the admin panel's authenticated routes, so
 * the panel's own login applies, and it additionally requires the
 * `subscriptions.view` permission: a student's payment details are not for every
 * admin account that can open the dashboard.
 */
class SubscriptionPeriodProofController extends Controller
{
    public function __invoke(Request $request, SubscriptionPeriod $period): StreamedResponse
    {
        abort_unless($request->user('admin')?->can('view', $period), 403);

        $media = $period->proof();

        abort_if($media === null, 404);

        $response = $media->toInlineResponse($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
