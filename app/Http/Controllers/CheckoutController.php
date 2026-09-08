<?php

namespace App\Http\Controllers;

use App\Http\Requests\RetryPaymentRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Support\Orders\OrderCapture;
use App\Support\Payments\PaymentStarter;
use App\Support\Payments\PaymentStillPending;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as ResponseCode;

/**
 * Capture, and the pages a visitor lands on afterwards.
 *
 * Unlike the landing routes, these run inside the `web` group with a session
 * and request-forgery protection. They are never cached — one hit per order —
 * so the Set-Cookie that costs a landing page its cacheability costs nothing
 * here.
 */
class CheckoutController extends Controller
{
    public function __construct(private readonly PaymentStarter $payments) {}

    /**
     * Take an Order.
     *
     * Answers JSON to the Checkout island's fetch and a redirect to a plain
     * form post, so the form still works if the island's script never runs.
     */
    public function store(StoreOrderRequest $request, OrderCapture $capture): JsonResponse|RedirectResponse
    {
        $order = $capture->capture($request->page(), $request->details());

        return $this->sendOn($request, $order);
    }

    /**
     * The Thank-you Page: where the visitor lands once the Order exists.
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
        return $this->uncachedPage('checkout.confirmation', ['order' => $order->load('lines')]);
    }

    /**
     * The Retry Page: where a Customer lands with an Unpaid Order.
     *
     * The Order summary and the Page's Payment Methods again, Cash on Delivery
     * among them where the Page offers it, so the Customer finishes without
     * re-typing anything. Addressed and printed like the Thank-you Page, for
     * the same reason. An Order that is not Unpaid has nothing to retry and
     * is sent to its Thank-you Page instead.
     */
    public function retry(Order $order): Response|RedirectResponse
    {
        if (! $order->isUnpaid()) {
            return redirect()->route('checkout.confirmation', $order->order_number);
        }

        return $this->uncachedPage('checkout.retry', [
            'order' => $order->load('lines'),
            'methods' => $order->retryPaymentMethods(),
        ]);
    }

    /**
     * The Customer choosing again on the Retry Page.
     *
     * The chosen method is written onto the Order, which the Order's own log
     * records, and the Customer is sent where a Capture under that method
     * would send them: Cash on Delivery settles the Order and lands on the
     * Thank-you Page; an online method starts a new Payment and lands on the
     * gateway.
     */
    public function choose(RetryPaymentRequest $request, Order $order): RedirectResponse
    {
        if ($order->isUnpaid()) {
            $order->update(['payment_method' => $request->paymentMethod()]);
        }

        return $this->sendOn($request, $order);
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

    /**
     * Send the Customer to the page that follows an Order in its current
     * state: the gateway's page where a Payment could be started, else the
     * Thank-you Page once the Order is settled as far as the Checkout is
     * concerned, or the Retry Page while it is Unpaid.
     *
     * The Order exists before the gateway is asked, so a gateway that cannot
     * be reached keeps the Order and lands the Customer on the Retry Page
     * rather than turning them away (ADR-0013).
     *
     * A Payment refused because another is Pending and only seconds old is
     * the one outcome with something to say: the Customer is sent to the
     * Retry Page with the reason beside the chooser, so a second tap reads
     * as "wait" rather than as nothing having happened.
     */
    private function sendOn(Request $request, Order $order): JsonResponse|RedirectResponse
    {
        $errors = [];

        try {
            $destination = $this->payments->start($order) ?? $order->followingPageUrl();
        } catch (PaymentStillPending $refusal) {
            $destination = route('checkout.retry', $order->order_number);
            $errors = ['payment_method' => $refusal->getMessage()];
        }

        /**
         * The reason rides only on the form post: the Retry Page is a plain
         * form, and Capture, the one JSON caller, cannot be refused this way
         * because its Order has no Payment yet to be Pending.
         */
        if ($request->expectsJson()) {
            return response()->json(['redirect' => $destination], ResponseCode::HTTP_CREATED);
        }

        /**
         * 303 rather than 302: the visitor arrived here by POST, and only See
         * Other guarantees the browser follows with a GET rather than
         * re-submitting the order on a refresh.
         */
        return redirect()->to($destination, ResponseCode::HTTP_SEE_OTHER)->withErrors($errors);
    }
}
