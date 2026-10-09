import { Link, router, useHttp, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { bn } from '@/lang/bn';
import { formatPhone, toBanglaDigits, toLatinDigits } from '@/lib/bangla';
import { useTaka } from '@/lib/use-taka';
import { cn } from '@/lib/utils';
import type { MyOrdersProps, OrderProgress } from '@/types/blocks';

type Order = MyOrdersProps['orders'][number];

/** How many of the four steps an Order has behind it; the next is the one under way. */
const STEPS_DONE: Record<Exclude<OrderProgress, 'cancelled'>, number> = {
    received: 1,
    confirmed: 2,
    sent: 3,
    delivered: 4,
};

/**
 * My Orders: a phone number and a One-Time Code for a stranger, and for a
 * Customer Signed in every Order placed with their number, each with its
 * four-step tracker — or বাতিল in its place. Whether this browser is signed
 * in is the server's to say: once it changes, the Page is drawn again.
 */
export function MyOrders({ heading, text, phone, orders }: MyOrdersProps) {
    return (
        <section className="mx-auto flex w-full max-w-[720px] flex-col gap-6 px-[clamp(16px,4vw,32px)] pt-8 pb-14 md:pt-12">
            {phone === null ? <SignIn heading={heading} text={text} /> : <SignedIn heading={heading} phone={phone} orders={orders} />}
        </section>
    );
}

function SignIn({ heading, text }: { heading: string | null; text: string | null }) {
    const { links } = usePage().props;
    const [step, setStep] = useState<'phone' | 'code'>('phone');
    const [notice, setNotice] = useState<string | null>(null);
    const form = useHttp<{ phone: string; code: string }>({ phone: '', code: '' });

    const answered = {
        onHttpException: (response: { status: number }) => {
            if (response.status !== 422) {
                setNotice(response.status === 429 ? bn.myOrders.tooMany : bn.myOrders.failed);
            }
        },
        onNetworkError: () => setNotice(bn.myOrders.failed),
    };

    function sendCode(event?: React.FormEvent) {
        event?.preventDefault();
        setNotice(null);
        form.transform((data) => ({ phone: toLatinDigits(data.phone) }));
        form.post(links.signInCode, {
            ...answered,
            onSuccess: () => {
                form.setData('code', '');
                setStep('code');
            },
        }).catch(() => {
            // Answered above.
        });
    }

    function signIn(event: React.FormEvent) {
        event.preventDefault();
        setNotice(null);
        form.transform((data) => ({ phone: toLatinDigits(data.phone), code: toLatinDigits(data.code) }));
        form.post(links.signIn, { ...answered, onSuccess: () => router.reload() }).catch(() => {
            // Answered above.
        });
    }

    const error = notice ?? form.errors.phone ?? form.errors.code;

    return (
        <div className="mx-auto mt-[clamp(12px,4vw,48px)] flex w-full max-w-[420px] flex-col gap-4">
            <h1 className="m-0 text-[clamp(26px,3.2vw,36px)] leading-[1.3] font-semibold text-ink">{heading ?? bn.myOrders.heading}</h1>
            <p className="m-0 text-[15px] leading-[1.7] text-muted">{text ?? bn.myOrders.intro}</p>

            {step === 'phone' ? (
                <form onSubmit={sendCode} noValidate className="flex flex-col gap-4">
                    <label className="flex flex-col gap-[6px]">
                        <span className="text-[15px] font-semibold">{bn.myOrders.phone}</span>
                        <input
                            className="h-[54px] rounded-[10px] border border-input bg-white px-[14px] text-[18px] text-ink outline-none focus:border-primary"
                            value={form.data.phone}
                            onChange={(event) => form.setData('phone', event.target.value)}
                            placeholder={bn.myOrders.phonePlaceholder}
                            type="tel"
                            inputMode="tel"
                            autoComplete="tel"
                        />
                    </label>
                    <SubmitButton busy={form.processing} label={bn.myOrders.sendCode} busyLabel={bn.myOrders.sending} />
                </form>
            ) : (
                <form onSubmit={signIn} noValidate className="flex flex-col gap-4">
                    <span className="text-[14px] text-muted">
                        {bn.myOrders.codeSent(formatPhone(form.data.phone))}{' '}
                        <button
                            type="button"
                            onClick={() => {
                                setNotice(null);
                                form.clearErrors();
                                setStep('phone');
                            }}
                            className="cursor-pointer border-0 bg-transparent p-0 font-semibold text-primary"
                        >
                            {bn.myOrders.changeNumber}
                        </button>
                    </span>
                    <input
                        aria-label={bn.myOrders.code}
                        className="h-[60px] rounded-[10px] border border-input bg-white text-center text-[26px] tracking-[.5em] text-ink outline-none focus:border-primary"
                        value={form.data.code}
                        onChange={(event) => form.setData('code', event.target.value.slice(0, 4))}
                        placeholder={bn.myOrders.codePlaceholder}
                        inputMode="numeric"
                        autoComplete="one-time-code"
                    />
                    <SubmitButton busy={form.processing} label={bn.myOrders.signIn} busyLabel={bn.myOrders.signingIn} />
                    <button
                        type="button"
                        onClick={() => sendCode()}
                        disabled={form.processing}
                        className="cursor-pointer border-0 bg-transparent p-0 text-[14px] font-semibold text-primary disabled:cursor-wait"
                    >
                        {bn.myOrders.resend}
                    </button>
                </form>
            )}

            {error && (
                <span role="alert" className="rounded-[8px] bg-error-soft px-3 py-[10px] text-[14px] text-error">
                    {error}
                </span>
            )}
        </div>
    );
}

function SubmitButton({ busy, label, busyLabel }: { busy: boolean; label: string; busyLabel: string }) {
    return (
        <button
            type="submit"
            disabled={busy}
            className="cursor-pointer rounded-[10px] border-0 bg-primary p-[15px] text-center text-[16px] font-semibold text-white hover:bg-primary-dark disabled:cursor-wait disabled:bg-disabled"
        >
            {busy ? busyLabel : label}
        </button>
    );
}

function SignedIn({ heading, phone, orders }: { heading: string | null; phone: string; orders: Order[] }) {
    const { links } = usePage().props;
    const [open, setOpen] = useState<string | null>(orders[0]?.number ?? null);
    const signOut = useHttp({});

    return (
        <>
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="m-0 text-[clamp(26px,3.2vw,36px)] leading-[1.3] font-semibold text-ink">{heading ?? bn.myOrders.heading}</h1>
                    <span className="text-[14px] text-muted">{formatPhone(phone)}</span>
                </div>
                <button
                    type="button"
                    disabled={signOut.processing}
                    onClick={() => {
                        signOut.post(links.signOut, { onSuccess: () => router.reload() }).catch(() => {
                            // Nothing to tell: the browser is still signed in, as it shows.
                        });
                    }}
                    className="cursor-pointer rounded-[8px] border border-input bg-white px-[14px] py-2 text-[14px] font-semibold text-muted disabled:cursor-wait"
                >
                    {bn.myOrders.signOut}
                </button>
            </div>

            {orders.length === 0 ? (
                <div className="flex flex-col items-start gap-3 rounded-[14px] border border-border p-5">
                    <span className="text-[15px] text-muted">{bn.myOrders.none}</span>
                    <Link href={links.bulkOrder} className="rounded-[10px] bg-primary px-5 py-3 text-[15px] font-semibold text-white no-underline hover:bg-primary-dark hover:text-white">
                        {bn.myOrders.orderNow}
                    </Link>
                </div>
            ) : (
                <div className="flex flex-col gap-3">
                    {orders.map((order) => (
                        <OrderCard key={order.number} order={order} open={open === order.number} onToggle={() => setOpen(open === order.number ? null : order.number)} />
                    ))}
                </div>
            )}
        </>
    );
}

function OrderCard({ order, open, onToggle }: { order: Order; open: boolean; onToggle: () => void }) {
    const taka = useTaka();
    const { store } = usePage().props;
    const cancelled = order.progress === 'cancelled';
    const done = order.progress === 'cancelled' ? 0 : STEPS_DONE[order.progress];
    const delivered = order.progress === 'delivered';

    return (
        <div className="overflow-hidden rounded-[14px] border border-border">
            <button type="button" onClick={onToggle} aria-expanded={open} className="flex w-full cursor-pointer items-center gap-[14px] border-0 bg-transparent px-[18px] py-4 text-left">
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <div className="flex flex-wrap items-center gap-[10px]">
                        <b className="text-[16px] font-semibold text-ink">{order.number}</b>
                        <span
                            className={cn(
                                'rounded-full px-[10px] py-[3px] text-[12px] font-semibold',
                                cancelled ? 'bg-error-soft text-error' : delivered ? 'bg-green-50 text-primary-dark' : 'bg-progress-tint text-mail-text',
                            )}
                        >
                            {cancelled ? bn.myOrders.cancelled : delivered ? bn.myOrders.steps[bn.myOrders.steps.length - 1].title : bn.myOrders.inProgress(bn.myOrders.steps[done].title)}
                        </span>
                    </div>
                    <span className="text-[13px] text-muted">
                        {placedOn(order.placedOn)} · {bn.myOrders.kilograms(toBanglaDigits(order.kilograms))} · {taka(order.total)}
                    </span>
                </div>
                <span aria-hidden className="flex size-8 flex-none items-center justify-center rounded-full border border-input text-[18px] text-primary">
                    {open ? '−' : '+'}
                </span>
            </button>

            {open && (
                <div className="flex flex-col gap-4 px-[18px] pt-1 pb-5">
                    <span className="rounded-[8px] bg-surface px-3 py-[10px] text-[14px] text-ink">
                        {order.lines.map((line) => bn.myOrders.line(line.name, toBanglaDigits(line.kilograms))).join(', ')}
                    </span>
                    {cancelled ? <span className="text-[14px] text-error">{bn.myOrders.cancelledNote}</span> : <Tracker done={done} />}
                    {store.hotline && <span className="text-[13px] text-muted">{bn.myOrders.questions(formatPhone(store.hotline.number))}</span>}
                </div>
            )}
        </div>
    );
}

/** The four steps, those behind the Order ticked and the one under way marked "এখন". */
function Tracker({ done }: { done: number }) {
    const steps = bn.myOrders.steps;

    return (
        <ol className="m-0 flex list-none flex-col p-0">
            {steps.map((step, index) => {
                const complete = index < done;
                const current = index === done;

                return (
                    <li key={step.title} className="flex gap-[14px]">
                        <div className="flex flex-col items-center">
                            <span
                                className={cn(
                                    'box-border flex size-[22px] flex-none items-center justify-center rounded-full border-2 text-[12px] font-bold text-white',
                                    complete ? 'border-primary bg-primary' : current ? 'border-primary bg-white' : 'border-input bg-white',
                                )}
                            >
                                {complete ? '✓' : ''}
                            </span>
                            {index < steps.length - 1 && <span className={cn('block min-h-[26px] w-[2px] flex-1', index < done ? 'bg-primary' : 'bg-border')} />}
                        </div>
                        <div className="flex flex-col gap-[2px] pb-4">
                            <b className={cn('text-[15px] leading-[22px] font-semibold', complete || current ? 'text-ink' : 'text-faint')}>
                                {step.title}
                                {current && <span className="ml-2 text-[12px] font-semibold text-mail-text">{bn.myOrders.now}</span>}
                            </b>
                            <span className="text-[13px] text-muted">{step.text}</span>
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}

/** The day an Order was placed, as a Bangla reader writes it: ৩ অক্টোবর ২০২৬. */
function placedOn(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return bn.myOrders.placedOn(toBanglaDigits(day), bn.myOrders.months[month - 1], toBanglaDigits(year));
}
