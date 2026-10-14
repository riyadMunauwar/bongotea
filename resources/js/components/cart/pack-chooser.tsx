import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { BagIcon } from '@/components/storefront/icons';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { MAX_PACKS, type CartPack } from '@/lib/retail-cart';
import { withReturnPath } from '@/lib/return-path';
import { useRetailCart } from '@/lib/use-retail-cart';
import { useTaka } from '@/lib/use-taka';
import { cn } from '@/lib/utils';
import { packName } from './figures';

/**
 * The খুচরা কিনুন button, for a Product open to retail and nothing else
 * (ADR-0034): it opens the pack chooser, which puts the chosen Packs in the
 * খুচরা কার্ট and goes straight on to it. A Product with no Packs
 * draws nothing. The kilogram stepper
 * beside it is the bulk Cart's and is not touched.
 */
export function PackChooserButton({ productId, productName, className }: { productId: number; productName: string; className?: string }) {
    const retail = useRetailCart();
    const packs = retail.packsOf(productId);
    const [open, setOpen] = useState(false);

    if (packs.length === 0) {
        return null;
    }

    const held = packs.reduce((sum, pack) => sum + retail.countOf(pack.id), 0);

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className={cn(
                    'box-border flex w-full cursor-pointer items-center justify-center gap-2 rounded-[8px] border border-primary bg-white px-[6px] py-[9px] text-[13px] font-semibold text-primary-dark hover:bg-green-50 md:rounded-[10px] md:p-[11px] md:text-[15px]',
                    className,
                )}
            >
                <BagIcon size={18} className="max-md:hidden" />
                {held > 0 ? (
                    bn.packs.inBasket(toBanglaDigits(held))
                ) : (
                    <>
                        {bn.packs.buy} <span className="font-normal opacity-80">· {bn.packs.buyUnit}</span>
                    </>
                )}
            </button>
            <PackChooser productName={productName} packs={open ? packs : null} onClose={() => setOpen(false)} />
        </>
    );
}

/**
 * Each of a Product's Packs with its Pack Price, its MRP struck through and
 * the saving, a count, and the add button. A Pack that cannot be ordered now
 * is greyed and cannot be picked. A native `<dialog>`, so Escape closes it.
 */
function PackChooser({ productName, packs, onClose }: { productName: string; packs: CartPack[] | null; onClose: () => void }) {
    const dialog = useRef<HTMLDialogElement>(null);
    const { props, url } = usePage();
    const { links } = props;
    const retail = useRetailCart();
    const taka = useTaka();
    const [chosen, setChosen] = useState<number | null>(null);
    const [count, setCount] = useState(1);
    /** What may still be added of the chosen Pack before its tea passes its খুচরা maximum, with what the basket already holds of it. */
    const most = chosen === null ? MAX_PACKS : Math.max(1, retail.mostOf(chosen) - retail.countOf(chosen));

    useEffect(() => {
        const element = dialog.current;

        if (!element) {
            return;
        }

        if (packs && !element.open) {
            setChosen(packs.find((pack) => pack.isOrderable)?.id ?? null);
            setCount(1);
            element.showModal();
        }

        if (!packs && element.open) {
            element.close();
        }
    }, [packs]);

    /** Adds the chosen Pack and goes straight on to the খুচরা কার্ট, remembering this page for "আরও চা যোগ করুন". */
    const buyNow = () => {
        if (chosen === null) {
            return;
        }

        retail.set(chosen, retail.countOf(chosen) + Math.min(count, most));
        onClose();
        router.visit(withReturnPath(links.packetBasket, url));
    };

    return (
        <dialog
            ref={dialog}
            aria-label={bn.packs.chooserHeading}
            onClose={onClose}
            onClick={(event) => event.target === event.currentTarget && onClose()}
            className="m-auto w-[calc(100%-32px)] max-w-[420px] rounded-[16px] border-0 bg-white p-0 text-ink backdrop:bg-scrim"
        >
            {packs && (
                <div className="flex flex-col gap-4 p-5">
                    <div className="flex items-start justify-between gap-3">
                        <div className="flex flex-col gap-1">
                            <span className="text-[13px] font-semibold text-eyebrow">{bn.packs.chooserHeading}</span>
                            <b className="text-[18px] leading-[1.35] font-bold">{productName}</b>
                        </div>
                        <button type="button" onClick={onClose} aria-label={bn.packs.close} className="flex size-9 flex-none cursor-pointer items-center justify-center rounded-full border-0 bg-surface text-[18px] text-ink">
                            ✕
                        </button>
                    </div>

                    <div role="radiogroup" aria-label={bn.packs.chooserHeading} className="flex flex-col gap-2">
                        {packs.map((pack) => (
                            <button
                                key={pack.id}
                                type="button"
                                role="radio"
                                aria-checked={chosen === pack.id}
                                disabled={!pack.isOrderable}
                                onClick={() => setChosen(pack.id)}
                                className={cn(
                                    'flex cursor-pointer items-center justify-between gap-3 rounded-[10px] border bg-white px-4 py-3 text-left disabled:cursor-not-allowed disabled:opacity-50',
                                    chosen === pack.id ? 'border-primary bg-green-50' : 'border-border',
                                )}
                            >
                                <b className="text-[16px] font-bold">{packName(pack.grams)}</b>
                                {pack.isOrderable ? (
                                    <span className="flex flex-wrap items-baseline justify-end gap-x-2">
                                        <b className="text-[16px] font-bold text-primary-dark">{taka(pack.price)}</b>
                                        {pack.price < pack.mrp && (
                                            <>
                                                <s className="text-[13px] text-muted">{taka(pack.mrp)}</s>
                                                <span className="text-[12px] font-semibold text-primary">{bn.packs.saving(taka(pack.mrp - pack.price))}</span>
                                            </>
                                        )}
                                    </span>
                                ) : (
                                    <span className="text-[13px] font-semibold text-muted">{bn.packs.unavailable}</span>
                                )}
                            </button>
                        ))}
                    </div>

                    <div className="flex items-center justify-between gap-3">
                        <span className="text-[14px] text-muted">{bn.packs.count}</span>
                        <PackStepper count={Math.min(count, most)} max={most} onChange={(next) => setCount(Math.min(most, Math.max(1, next)))} />
                    </div>

                    <button
                        type="button"
                        disabled={chosen === null}
                        onClick={buyNow}
                        className="cursor-pointer rounded-[10px] border-0 bg-primary px-4 py-[13px] text-[15px] font-bold text-white hover:bg-primary-dark disabled:cursor-not-allowed disabled:bg-disabled"
                    >
                        {bn.packs.add}
                    </button>
                </div>
            )}
        </dialog>
    );
}

/** −/+ around a count of Packs, + stopping at `max` where given; what is asked for is clamped by whoever holds it. */
export function PackStepper({ count, onChange, max, className }: { count: number; onChange: (count: number) => void; max?: number; className?: string }) {
    const button = 'flex size-9 flex-none cursor-pointer items-center justify-center border-0 bg-transparent p-0 text-[20px] text-primary-dark';

    return (
        <div className={cn('flex items-center rounded-[8px] border border-primary bg-green-50', className)}>
            <button type="button" aria-label={bn.cart.decrease} onClick={() => onChange(count - 1)} className={button}>
                −
            </button>
            <span className="min-w-[44px] text-center text-[15px] font-bold text-primary-dark">{bn.packs.countOf(toBanglaDigits(count))}</span>
            <button
                type="button"
                aria-label={bn.cart.increase}
                disabled={max !== undefined && count >= max}
                onClick={() => onChange(count + 1)}
                className={cn(button, 'disabled:cursor-not-allowed disabled:opacity-40')}
            >
                +
            </button>
        </div>
    );
}
