import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { feeText, packName, retailDeliveryNote } from '@/components/cart/figures';
import { PackStepper } from '@/components/cart/pack-chooser';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { returnPath } from '@/lib/return-path';
import type { HeldPack } from '@/lib/retail-cart';
import { useRetailCart, type UseRetailCart } from '@/lib/use-retail-cart';
import { useTaka } from '@/lib/use-taka';
import { cn } from '@/lib/utils';
import type { PacketBasketProps } from '@/types/blocks';
import { Picture, SectionHeading } from './shared';

/**
 * The packet basket (খুচরা কার্ট) as a page (ADR-0034): each Pack with its
 * tea, size, Pack Price, a stepper, a remove button and its line total; what
 * can no longer be ordered flagged and left out of every figure; and beside
 * it the subtotal, the retail Delivery Charge, the total and how far free
 * delivery is. "আরও চা যোগ করুন" goes back to the page the last Pack was
 * bought from. An empty basket points back to the shop. Every figure comes
 * from the retail pricing mirror, never from the bulk Cart.
 */
export function PacketBasket({ heading, text }: PacketBasketProps) {
    const retail = useRetailCart();
    const isBare = retail.isEmpty && retail.unorderable.length === 0;
    const back = useReturnPath();

    return (
        <section className="pt-6 pb-10 md:pt-10 md:pb-14">
            <div className="shell-container flex flex-col gap-4 md:gap-6">
                <SectionHeading heading={heading} text={text} />

                {isBare ? (
                    <EmptyBasket />
                ) : (
                    <div className="grid items-start gap-6 md:grid-cols-[minmax(0,1fr)_360px] md:gap-8">
                        <div className="flex flex-col gap-2">
                            {retail.lines.map((line) => (
                                <BasketLine key={line.pack.id} line={line} retail={retail} />
                            ))}
                            {retail.unorderable.map((line) => (
                                <UnorderableLine key={line.packId} name={line.pack === null ? null : `${line.pack.productName} · ${packName(line.pack.grams)}`} onRemove={() => retail.remove(line.packId)} />
                            ))}
                            <Link href={back} className="self-start py-1 text-[14px] font-semibold text-primary no-underline">
                                + {bn.packs.addMore}
                            </Link>
                        </div>
                        <Summary retail={retail} />
                    </div>
                )}
            </div>
        </section>
    );
}

/** Where "আরও চা যোগ করুন" goes; the home page until mounted, because the server has no address bar to read. */
function useReturnPath(): string {
    const [back, setBack] = useState('/');

    useEffect(() => setBack(returnPath(window.location.search)), []);

    return back;
}

function EmptyBasket() {
    return (
        <div className="flex flex-col items-center gap-3 rounded-[14px] border border-border bg-surface px-5 py-10 text-center">
            <b className="text-[18px] font-semibold text-ink">{bn.packs.empty}</b>
            <span className="max-w-[420px] text-[15px] leading-[1.6] text-muted">{bn.packs.emptyHint}</span>
            <Link href="/" className="rounded-[10px] bg-primary px-6 py-3 text-[15px] font-semibold text-white no-underline hover:bg-primary-dark hover:text-white">
                {bn.packs.shop}
            </Link>
        </div>
    );
}

function BasketLine({ line, retail }: { line: HeldPack; retail: UseRetailCart }) {
    const taka = useTaka();
    const { pack, count } = line;

    return (
        <div className="flex items-center gap-3 rounded-[12px] border border-border bg-white p-3">
            <Picture src={pack.imageUrl} alt="" stripes="stripes-light" className="size-14 flex-none rounded-[8px]" />
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <Link href={pack.href} className="truncate text-[15px] font-semibold text-ink no-underline hover:text-primary">
                    {pack.productName}
                </Link>
                <span className="text-[13px] text-muted">
                    {packName(pack.grams)} · {bn.packs.lineAt(toBanglaDigits(count), taka(pack.price))}
                    {pack.price < pack.mrp && (
                        <>
                            {' '}
                            <s>{taka(pack.mrp)}</s>
                        </>
                    )}
                </span>
                <div className="flex items-center gap-3">
                    <PackStepper count={count} onChange={(next) => retail.set(pack.id, next)} />
                    <button type="button" onClick={() => retail.remove(pack.id)} className="cursor-pointer border-0 bg-transparent p-0 text-[13px] font-semibold text-muted underline">
                        {bn.packs.remove}
                    </button>
                </div>
            </div>
            <b className="self-start text-[16px] font-bold text-ink">{taka(pack.price * count)}</b>
        </div>
    );
}

/** A Pack held that cannot be ordered now: named where the store still knows it, and only removable. */
function UnorderableLine({ name, onRemove }: { name: string | null; onRemove: () => void }) {
    return (
        <div className="flex items-center justify-between gap-3 rounded-[12px] border border-dashed border-border bg-surface p-3 opacity-80">
            <span className="flex min-w-0 flex-col gap-1">
                <span className="truncate text-[15px] font-semibold text-muted">{name ?? bn.packs.gone}</span>
                {name !== null && <span className="text-[13px] text-muted">{bn.packs.unorderable}</span>}
            </span>
            <button type="button" onClick={onRemove} className="cursor-pointer border-0 bg-transparent p-0 text-[13px] font-semibold text-muted underline">
                {bn.packs.remove}
            </button>
        </div>
    );
}

function Summary({ retail }: { retail: UseRetailCart }) {
    const taka = useTaka();
    const { links } = usePage().props;
    const { priced, isEmpty } = retail;
    const note = retailDeliveryNote(priced, taka);

    return (
        <aside className="flex flex-col gap-4 rounded-[14px] border border-border bg-white p-5 md:sticky md:top-[150px] md:p-6">
            <span className="text-[20px] font-semibold text-ink">{bn.packs.summary}</span>
            <div className="flex flex-col gap-[10px]">
                <SummaryLine label={bn.packs.packCount} value={bn.packs.countOf(toBanglaDigits(priced.packCount))} />
                <SummaryLine label={bn.packs.subtotal} value={taka(priced.subtotal)} />
                <SummaryLine label={bn.packs.delivery} value={isEmpty ? bn.cart.noFigure : feeText(priced.deliveryCharge, taka)} accent />
                {note && <span className="text-right text-[12px] text-eyebrow">{note}</span>}
                <div className="flex items-baseline justify-between border-t border-divider pt-[14px]">
                    <span className="text-[16px] font-medium">{bn.packs.total}</span>
                    <b className="text-[28px] font-bold text-primary-dark">{taka(priced.total)}</b>
                </div>
                {priced.savings > 0 && <div className="text-right text-[14px] text-primary">{bn.packs.totalSaving(taka(priced.savings))}</div>}
            </div>
            <button
                type="button"
                disabled={isEmpty}
                onClick={() => router.visit(`${links.checkout}?cart=retail`)}
                className={cn(
                    'w-full cursor-pointer rounded-[10px] border-0 p-[15px] text-center text-[16px] font-semibold text-white disabled:cursor-not-allowed',
                    isEmpty ? 'bg-disabled' : 'bg-primary hover:bg-primary-dark',
                )}
            >
                {bn.packs.checkout}
            </button>
        </aside>
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
