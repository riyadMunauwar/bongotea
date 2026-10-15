import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { BagIcon } from '@/components/storefront/icons';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { MAX_PACKS, type CartPack } from '@/lib/retail-cart';
import { withReturnPath } from '@/lib/return-path';
import { useAdding } from '@/lib/use-adding';
import { useRetailCart } from '@/lib/use-retail-cart';
import { useTaka } from '@/lib/use-taka';
import { cn } from '@/lib/utils';
import { useCartChoice } from './cart-chooser';
import { packName } from './figures';

/**
 * The খুচরা কিনুন button, for a Product open to retail and nothing else
 * (ADR-0034): it opens the pack chooser, whose Packs go — by the add-rule
 * (ADR-0038) — into the one Cart the tea is assigned to, or the Cart the
 * buyer picks, on top of what that Pack holds there, and the buyer is taken
 * on to that Cart. It shows no Cart's quantity, since it cannot know which
 * Cart it will reach. A Product with no Packs draws nothing.
 */
export function PackChooserButton({ productId, productName, cartIds, className }: { productId: number; productName: string; cartIds: number[]; className?: string }) {
    const { url } = usePage();
    const retail = useRetailCart(null);
    const adding = useAdding();
    const { choose, chooser } = useCartChoice();
    const packs = retail.packsOf(productId);
    const [open, setOpen] = useState(false);

    if (packs.length === 0) {
        return null;
    }

    /** Adds the chosen Packs to the Cart the add-rule names and goes straight on to it, remembering this page for "আরও চা যোগ করুন". */
    const add = (packId: number, count: number) => {
        setOpen(false);
        choose(adding.ruleFor(cartIds, 'retail'), (cart) => {
            adding.addPacks(cart.id, packId, count);
            router.visit(withReturnPath(cart.href, url));
        });
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className={cn(
                    'box-border flex w-full cursor-pointer items-center justify-center gap-[6px] rounded-[8px] border border-primary bg-white px-1 py-[7px] text-[12px] font-semibold text-primary-dark hover:bg-green-50 md:text-[13px]',
                    className,
                )}
            >
                <BagIcon size={15} className="max-md:hidden" />
                {bn.packs.buy} <span className="font-normal opacity-80">· {bn.packs.buyUnit}</span>
            </button>
            <PackChooser productName={productName} packs={open ? packs : null} onAdd={add} onClose={() => setOpen(false)} />
            {chooser}
        </>
    );
}

/**
 * Each of a Product's Packs with its Pack Price, its MRP struck through and
 * the saving, a count, and the add button. A Pack that cannot be ordered now
 * is greyed and cannot be picked, and so is one স্টক শেষ — heavier than what
 * is free of its tea. A native `<dialog>`, so Escape closes it.
 */
function PackChooser({ productName, packs, onAdd, onClose }: { productName: string; packs: CartPack[] | null; onAdd: (packId: number, count: number) => void; onClose: () => void }) {
    const dialog = useRef<HTMLDialogElement>(null);
    const retail = useRetailCart(null);
    const taka = useTaka();
    const [chosen, setChosen] = useState<number | null>(null);
    const [count, setCount] = useState(1);
    /** The most of the chosen Pack its tea's খুচরা maximum or what is free allows; the Cart it reaches stops it again, with what it holds there. */
    const most = chosen === null ? MAX_PACKS : Math.max(1, retail.mostOf(chosen));
    const isPickable = (pack: CartPack) => pack.isOrderable && !retail.isSoldOut(pack.id);

    useEffect(() => {
        const element = dialog.current;

        if (!element) {
            return;
        }

        if (packs && !element.open) {
            setChosen(packs.find(isPickable)?.id ?? null);
            setCount(1);
            element.showModal();
        }

        if (!packs && element.open) {
            element.close();
        }
    }, [packs]);

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
                                disabled={!isPickable(pack)}
                                onClick={() => setChosen(pack.id)}
                                className={cn(
                                    'flex cursor-pointer items-center justify-between gap-3 rounded-[10px] border bg-white px-4 py-3 text-left disabled:cursor-not-allowed disabled:opacity-50',
                                    chosen === pack.id ? 'border-primary bg-green-50' : 'border-border',
                                )}
                            >
                                <b className="text-[16px] font-bold">{packName(pack.grams)}</b>
                                {isPickable(pack) ? (
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
                                    <span className="text-[13px] font-semibold text-muted">{pack.isOrderable ? bn.packs.soldOut : bn.packs.unavailable}</span>
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
                        onClick={() => chosen !== null && onAdd(chosen, Math.min(count, most))}
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
