import { usePage } from '@inertiajs/react';
import { MenuAnchor } from '@/components/storefront/menu-anchor';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { useCart } from '@/lib/use-cart';
import { useTaka } from '@/lib/use-taka';

/**
 * The dark bar that sits at the bottom of the screen while the Cart holds
 * anything: its Kilograms, its total, and the way to the Bulk Order page.
 */
export function CartBar() {
    const { links } = usePage().props;
    const { priced, isEmpty } = useCart();
    const taka = useTaka();

    if (isEmpty) {
        return null;
    }

    return (
        <div className="sticky bottom-4 z-10 mx-auto mb-4 box-border flex w-[calc(100%-32px)] max-w-[560px] items-center gap-3 rounded-[14px] bg-ink py-[10px] pr-[10px] pl-[18px] text-white shadow-cart-bar">
            <div className="flex min-w-0 flex-1 flex-col">
                <span className="text-[12px] text-night-muted">{bn.cart.barLine(toBanglaDigits(priced.totalKilograms))}</span>
                <b className="text-[18px] font-bold">{taka(priced.total)}</b>
            </div>
            <MenuAnchor
                href={links.bulkOrder}
                className="rounded-[10px] bg-gold-accent px-[18px] py-3 text-[15px] font-bold text-ink no-underline hover:bg-gold-hover hover:text-ink"
            >
                {bn.cart.viewOrder}
            </MenuAnchor>
        </div>
    );
}
