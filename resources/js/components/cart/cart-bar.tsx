import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { MenuAnchor } from '@/components/storefront/menu-anchor';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { useCart } from '@/lib/use-cart';
import { useRetailCart } from '@/lib/use-retail-cart';
import { useTaka } from '@/lib/use-taka';

/**
 * The slim green bar that sits at the bottom of the screen while either Cart
 * holds anything: a row per Cart that does, named — the পাইকারি কার্ট with
 * its Kilograms and total, leading to the Bulk Order page, and the খুচরা
 * কার্ট with its Packs and total, leading to it. The two are never added
 * together (ADR-0034). Each row empties its own Cart, and only that one,
 * once the buyer says yes.
 */
export function CartBar() {
    const { headerCarts } = usePage().props;
    const cart = useCart(headerCarts.bulk?.id ?? null);
    const retail = useRetailCart(headerCarts.retail?.id ?? null);
    const taka = useTaka();

    if (cart.isEmpty && retail.isEmpty) {
        return null;
    }

    return (
        <div className="sticky bottom-3 z-10 mx-auto my-3 box-border flex w-[calc(100%-24px)] max-w-[480px] flex-col gap-[6px]">
            {!cart.isEmpty && (
                <CartBarRow
                    name={bn.header.cart}
                    size={bn.packs.kilograms(toBanglaDigits(cart.priced.totalKilograms))}
                    total={taka(cart.priced.total)}
                    href={headerCarts.bulk?.href ?? '/'}
                    onClear={cart.clear}
                />
            )}
            {!retail.isEmpty && (
                <CartBarRow
                    name={bn.header.packetBasket}
                    size={bn.cart.barPacks(toBanglaDigits(retail.priced.packCount))}
                    total={taka(retail.priced.total)}
                    href={headerCarts.retail?.href ?? '/'}
                    onClear={retail.clear}
                />
            )}
        </div>
    );
}

function CartBarRow({ name, size, total, href, onClear }: { name: string; size: string; total: string; href: string; onClear: () => void }) {
    const [isConfirming, setIsConfirming] = useState(false);
    const confirmClear = bn.cart.confirmClearOne(name);

    return (
        <div className="box-border flex min-h-[48px] items-center gap-2 rounded-[12px] bg-primary py-[6px] pr-[6px] pl-3 text-white shadow-cart-bar">
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
                            {size} · <b className="font-bold text-white">{total}</b>
                        </span>
                    </span>
                    <button
                        type="button"
                        onClick={() => setIsConfirming(true)}
                        className="cursor-pointer rounded-[8px] border border-white/40 bg-transparent px-[10px] py-[7px] text-[12px] font-semibold text-white hover:bg-white/10"
                    >
                        {bn.cart.clearCart}
                    </button>
                    <MenuAnchor
                        href={href}
                        className="rounded-[8px] bg-gold-accent px-3 py-[8px] text-[13px] font-bold whitespace-nowrap text-ink no-underline hover:bg-gold-hover hover:text-ink"
                    >
                        {bn.cart.barView}
                    </MenuAnchor>
                </>
            )}
        </div>
    );
}
