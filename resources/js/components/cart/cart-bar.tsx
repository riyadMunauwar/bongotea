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
 * and the way to it. The two are never added together (ADR-0034). Above them,
 * "সব মুছুন" empties both, once the buyer says yes.
 */
export function CartBar() {
    const { links } = usePage().props;
    const cart = useCart();
    const retail = useRetailCart();
    const taka = useTaka();
    const [isConfirming, setIsConfirming] = useState(false);

    if (cart.isEmpty && retail.isEmpty) {
        return null;
    }

    const clearBoth = () => {
        cart.clear();
        retail.clear();
        setIsConfirming(false);
    };

    return (
        <div className="sticky bottom-4 z-10 mx-auto my-4 box-border flex w-[calc(100%-32px)] max-w-[560px] flex-col gap-2">
            <div className="flex justify-end">
                {isConfirming ? (
                    <span
                        role="alertdialog"
                        aria-label={bn.cart.confirmClearBoth}
                        className="flex flex-wrap items-center justify-end gap-2 rounded-[10px] bg-white px-3 py-[6px] text-[13px] shadow-cart-bar"
                    >
                        <span className="text-ink">{bn.cart.confirmClearBoth}</span>
                        <button type="button" onClick={clearBoth} className="cursor-pointer rounded-[8px] border-0 bg-error px-3 py-[5px] font-semibold text-white">
                            {bn.calculator.confirmYes}
                        </button>
                        <button
                            type="button"
                            onClick={() => setIsConfirming(false)}
                            className="cursor-pointer rounded-[8px] border border-input bg-white px-3 py-[5px] font-semibold text-ink"
                        >
                            {bn.calculator.confirmNo}
                        </button>
                    </span>
                ) : (
                    <button
                        type="button"
                        onClick={() => setIsConfirming(true)}
                        className="cursor-pointer rounded-full border border-border bg-white px-3 py-[5px] text-[12px] font-semibold text-error shadow-cart-bar hover:bg-error-soft"
                    >
                        ✕ {bn.calculator.clearAll}
                    </button>
                )}
            </div>
            {!cart.isEmpty && (
                <CartBarRow line={bn.cart.barLine(toBanglaDigits(cart.priced.totalKilograms))} total={taka(cart.priced.total)} href={links.bulkOrder} action={bn.cart.viewOrder} />
            )}
            {!retail.isEmpty && (
                <CartBarRow line={bn.packs.barLine(toBanglaDigits(retail.priced.packCount))} total={taka(retail.priced.total)} href={links.packetBasket} action={bn.packs.view} />
            )}
        </div>
    );
}

function CartBarRow({ line, total, href, action }: { line: string; total: string; href: string; action: string }) {
    return (
        <div className="box-border flex items-center gap-3 rounded-[14px] bg-primary py-[10px] pr-[10px] pl-[18px] text-white shadow-cart-bar">
            <div className="flex min-w-0 flex-1 flex-col">
                <span className="text-[12px] text-white/80">{line}</span>
                <b className="text-[18px] font-bold">{total}</b>
            </div>
            <MenuAnchor
                href={href}
                className="rounded-[10px] bg-gold-accent px-[18px] py-3 text-[15px] font-bold text-ink no-underline hover:bg-gold-hover hover:text-ink"
            >
                {action}
            </MenuAnchor>
        </div>
    );
}
