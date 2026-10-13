<?php

namespace App\Support\Sms;

use App\Enums\OrderText;
use App\Models\Order;
use App\Models\Shipment;
use App\Support\Bangla;
use App\Support\Inventory\Grams;
use App\Support\Kilograms;
use App\Support\Settings\SmsSettings;
use App\Support\Storefront\StorefrontShell;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The texts an Order is owed, and the Staff Alert a new one raises.
 *
 * Nothing here sends. Each text is worded and queued **after the change that
 * owes it has committed** — a Capture rolled back texts nobody, and the
 * placed text reads the Order's lines and total, which exist only once
 * Capture has finished writing them. And nothing here may throw into its
 * caller: a text that cannot go out never stops an Order being captured or
 * moved along, so the whole of it is `rescue()`d and reported.
 */
class OrderTexts
{
    /** What a template may say, each filled from the Order as it stands once committed. */
    public const PLACEHOLDERS = ['{order_number}', '{total}', '{kilograms}', '{link}', '{name}'];

    /**
     * Queue this text to the Order's Customer, if the Merchant has it
     * switched on.
     */
    public function owe(Order $order, OrderText $text): void
    {
        $this->afterCommit(fn (): ?Order => $order->fresh(), $this->toCustomer($text));
    }

    /**
     * Queue the sent text for the Order this parcel belongs to. The Order is
     * read only once the booking has committed, inside the same guard, so a
     * Shipment whose Order cannot be read never fails the booking that wrote
     * it.
     */
    public function oweSent(Shipment $shipment): void
    {
        $this->afterCommit(fn (): ?Order => Order::query()->find($shipment->order_id), $this->toCustomer(OrderText::Sent));
    }

    /**
     * Queue the new-Order Staff Alert to every one of the Merchant's numbers,
     * if it is switched on. Never to the Customer.
     */
    public function alertStaffToNewOrder(Order $order): void
    {
        $this->afterCommit(fn (): ?Order => $order->fresh(), function (Order $order, SmsSettings $settings): void {
            if (! $settings->newOrderAlertEnabled) {
                return;
            }

            $alert = $this->word($settings->newOrderAlertTemplate, $order);

            foreach ($settings->staffNumbers as $number) {
                SendText::dispatch($number, $alert);
            }
        });
    }

    /**
     * @return Closure(Order, SmsSettings): void
     */
    private function toCustomer(OrderText $text): Closure
    {
        return function (Order $order, SmsSettings $settings) use ($text): void {
            if ($settings->isOn($text)) {
                SendText::dispatch($order->customer_phone, $this->word($settings->template($text), $order));
            }
        };
    }

    /**
     * A template with every placeholder filled: figures in Bangla digits as
     * the storefront draws them, the order number Latin as it is everywhere.
     */
    private function word(string $template, Order $order): string
    {
        return str_replace(self::PLACEHOLDERS, [
            $order->order_number,
            Bangla::taka($order->total),
            self::kilograms($order),
            url(StorefrontShell::MY_ORDERS_PATH),
            (string) $order->customer_name,
        ], $template);
    }

    /**
     * What the Order weighs, in Kilograms as the storefront draws them: read
     * through its lines' grams, so a retail Order of Packs is its Packs'
     * weight (ADR-0035) and not their count, with decimals only where a part
     * of a kilogram is held.
     */
    private static function kilograms(Order $order): string
    {
        $kilograms = Kilograms::fromGrams($order->grams());

        return is_int($kilograms) ? Bangla::number($kilograms) : Bangla::digits(Grams::toKilograms($order->grams()));
    }

    /**
     * Run once whatever transaction is open has committed — at once where
     * none is — against the Order as it was committed and the Settings as
     * they stand. Never thrown out of.
     *
     * @param  Closure(): ?Order  $committedOrder
     * @param  Closure(Order, SmsSettings): void  $queue
     */
    private function afterCommit(Closure $committedOrder, Closure $queue): void
    {
        DB::afterCommit(fn () => rescue(function () use ($committedOrder, $queue): void {
            $committed = $committedOrder();

            if ($committed !== null) {
                $queue($committed, app(SmsSettings::class));
            }
        }));
    }
}
