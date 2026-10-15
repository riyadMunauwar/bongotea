import { usePage } from '@inertiajs/react';
import { MenuAnchor } from '@/components/storefront/menu-anchor';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { useCart } from '@/lib/use-cart';
import { useRetailCart } from '@/lib/use-retail-cart';
import { useTaka } from '@/lib/use-taka';

/**
 * The dark bar that sits at the bottom of the screen while either Cart holds
 * anything: a row per Cart that does — the bulk Cart's Kilograms, its total
 * and the way to the Bulk Order page, and the packet basket's Packs, its
 * total and the way to it. The two are never added together (ADR-0034).
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
        <div className="sticky bottom-4 z-10 mx-auto my-4 box-border flex w-[calc(100%-32px)] max-w-[560px] flex-col gap-2">
            {!isEmpty && (
                <CartBarRow line={bn.cart.barLine(toBanglaDigits(priced.totalKilograms))} total={taka(priced.total)} href={links.bulkOrder} action={bn.cart.viewOrder} />
            )}
            {!retail.isEmpty && (
                <CartBarRow line={bn.packs.barLine(toBanglaDigits(retail.priced.packCount))} total={taka(retail.priced.total)} href={links.packetBasket} action={bn.packs.view} />
            )}
        </div>
    );
}

function CartBarRow({ line, total, href, action }: { line: string; total: string; href: string; action: string }) {
    return (
        <div className="box-border flex items-center gap-3 rounded-[14px] bg-ink py-[10px] pr-[10px] pl-[18px] text-white shadow-cart-bar">
            <div className="flex min-w-0 flex-1 flex-col">
                <span className="text-[12px] text-night-muted">{line}</span>
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
