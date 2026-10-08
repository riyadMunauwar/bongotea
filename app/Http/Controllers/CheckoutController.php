<?php

namespace App\Http\Controllers;

use App\Http\Requests\RetryPaymentRequest;
use App\Models\Order;
use App\Support\Orders\OrderOwnership;
use App\Support\Payments\PaymentStarter;
use App\Support\Payments\PaymentStillPending;
use App\Support\Tracking\TrackingSnippet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as ResponseCode;

/**
 * The pages a Customer lands on once an Order exists.
 *
 * These run inside the `web` group with a session and request-forgery
 * protection, and are never cached — one hit per order.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly PaymentStarter $payments,
        private readonly TrackingSnippet $snippet,
    ) {}

    /**
     * The Order Complete Page: where the Customer lands once the Order is
     * settled as far as the Checkout is concerned.
     *
     * It draws exactly one state. An Unpaid Order has nothing complete to
     * show and one thing to do, which is the Retry Page, so it is sent there
     * rather than drawn as "saved".
     *
     * Addressed by `order_number` alone, and that address ends up in browser
     * history and Referer headers, so it is not what unlocks the Customer's
     * details. What does is the cookie handed to the browser that placed the
     * Order (ADR-0014): a request carrying it sees the Order in full, and
     * any other request sees the Order and not the Customer. A stranger is
     * shown less, never refused.
     */
    public function orderComplete(Request $request, Order $order): Response|RedirectResponse
    {
        if ($order->isUnpaid()) {
            return redirect()->route('checkout.retry', $order->order_number);
        }

        $placedByThisBrowser = OrderOwnership::fromRequest($request)->owns($order->order_number);

        return $this->uncachedPage('checkout.order-complete', [
            'order' => $order->load('lines'),
            'placedByThisBrowser' => $placedByThisBrowser,

            /**
             * The browser's copy of the Purchase the outbox already holds,
             * deduplicated against it on the order number. It is the same
             * cookie that unlocks the Customer's details that unlocks the
             * report: the address alone is not ownership, and a Purchase
             * fired for whoever opens the link would be reported revenue the
             * Merchant does not have.
             */
            'snippet' => $this->snippet->forOrder($order, reportsPurchase: $placedByThisBrowser),
        ]);
    }

    /**
     * The Retry Page: where a Customer lands with an Unpaid Order.
     *
     * The Order summary and the store's Payment Methods again, so the
     * Customer finishes without re-typing anything. Addressed and printed
     * like the Order Complete Page, for the same reason. An Order that is not
     * Unpaid has nothing to retry and is sent to its Order Complete Page
     * instead.
     */
    public function retry(Order $order): Response|RedirectResponse
    {
        if (! $order->isUnpaid()) {
            return redirect()->route('checkout.order-complete', $order->order_number);
        }

        return $this->uncachedPage('checkout.retry', [
            'order' => $order->load('lines'),
            'methods' => $order->retryPaymentMethods(),

            /** The Pixel's base code and nothing more: an Order that is Unpaid has bought nothing to report. */
            'snippet' => $this->snippet->forOrder($order, reportsPurchase: false),
        ]);
    }

    /**
     * The Customer choosing again on the Retry Page.
     *
     * The chosen method is written onto the Order, which the Order's own log
     * records, and the Customer is sent where that method sends them: Cash on
     * Delivery settles the Order and lands on the Order Complete Page; an
     * online method starts a new Payment and lands on the gateway.
     */
    public function choose(RetryPaymentRequest $request, Order $order): RedirectResponse
    {
        if ($order->isUnpaid()) {
            $order->update(['payment_method' => $request->paymentMethod()]);
        }

        return $this->sendOn($order);
    }

    /**
     * Send the Customer to the page that follows an Order in its current
     * state: the gateway's page where a Payment could be started, else the
     * Order Complete Page once the Order is settled as far as the Checkout is
     * concerned, or the Retry Page while it is Unpaid.
     *
     * A gateway that cannot be reached keeps the Order and lands the Customer
     * on the Retry Page rather than turning them away (ADR-0013).
     *
     * A Payment refused because another is Pending and only seconds old is
     * the one outcome with something to say: the Customer is sent to the
     * Retry Page with the reason beside the chooser, so a second tap reads
     * as "wait" rather than as nothing having happened.
     */
    private function sendOn(Order $order): RedirectResponse
    {
        $errors = [];

        try {
            $destination = $this->payments->start($order) ?? $order->followingPageUrl();
        } catch (PaymentStillPending $refusal) {
            $destination = route('checkout.retry', $order->order_number);
            $errors = ['payment_method' => $refusal->getMessage()];
        }

        /**
         * 303 rather than 302: the visitor arrived here by POST, and only See
         * Other guarantees the browser follows with a GET rather than
         * re-submitting the choice on a refresh.
         */
        return redirect()->to($destination, ResponseCode::HTTP_SEE_OTHER)->withErrors($errors);
    }
}
