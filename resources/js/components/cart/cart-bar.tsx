import { useState } from 'react';
import { MenuAnchor } from '@/components/storefront/menu-anchor';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { barCarts } from '@/lib/current-cart';
import { useCart } from '@/lib/use-cart';
import { useCurrentCarts, type HeldCartInfo } from '@/lib/use-current-carts';
import { useRetailCart } from '@/lib/use-retail-cart';
import { useTaka } from '@/lib/use-taka';
import { cn } from '@/lib/utils';

/**
 * The slim green bar that sits at the bottom of the screen while any Cart
 * holds anything (ADR-0038): first a row for each Current Cart that does,
 * named — a bulk Cart with its Kilograms and total, a retail one with its
 * Packs and total, leading to its page — then, where other Carts hold
 * something, one "+N আরও কার্ট" row opening the rest, so the bar stays slim
 * and no Cart filled can hide. Two Carts are never added together. A closed
 * Cart's row says বন্ধ and offers only মুছুন. Each row empties its own Cart,
 * and only that one, once the buyer says yes.
 */
export function CartBar() {
    const currentCarts = useCurrentCarts();
    const [showsMore, setShowsMore] = useState(false);
    const { shown, more } = barCarts({ current: [currentCarts.bulk?.id ?? null, currentCarts.retail?.id ?? null], holding: currentCarts.held });

    if (shown.length + more.length === 0) {
        return null;
    }

    return (
        <div className="sticky bottom-3 z-10 mx-auto my-3 box-border flex w-[calc(100%-24px)] max-w-[480px] flex-col gap-[6px]">
            {shown.map((cart) => (
                <HeldCartRow key={cart.id} cart={cart} />
            ))}
            {more.length > 0 &&
                (showsMore ? (
                    more.map((cart) => <HeldCartRow key={cart.id} cart={cart} />)
                ) : (
                    <button
                        type="button"
                        onClick={() => setShowsMore(true)}
                        className="box-border flex min-h-[40px] cursor-pointer items-center justify-center rounded-[12px] border-0 bg-primary-dark px-3 text-[13px] font-bold text-white shadow-cart-bar hover:bg-primary"
                    >
                        {bn.cart.moreCarts(toBanglaDigits(more.length))}
                    </button>
                ))}
        </div>
    );
}

/** One Cart's row: a closed Cart's বন্ধ row, or an open one's figures. */
function HeldCartRow({ cart }: { cart: HeldCartInfo }) {
    if (!cart.isOpen || cart.href === null) {
        return <ClosedCartRow cart={cart} />;
    }

    return cart.kind === 'bulk' ? <BulkCartRow cart={cart} href={cart.href} /> : <RetailCartRow cart={cart} href={cart.href} />;
}

function BulkCartRow({ cart, href }: { cart: HeldCartInfo; href: string }) {
    const contents = useCart(cart.id);
    const taka = useTaka();

    return <CartBarRow name={cart.name} size={bn.packs.kilograms(toBanglaDigits(contents.priced.totalKilograms))} total={taka(contents.priced.total)} href={href} onClear={contents.clear} />;
}

function RetailCartRow({ cart, href }: { cart: HeldCartInfo; href: string }) {
    const contents = useRetailCart(cart.id);
    const taka = useTaka();

    return <CartBarRow name={cart.name} size={bn.cart.barPacks(toBanglaDigits(contents.priced.packCount))} total={taka(contents.priced.total)} href={href} onClear={contents.clear} />;
}

/**
 * Contents kept in a Cart that has since closed — switched off, on no Page,
 * or deleted: named, marked বন্ধ, and only removable, never dropped behind
 * the buyer's back. Reopened, the Cart finds them where they were left.
 */
function ClosedCartRow({ cart }: { cart: HeldCartInfo }) {
    const bulk = useCart(cart.kind === 'bulk' ? cart.id : null);
    const retail = useRetailCart(cart.kind === 'retail' ? cart.id : null);

    return <CartBarRow name={cart.name} size={bn.cart.closed} total={null} href={null} onClear={cart.kind === 'bulk' ? bulk.clear : retail.clear} />;
}

/** A row: the Cart's name and figures, its confirmed মুছুন, and — while it is open — the way to its page. A closed Cart has no total and no page. */
function CartBarRow({ name, size, total, href, onClear }: { name: string; size: string; total: string | null; href: string | null; onClear: () => void }) {
    const [isConfirming, setIsConfirming] = useState(false);
    const confirmClear = bn.cart.confirmClearOne(name);

    return (
        <div className={cn('box-border flex min-h-[48px] items-center gap-2 rounded-[12px] py-[6px] pr-[6px] pl-3 text-white shadow-cart-bar', href === null ? 'bg-muted' : 'bg-primary')}>
            {isConfirming ? (
                <span role="alertdialog" aria-label={confirmClear} className="flex min-w-0 flex-1 items-center gap-[6px]">
                    <span className="min-w-0 flex-1 text-[13px] leading-tight font-semibold">{confirmClear}</span>
                    <button
                        type="button"
                        onClick={() => {
                            onClear();
                            setIsConfirming(false);
                        }}
                        className="cursor-pointer rounded-[8px] border-0 bg-error px-3 py-[7px] text-[13px] font-bold text-white"
                    >
                        {bn.calculator.confirmYes}
                    </button>
                    <button type="button" onClick={() => setIsConfirming(false)} className="cursor-pointer rounded-[8px] border-0 bg-white px-3 py-[7px] text-[13px] font-bold text-ink">
                        {bn.calculator.confirmNo}
                    </button>
                </span>
            ) : (
                <>
                    <span className="flex min-w-0 flex-1 flex-col leading-tight">
                        <b className="truncate text-[13px] font-bold">{name}</b>
                        <span className="truncate text-[12px] text-white/85">
                            {size}
                            {total !== null && (
                                <>
                                    {' · '}
                                    <b className="font-bold text-white">{total}</b>
                                </>
                            )}
                        </span>
                    </span>
                    <button
                        type="button"
                        onClick={() => setIsConfirming(true)}
                        className="cursor-pointer rounded-[8px] border border-white/40 bg-transparent px-[10px] py-[7px] text-[12px] font-semibold text-white hover:bg-white/10"
                    >
                        {bn.cart.clearCart}
                    </button>
                    {href !== null && (
                        <MenuAnchor
                            href={href}
                            className="rounded-[8px] bg-gold-accent px-3 py-[8px] text-[13px] font-bold whitespace-nowrap text-ink no-underline hover:bg-gold-hover hover:text-ink"
                        >
                            {bn.cart.barView}
                        </MenuAnchor>
                    )}
                </>
            )}
        </div>
    );
}
