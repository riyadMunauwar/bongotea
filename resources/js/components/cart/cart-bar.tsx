import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { BagIcon, CartIcon } from '@/components/storefront/icons';
import { MenuAnchor } from '@/components/storefront/menu-anchor';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { useCart } from '@/lib/use-cart';
import { useRetailCart } from '@/lib/use-retail-cart';
import { useTaka } from '@/lib/use-taka';

/**
 * The tabs held to the right edge of the screen, halfway down, while either
 * Cart holds anything: a tab per Cart that does — the bulk Cart's Kilograms
 * and its total, leading to the Bulk Order page, and the খুচরা কার্ট's Packs
 * and its total, leading to it. The two are never added together (ADR-0034).
 */
export function CartBar() {
    const { links } = usePage().props;
    const { priced, isEmpty } = useCart();
    const retail = useRetailCart();
    const taka = useTaka();

    if (isEmpty && retail.isEmpty) {
        return null;
    }

    return (
        <div className="fixed top-1/2 right-0 z-30 flex -translate-y-1/2 flex-col gap-2">
            {!isEmpty && (
                <CartTab
                    href={links.bulkOrder}
                    icon={<CartIcon size={22} />}
                    count={toBanglaDigits(priced.totalKilograms)}
                    name={bn.header.cart}
                    line={bn.cart.barLine(toBanglaDigits(priced.totalKilograms))}
                    total={taka(priced.total)}
                />
            )}
            {!retail.isEmpty && (
                <CartTab
                    href={links.packetBasket}
                    icon={<BagIcon size={22} />}
                    count={toBanglaDigits(retail.priced.packCount)}
                    name={bn.header.packetBasket}
                    line={bn.packs.barLine(toBanglaDigits(retail.priced.packCount))}
                    total={taka(retail.priced.total)}
                />
            )}
        </div>
    );
}

function CartTab({ href, icon, count, name, line, total }: { href: string; icon: ReactNode; count: string; name: string; line: string; total: string }) {
    return (
        <MenuAnchor
            href={href}
            aria-label={`${line}, ${total}`}
            className="box-border flex w-[76px] flex-col items-center gap-[2px] overflow-hidden rounded-l-[12px] border border-r-0 border-border bg-white text-center text-ink no-underline shadow-cart-bar hover:text-ink md:w-[88px]"
        >
            <span className="flex w-full flex-col items-center gap-[2px] bg-primary px-1 pt-2 pb-[6px] text-white">
                <span className="relative flex">
                    {icon}
                    <span className="absolute -top-[6px] -right-[10px] box-border flex h-[18px] min-w-[18px] items-center justify-center rounded-full border-2 border-primary bg-cart-badge px-1 text-[10px] font-bold">
                        {count}
                    </span>
                </span>
                <span className="text-[10px] leading-tight font-semibold md:text-[11px]">{name}</span>
            </span>
            <b className="px-1 py-[6px] text-[12px] font-bold md:text-[13px]">{total}</b>
        </MenuAnchor>
    );
}
