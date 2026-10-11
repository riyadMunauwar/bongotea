import { Link, router, useHttp, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { deliveryNote, discountLabel, feeText } from '@/components/cart/figures';
import { bn } from '@/lang/bn';
import { districtFromTyped, districtName } from '@/lang/districts';
import { toBanglaDigits, toLatinDigits } from '@/lib/bangla';
import { useCart } from '@/lib/use-cart';
import { useMoments } from '@/lib/use-moments';
import { useTaka } from '@/lib/use-taka';
import { cn } from '@/lib/utils';

type CheckoutPageProps = {
    /** Every district a parcel can go to, by the name the store stores. */
    districts: string[];
    /** The methods offered, by stored name with the logo to draw; a choice is drawn only where there is more than one. */
    paymentMethods: PaymentMethodOption[];
    /** Every published tea's name, by Product id. */
    productNames: Record<number, string>;
    /** The number this browser is Signed in as, typed in for the buyer; null when nobody is. */
    signedInPhone: string | null;
};

type PaymentMethodOption = {
    /** The name the method is stored and posted under. */
    name: string;
    /** The Merchant's uploaded logo, else the default shipped with the method. */
    logoUrl: string;
};

type CheckoutForm = {
    name: string;
    phone: string;
    district: string;
    address: string;
    shop_name: string;
    note: string;
    payment_method: string;
};

/** What the store answered when the Quote no longer agreed with it (ADR-0029). */
type Refusal = {
    current: { total: number };
    unavailable: number[];
};

/**
 * The Checkout: "কোথায় পাঠাবো?" — the delivery details beside the Cart's
 * figures, one step from the Bulk Order page to an Order.
 *
 * The Cart is this browser's, so the summary is priced here by the same
 * arithmetic the store uses, and what was shown is sent with the order as
 * its Quote. The store prices it again; where it no longer agrees — a price
 * moved, a tea can no longer be had — nothing is placed: the store's figures
 * are fetched afresh, the summary redraws from them, and the buyer confirms
 * again having seen the new total.
 */
export default function CheckoutPage() {
    const { districts, paymentMethods, productNames, signedInPhone } = usePage<CheckoutPageProps>().props;
    const { links } = usePage().props;
    const cart = useCart();
    const taka = useTaka();
    const [districtTyped, setDistrictTyped] = useState('');
    const [refusal, setRefusal] = useState<{ text: string } | null>(null);
    const [failed, setFailed] = useState(false);

    /** While the store's prices are fetched again after a refusal, confirming would only send the old Quote again. */
    const [repricing, setRepricing] = useState(false);

    /**
     * The server cannot see the browser's Cart and draws it empty, and the
     * Cart is emptied once the Order is placed; neither moment is the buyer's
     * Cart being empty, so neither draws the empty page.
     */
    const [hasMounted, setHasMounted] = useState(false);
    const [placed, setPlaced] = useState(false);

    useEffect(() => setHasMounted(true), []);

    const form = useHttp<CheckoutForm, { redirect: string }>({
        name: '',
        phone: signedInPhone ? toBanglaDigits(signedInPhone) : '',
        district: '',
        address: '',
        shop_name: '',
        note: '',
        payment_method: paymentMethods.length > 1 ? '' : paymentMethods[0].name,
    });

    const { priced, isEmpty } = cart;
    const { checkoutStarted } = useMoments();
    const startReported = useRef(false);

    /**
     * Checkout Started, once per opening and only with something in the
     * Cart. Waits for the stored Cart rather than firing on mount, because
     * the first draw is the server's empty one.
     */
    useEffect(() => {
        if (!isEmpty && !startReported.current) {
            startReported.current = true;
            checkoutStarted(priced);
        }
    }, [isEmpty, priced, checkoutStarted]);

    const nameOf = (productId: number) => productNames[productId] ?? '';

    function submit(event: React.FormEvent) {
        event.preventDefault();
        setRefusal(null);
        setFailed(false);

        const quotedTotal = priced.total;

        form.transform((data) => ({
            ...data,
            phone: toLatinDigits(data.phone),
            lines: priced.lines.map((line) => ({ productId: line.productId, kilograms: line.kilograms })),
            quote: {
                lines: priced.lines.map((line) => ({ productId: line.productId, tierPrice: line.tierPrice })),
                deliveryCharge: priced.deliveryCharge,
                total: priced.total,
            },
        }));

        form.post(links.checkout, {
            onSuccess: (response) => {
                setPlaced(true);
                cart.clear();
                window.location.assign(response.redirect);
            },
            onHttpException: (response) => {
                if (response.status !== 409) {
                    setFailed(true);

                    return;
                }

                const answer = JSON.parse(response.data) as Refusal;
                const gone = answer.unavailable.map(nameOf).filter((name) => name !== '');

                setRefusal({
                    text: [
                        bn.checkout.changedHeading,
                        bn.checkout.changed(taka(quotedTotal), taka(answer.current.total)),
                        gone.length > 0 ? bn.checkout.unavailable(gone.join(', ')) : null,
                    ]
                        .filter(Boolean)
                        .join(' '),
                });

                /** The store's prices as they are now, which the summary redraws from. */
                setRepricing(true);
                router.reload({ only: ['pricing'], onFinish: () => setRepricing(false) });
            },
            onNetworkError: () => setFailed(true),
        }).catch(() => {
            // Answered above; a refusal is not an error to raise.
        });
    }

    if (!hasMounted || placed) {
        return <div className="min-h-[60vh]" />;
    }

    if (isEmpty) {
        return (
            <div className="shell-container flex flex-col items-center gap-3 py-16 text-center md:py-24">
                <h1 className="m-0 text-[clamp(22px,3vw,30px)] font-semibold text-ink">{bn.checkout.empty}</h1>
                <p className="m-0 text-[15px] text-muted">{bn.checkout.emptyHint}</p>
                <Link href={links.bulkOrder} className="mt-2 rounded-[10px] bg-primary px-6 py-3 text-[16px] font-semibold text-white no-underline hover:bg-primary-dark hover:text-white">
                    {bn.checkout.chooseTea}
                </Link>
            </div>
        );
    }

    const note = deliveryNote(priced);

    /** A refusal of the Cart's lines or Quote rather than of a field the buyer typed in. */
    const cartError = Object.entries(form.errors as Record<string, string | undefined>).find(([key]) => key.startsWith('lines') || key.startsWith('quote'))?.[1];

    return (
        <div className="shell-container pt-6 pb-14 md:pt-10">
            <Link href={links.bulkOrder} className="mb-[6px] inline-block py-[6px] text-[14px] font-semibold text-primary no-underline">
                {bn.checkout.back}
            </Link>
            <h1 className="m-0 mb-5 text-[clamp(24px,3.4vw,40px)] leading-[1.35] font-semibold text-ink">{bn.checkout.heading}</h1>

            <form onSubmit={submit} noValidate className="grid items-start gap-7 md:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
                <div className="flex flex-col gap-4">
                    <Field label={bn.checkout.name} required error={form.errors.name}>
                        <input
                            className={inputClass(form.errors.name)}
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            placeholder={bn.checkout.namePlaceholder}
                            autoComplete="name"
                        />
                    </Field>
                    <Field label={bn.checkout.phone} required error={form.errors.phone}>
                        <input
                            className={inputClass(form.errors.phone)}
                            value={form.data.phone}
                            onChange={(event) => form.setData('phone', event.target.value)}
                            placeholder={bn.checkout.phonePlaceholder}
                            type="tel"
                            inputMode="tel"
                            autoComplete="tel"
                        />
                    </Field>
                    <Field label={bn.checkout.district} required error={form.errors.district}>
                        <input
                            className={inputClass(form.errors.district)}
                            value={districtTyped}
                            onChange={(event) => {
                                setDistrictTyped(event.target.value);
                                form.setData('district', districtFromTyped(event.target.value, districts) ?? '');
                            }}
                            placeholder={bn.checkout.districtPlaceholder}
                            list="checkout-districts"
                            autoComplete="off"
                        />
                        <datalist id="checkout-districts">
                            {districts.map((district) => (
                                <option key={district} value={districtName(district)} />
                            ))}
                        </datalist>
                    </Field>
                    <Field label={bn.checkout.address} required error={form.errors.address}>
                        <textarea
                            className={cn(inputClass(form.errors.address), 'h-auto resize-y py-3')}
                            rows={3}
                            value={form.data.address}
                            onChange={(event) => form.setData('address', event.target.value)}
                            placeholder={bn.checkout.addressPlaceholder}
                            autoComplete="street-address"
                        />
                    </Field>
                    <Field label={bn.checkout.shopName} error={form.errors.shop_name}>
                        <input
                            className={inputClass(form.errors.shop_name)}
                            value={form.data.shop_name}
                            onChange={(event) => form.setData('shop_name', event.target.value)}
                            placeholder={bn.checkout.shopNamePlaceholder}
                            autoComplete="organization"
                        />
                    </Field>
                    <Field label={bn.checkout.note} error={form.errors.note}>
                        <textarea
                            className={cn(inputClass(form.errors.note), 'h-auto resize-y py-3')}
                            rows={2}
                            value={form.data.note}
                            onChange={(event) => form.setData('note', event.target.value)}
                            placeholder={bn.checkout.notePlaceholder}
                        />
                    </Field>

                    <fieldset className="m-0 flex flex-col gap-[6px] border-0 p-0">
                        <legend className="mb-[6px] p-0 text-[15px] font-semibold">{bn.checkout.payment}</legend>
                        {paymentMethods.map((method) => (
                            <PaymentChoice
                                key={method.name}
                                method={method}
                                chosen={form.data.payment_method === method.name}
                                choosable={paymentMethods.length > 1}
                                onChoose={() => form.setData('payment_method', method.name)}
                            />
                        ))}
                        {form.errors.payment_method && <span className="text-[13px] text-error">{form.errors.payment_method}</span>}
                    </fieldset>

                    {refusal && (
                        <div role="alert" className="rounded-[8px] bg-error-soft px-3 py-[10px] text-[14px] leading-[1.6] text-error">
                            {refusal.text}
                        </div>
                    )}
                    {cartError && (
                        <div role="alert" className="rounded-[8px] bg-error-soft px-3 py-[10px] text-[14px] text-error">
                            {cartError}
                        </div>
                    )}
                    {failed && (
                        <div role="alert" className="rounded-[8px] bg-error-soft px-3 py-[10px] text-[14px] text-error">
                            {bn.checkout.failed}
                        </div>
                    )}
                </div>

                <aside className="flex flex-col gap-3 rounded-[14px] border border-border p-[clamp(18px,2vw,24px)] md:sticky md:top-[150px]">
                    <span className="text-[19px] font-semibold text-ink">{bn.checkout.summary}</span>
                    <div className="flex flex-col gap-2 border-b border-divider pb-3">
                        {priced.lines.map((line) => (
                            <div key={line.productId} className="flex justify-between gap-3 text-[14px]">
                                <span>
                                    {nameOf(line.productId)}{' '}
                                    <span className="text-muted">{bn.checkout.lineAt(toBanglaDigits(line.kilograms), taka(line.tierPrice))}</span>
                                    {line.tierPrice < line.mrp && (
                                        <>
                                            <s className="ml-1 text-muted opacity-75">{taka(line.mrp)}</s>
                                            <span className="ml-1 text-[12px] font-semibold text-primary">{discountLabel(line.mrp, line.tierPrice, taka)}</span>
                                        </>
                                    )}
                                </span>
                                <span className="font-semibold">{taka(line.lineTotal)}</span>
                            </div>
                        ))}
                    </div>
                    <SummaryLine label={bn.bulkOrder.totalQuantity} value={bn.cart.kilogramsOf(toBanglaDigits(priced.totalKilograms))} />
                    <SummaryLine label={bn.bulkOrder.average} value={taka(priced.averagePerKilogram ?? 0)} />
                    <SummaryLine label={bn.bulkOrder.teaPrice} value={taka(priced.subtotal)} />
                    <SummaryLine label={bn.bulkOrder.delivery} value={feeText(priced.deliveryCharge, taka)} accent />
                    {note && <span className="text-right text-[12px] text-eyebrow">{note}</span>}
                    <div className="flex items-baseline justify-between border-t border-divider pt-3">
                        <span className="text-[16px] font-medium">{bn.bulkOrder.total}</span>
                        <b className="text-[26px] font-bold text-primary-dark">{taka(priced.total)}</b>
                    </div>
                    <span className="text-[12px] text-muted">{bn.checkout.totalWithDelivery}</span>
                    {priced.savings > 0 && <span className="text-right text-[14px] font-semibold text-primary">{bn.bulkOrder.saving(taka(priced.savings))}</span>}
                    <button
                        type="submit"
                        disabled={form.processing || repricing}
                        className="mt-1 w-full cursor-pointer rounded-[10px] border-0 bg-primary p-[15px] text-center text-[17px] font-semibold text-white hover:bg-primary-dark disabled:cursor-wait disabled:bg-disabled"
                    >
                        {form.processing ? bn.checkout.placing : bn.checkout.confirm}
                    </button>
                </aside>
            </form>
        </div>
    );
}

function inputClass(error: string | undefined): string {
    return cn(
        'h-[50px] rounded-[10px] border bg-white px-[14px] text-[16px] text-ink outline-none focus:border-primary',
        error ? 'border-error' : 'border-input',
    );
}

function Field({ label, required = false, error, children }: { label: string; required?: boolean; error?: string; children: ReactNode }) {
    return (
        <label className="flex flex-col gap-[6px]">
            <span className="text-[15px] font-semibold">
                {label}
                {required && <span className="text-error"> *</span>}
            </span>
            {children}
            {error && <span className="text-[13px] text-error">{error}</span>}
        </label>
    );
}

/** One way to pay, its logo beside its name: a radio card where there is a choice, the mockup's fixed card where there is none. */
function PaymentChoice({ method, chosen, choosable, onChoose }: { method: PaymentMethodOption; chosen: boolean; choosable: boolean; onChoose: () => void }) {
    const selected = chosen || !choosable;
    const name = bn.paymentMethods[method.name] ?? method.name;

    return (
        <label
            className={cn(
                'flex items-center gap-3 rounded-[10px] border p-[14px]',
                selected ? 'border-primary bg-green-50' : 'border-border bg-white',
                choosable && 'cursor-pointer',
            )}
        >
            {choosable ? (
                <input type="radio" name="payment_method" value={method.name} checked={chosen} onChange={onChoose} className="size-[18px] accent-primary" />
            ) : (
                <span aria-hidden className="box-border size-[18px] flex-none rounded-full border-[5px] border-primary bg-white" />
            )}
            <img src={method.logoUrl} alt={name} className="block h-8 w-auto max-w-[96px] flex-none object-contain" />
            <span className="flex flex-col">
                <b className="text-[15px] font-semibold">{name}</b>
                <span className="text-[13px] text-muted">{method.name === 'cash_on_delivery' ? bn.checkout.cashOnDeliveryHint : bn.checkout.onlineHint}</span>
            </span>
        </label>
    );
}

function SummaryLine({ label, value, accent = false }: { label: string; value: string; accent?: boolean }) {
    return (
        <div className="flex justify-between text-[14px]">
            <span className="text-muted">{label}</span>
            <b className={cn('font-semibold', accent && 'text-primary')}>{value}</b>
        </div>
    );
}
