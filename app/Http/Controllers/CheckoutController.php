<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Http\Requests\CheckoutRequest;
use App\Http\Requests\RetryPaymentRequest;
use App\Models\Order;
use App\Models\Product;
use App\Models\TrackingContext;
use App\Support\Orders\OrderCapture;
use App\Support\Orders\OrderOwnership;
use App\Support\Orders\QuoteRefused;
use App\Support\Orders\RetailQuoteRefused;
use App\Support\Payments\PaymentStarter;
use App\Support\Payments\PaymentStillPending;
use App\Support\PhoneNumber;
use App\Support\Settings\PaymentSettings;
use App\Support\Storefront\Catalogue;
use App\Support\Storefront\StorefrontShell;
use App\Support\Tracking\MomentRecorder;
use App\Support\Tracking\TrackingSnippet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response as ResponseCode;
use Throwable;

/**
 * The Checkout, Capture, and the pages a Customer lands on once an Order
 * exists.
 *
 * These run inside the `web` group with a session and request-forgery
 * protection, and are never cached — one hit per order.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly PaymentStarter $payments,
        private readonly MomentRecorder $moments,
        private readonly TrackingSnippet $snippet,
    ) {}

    /**
     * The Checkout: one fixed step for the whole store, "where shall we send
     * it?". The Cart is the browser's (ADR-0029), so the page is sent what
     * the form asks from and the names of the teas it may list, and prices
     * the Cart itself from the shared `pricing` prop. A payment choice is
     * drawn only where more than one method is offered. A Customer Signed in
     * finds their number already typed; nobody is asked to sign in.
     */
    public function create(Request $request, Catalogue $catalogue, PaymentSettings $payments): InertiaResponse
    {
        $signedIn = Auth::guard('customer')->user();

        return Inertia::render('checkout', [
            /** Which Cart the Checkout is opened for (ADR-0034): the packet basket's link names it, and anything else is the bulk Cart. */
            'cart' => $request->query('cart') === 'retail' ? 'retail' : 'bulk',
            'signedInPhone' => $signedIn === null ? null : PhoneNumber::asDialled($signedIn->phone),
            'districts' => config('giopages.districts'),
            'paymentMethods' => PaymentMethod::withLogos($payments->offeredMethods()),
            'productNames' => $catalogue->products()
                ->mapWithKeys(fn (Product $product): array => [$product->getKey() => $product->name])
                ->all(),
        ]);
    }

    /**
     * Capture: the Cart and the Quote the buyer was shown, made an Order.
     *
     * Answers the checkout page's request in JSON: where to go next, or —
     * where the Quote no longer agrees with the store — 409 with the figures
     * as they are now and the Products the buyer can no longer have, so the
     * page shows them before anything is placed (ADR-0029).
     */
    public function store(CheckoutRequest $request, OrderCapture $capture): JsonResponse
    {
        try {
            $order = $request->isRetail()
                ? $capture->captureRetail($request->retailLines(), $request->details(), $request->retailQuote())
                : $capture->capture($request->cartLines(), $request->details(), $request->quote());
        } catch (QuoteRefused $refusal) {
            return self::refused($refusal->current->toArray(), $refusal->unavailableProductIds);
        } catch (RetailQuoteRefused $refusal) {
            return self::refused($refusal->current->toArray(), $refusal->unavailablePackIds);
        }

        /**
         * The Capture Moment. It writes rows and returns — nothing is sent to
         * an ad platform from here, so submitting the Checkout is no slower
         * for tracking being on and somebody else's outage is never the
         * Customer's.
         *
         * Caught and reported rather than allowed to raise, because the Order
         * is already committed by the time this runs: an exception here would
         * show an error for an Order that exists, withhold the ownership
         * cookie below, and invite the Customer to submit again.
         */
        try {
            $this->moments->captured($order, TrackingContext::fromRequest($request, route('checkout')));
        } catch (Throwable $exception) {
            report($exception);
        }

        /**
         * The browser that placed the Order is handed ownership of it here,
         * on the Capture response itself, so it is in hand before the browser
         * leaves for a gateway (ADR-0014).
         */
        return response()
            ->json(['redirect' => $this->payments->start($order) ?? $order->followingPageUrl()], ResponseCode::HTTP_CREATED)
            ->withCookie(OrderOwnership::fromRequest($request)->placing($order->order_number)->cookie());
    }

    /**
     * A Capture refused because the Quote no longer agrees: 409, the figures
     * as they are now, and what the buyer can no longer have — Products for
     * the bulk Cart, Packs for the packet basket.
     *
     * @param  array<string, mixed>  $current
     * @param  list<int>  $unavailable
     */
    private static function refused(array $current, array $unavailable): JsonResponse
    {
        return response()->json([
            'message' => 'দাম বা প্রাপ্যতা বদলেছে। নতুন হিসাব দেখে আবার নিশ্চিত করুন।',
            'current' => $current,
            'unavailable' => $unavailable,
        ], ResponseCode::HTTP_CONFLICT);
    }

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
    public function orderComplete(Request $request, Order $order, StorefrontShell $shell): Response|RedirectResponse
    {
        if ($order->isUnpaid()) {
            return redirect()->route('checkout.retry', $order->order_number);
        }

        $placedByThisBrowser = OrderOwnership::fromRequest($request)->owns($order->order_number);

        return $this->uncachedPage('checkout.order-complete', [
            'order' => $order->load('lines'),
            'placedByThisBrowser' => $placedByThisBrowser,

            /** Where the buyer follows the Order from here, found by its System Page's role. */
            'myOrdersUrl' => url($shell->links()['myOrders']),

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
