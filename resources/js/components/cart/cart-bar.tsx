import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { MenuAnchor } from '@/components/storefront/menu-anchor';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { useCart } from '@/lib/use-cart';
import { useRetailCart } from '@/lib/use-retail-cart';
import { useTaka } from '@/lib/use-taka';

/**
 * The green bar that sits at the bottom of the screen while either Cart holds
 * anything: a row per Cart that does — the bulk Cart's Kilograms, its total
 * and the way to the Bulk Order page, and the খুচরা কার্ট's Packs, its total
 * and the way to it. The two are never added together (ADR-0034). Each row
 * empties its own Cart, and only that one, once the buyer says yes.
 */
export function CartBar() {
    const { links } = usePage().props;
    const cart = useCart();
    const retail = useRetailCart();
    const taka = useTaka();

    if (cart.isEmpty && retail.isEmpty) {
        return null;
    }

    return (
        <div className="sticky bottom-4 z-10 mx-auto my-4 box-border flex w-[calc(100%-32px)] max-w-[560px] flex-col gap-2">
            {!cart.isEmpty && (
                <CartBarRow
                    line={bn.cart.barLine(toBanglaDigits(cart.priced.totalKilograms))}
                    total={taka(cart.priced.total)}
                    href={links.bulkOrder}
                    action={bn.cart.viewOrder}
                    confirmClear={bn.cart.confirmClearOne(bn.header.cart)}
                    onClear={cart.clear}
                />
            )}
            {!retail.isEmpty && (
                <CartBarRow
                    line={bn.packs.barLine(toBanglaDigits(retail.priced.packCount))}
                    total={taka(retail.priced.total)}
                    href={links.packetBasket}
                    action={bn.packs.view}
                    confirmClear={bn.cart.confirmClearOne(bn.header.packetBasket)}
                    onClear={retail.clear}
                />
            )}
        </div>
    );
}

function CartBarRow({
    line,
    total,
    href,
    action,
    confirmClear,
    onClear,
}: {
    line: string;
    total: string;
    href: string;
    action: string;
    confirmClear: string;
    onClear: () => void;
}) {
    const [isConfirming, setIsConfirming] = useState(false);

    if (isConfirming) {
        return (
            <div
                role="alertdialog"
                aria-label={confirmClear}
                className="box-border flex flex-wrap items-center gap-2 rounded-[14px] bg-primary py-[10px] pr-[10px] pl-[18px] text-white shadow-cart-bar"
            >
                <span className="min-w-0 flex-1 text-[14px] font-semibold">{confirmClear}</span>
                <button
                    type="button"
                    onClick={() => {
                        onClear();
                        setIsConfirming(false);
                    }}
                    className="cursor-pointer rounded-[10px] border-0 bg-error px-4 py-[10px] text-[14px] font-bold text-white"
                >
                    {bn.calculator.confirmYes}
                </button>
                <button
                    type="button"
                    onClick={() => setIsConfirming(false)}
                    className="cursor-pointer rounded-[10px] border-0 bg-white px-4 py-[10px] text-[14px] font-bold text-ink"
                >
                    {bn.calculator.confirmNo}
                </button>
            </div>
        );
    }

    return (
        <div className="box-border flex items-center gap-3 rounded-[14px] bg-primary py-[10px] pr-[10px] pl-[18px] text-white shadow-cart-bar">
            <div className="flex min-w-0 flex-1 flex-col">
                <span className="text-[12px] text-white/80">{line}</span>
                <b className="text-[18px] font-bold">{total}</b>
            </div>
            <button
                type="button"
                onClick={() => setIsConfirming(true)}
                aria-label={bn.calculator.clearAll}
                title={bn.calculator.clearAll}
                className="flex cursor-pointer items-center gap-1 rounded-[10px] border border-white/40 bg-transparent px-3 py-[11px] text-[13px] font-semibold text-white hover:bg-white/10"
            >
                ✕<span className="max-sm:hidden">{bn.calculator.clearAll}</span>
            </button>
            <MenuAnchor
                href={href}
                className="rounded-[10px] bg-gold-accent px-[18px] py-3 text-[15px] font-bold text-ink no-underline hover:bg-gold-hover hover:text-ink"
            >
                {action}
            </MenuAnchor>
        </div>
    );
}
