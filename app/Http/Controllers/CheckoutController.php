<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Support\Orders\OrderCapture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as ResponseCode;

/**
 * Capture, and the page a visitor lands on afterwards.
 *
 * Unlike the landing routes, these run inside the `web` group with a session
 * and request-forgery protection. They are never cached — one hit per order —
 * so the Set-Cookie that costs a landing page its cacheability costs nothing
 * here.
 */
class CheckoutController extends Controller
{
    /**
     * Take an Order.
     *
     * Answers JSON to the Checkout island's fetch and a redirect to a plain
     * form post, so the form still works if the island's script never runs.
     */
    public function store(StoreOrderRequest $request, OrderCapture $capture): JsonResponse|RedirectResponse
    {
        $order = $capture->capture($request->page(), $request->details());

        $confirmation = route('checkout.confirmation', $order->order_number);

        if ($request->expectsJson()) {
            return response()->json(['redirect' => $confirmation], ResponseCode::HTTP_CREATED);
        }

        /**
         * 303 rather than 302: the visitor arrived here by POST, and only See
         * Other guarantees the browser follows with a GET rather than
         * re-submitting the order on a refresh.
         */
        return redirect()->to($confirmation, ResponseCode::HTTP_SEE_OTHER);
    }

    /**
     * The page the visitor lands on once the Order exists.
     *
     * Addressed by `order_number` alone, so it deliberately shows the *order*
     * and not the *customer*: what was bought, what it costs, and that someone
     * will call. Printing the name, phone or address here would put personal
     * details behind a URL that lives in browser history, Referer headers and
     * anything else that sees a link — for no gain, since the visitor typed
     * them moments ago.
     */
    public function confirmation(Order $order): Response
    {
        return response()
            ->view('checkout.confirmation', ['order' => $order->load('lines')])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * A request-forgery token for the Checkout island.
     *
     * The island ships inside a page served straight off disk with no session
     * and no cookie, so it has no token baked in and does not need one: the
     * `Sec-Fetch-Site` origin check passes without it. This endpoint is the
     * fallback for a client that sends no such header — the island calls it
     * only after a Capture has already been refused, which turns "some
     * webviews cannot order" into "some webviews pay one extra round trip".
     */
    public function token(Request $request): JsonResponse
    {
        return response()
            ->json(['token' => $request->session()->token()])
            ->header('Cache-Control', 'no-store');
    }
}
