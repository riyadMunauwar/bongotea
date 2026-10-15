import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { useCart } from '@/lib/use-cart';
import { useRetailCart } from '@/lib/use-retail-cart';
import { useTaka } from '@/lib/use-taka';
import { AnnouncementBar } from './announcement-bar';
import { DesktopNav } from './desktop-nav';
import { BagIcon, CartIcon, ParcelIcon } from './icons';
import { MenuAnchor } from './menu-anchor';
import { MobileMenu } from './mobile-menu';
import { SearchBox } from './search-box';
import { StoreLogo } from './store-logo';

/**
 * The sticky header on every storefront page: the announcement bar, then
 * either the full two-row header (from 1024px) or the compact one with its
 * drawer. Which is drawn is decided in CSS, so the server draws both and the
 * first paint never jumps. From 1024px each Cart's button names it and shows
 * what it all comes to, delivery included, with its size on the icon — the
 * পাইকারি কার্ট's Kilograms, the খুচরা কার্ট's Packs; the compact header
 * draws the icons alone. খুচরা অর্ডার করুন stands left of পাইকারি অর্ডার
 * করুন, each opening the Cart chosen in Header settings (ADR-0038); a
 * button whose Cart is closed is not drawn, nor is its icon.
 */
export function SiteHeader() {
    const { store, announcement, menus, links, headerCarts } = usePage().props;
    const [menuOpen, setMenuOpen] = useState(false);
    const { priced } = useCart(headerCarts.bulk?.id ?? null);
    const retail = useRetailCart(headerCarts.retail?.id ?? null);
    const taka = useTaka();

    useEffect(() => router.on('navigate', () => setMenuOpen(false)), []);

    return (
        <header className="sticky top-0 z-40 bg-white shadow-header">
            {announcement && <AnnouncementBar announcement={announcement} store={store} />}

            <div className="hidden lg:block">
                <div className="shell-container flex h-[76px] items-center gap-7">
                    <StoreLogo store={store} />
                    <SearchBox className="h-[46px] max-w-[560px] flex-1" />
                    <div className="ml-auto flex flex-none items-center gap-[22px]">
                        <MenuAnchor href={links.myOrders} className="flex items-center gap-[9px] text-ink no-underline hover:text-ink">
                            <ParcelIcon />
                            <span className="flex flex-col leading-[1.25]">
                                <span className="text-[11px] text-muted">{bn.header.myOrdersEyebrow}</span>
                                <span className="text-[14px] font-semibold">{bn.header.myOrders}</span>
                            </span>
                        </MenuAnchor>
                        {headerCarts.retail && (
                            <MenuAnchor href={headerCarts.retail.href} className="flex items-center gap-[10px] text-ink no-underline hover:text-ink">
                                <span className="relative flex">
                                    <BagIcon />
                                    <CartBadge count={retail.priced.packCount} />
                                </span>
                                <span className="flex flex-col leading-[1.25]">
                                    <span className="text-[11px] text-muted">{bn.header.packetBasket}</span>
                                    <span className="text-[14px] font-bold">{taka(retail.priced.total)}</span>
                                </span>
                            </MenuAnchor>
                        )}
                        {headerCarts.bulk && (
                            <MenuAnchor href={headerCarts.bulk.href} className="flex items-center gap-[10px] text-ink no-underline hover:text-ink">
                                <span className="relative flex">
                                    <CartIcon />
                                    <CartBadge count={priced.totalKilograms} />
                                </span>
                                <span className="flex flex-col leading-[1.25]">
                                    <span className="text-[11px] text-muted">{bn.header.cart}</span>
                                    <span className="text-[14px] font-bold">{taka(priced.total)}</span>
                                </span>
                            </MenuAnchor>
                        )}
                    </div>
                </div>
                <div className="border-t border-divider">
                    <div className="shell-container flex h-[58px] items-center gap-7 text-ink">
                        {menus.header.length > 0 && <DesktopNav items={menus.header} />}
                        <div className="my-2 ml-auto flex items-center gap-3">
                            {headerCarts.retail && (
                                <MenuAnchor
                                    href={headerCarts.retail.href}
                                    className="flex items-center gap-2 rounded-[8px] border border-primary bg-white px-[18px] py-[9px] text-[14px] font-bold text-primary-dark no-underline hover:bg-green-50 hover:text-primary-dark"
                                >
                                    {bn.header.retailOrder}
                                </MenuAnchor>
                            )}
                            {headerCarts.bulk && (
                                <MenuAnchor
                                    href={headerCarts.bulk.href}
                                    className="flex items-center gap-2 rounded-[8px] bg-primary px-[18px] py-[10px] text-[14px] font-bold text-white no-underline hover:bg-primary-dark hover:text-white"
                                >
                                    {bn.header.bulkOrder}
                                </MenuAnchor>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <div className="flex flex-col gap-2 px-3 pt-2 pb-[10px] lg:hidden">
                <div className="flex h-11 min-w-0 items-center gap-[2px]">
                    <button
                        type="button"
                        aria-label={bn.header.menu}
                        aria-expanded={menuOpen}
                        onClick={() => setMenuOpen((open) => !open)}
                        className="flex size-11 flex-none cursor-pointer flex-col items-center justify-center gap-[5px] border-0 bg-transparent p-0"
                    >
                        <span className="block h-[2px] w-5 rounded-[2px] bg-ink" />
                        <span className="block h-[2px] w-5 rounded-[2px] bg-ink" />
                        <span className="mr-[6px] block h-[2px] w-[14px] rounded-[2px] bg-ink" />
                    </button>
                    <StoreLogo store={store} compact />
                    <div className="ml-auto flex flex-none items-center gap-1">
                        {headerCarts.retail && (
                            <MenuAnchor
                                href={headerCarts.retail.href}
                                className="flex h-[34px] items-center rounded-full border border-primary bg-white px-[10px] text-[12px] font-bold whitespace-nowrap text-primary-dark no-underline hover:text-primary-dark active:bg-green-50"
                            >
                                {bn.header.retailOrderShort}
                            </MenuAnchor>
                        )}
                        {headerCarts.bulk && (
                            <MenuAnchor
                                href={headerCarts.bulk.href}
                                className="flex h-[34px] items-center rounded-full bg-primary px-[10px] text-[12px] font-bold whitespace-nowrap text-white no-underline shadow-pill hover:text-white active:bg-primary-dark"
                            >
                                {bn.header.bulkOrderShort}
                            </MenuAnchor>
                        )}
                    </div>
                    {headerCarts.retail && (
                        <MenuAnchor href={headerCarts.retail.href} aria-label={bn.header.packetBasket} className="flex size-10 flex-none items-center justify-center text-ink hover:text-ink">
                            <span className="relative flex">
                                <BagIcon />
                                <CartBadge count={retail.priced.packCount} />
                            </span>
                        </MenuAnchor>
                    )}
                    {headerCarts.bulk && (
                        <MenuAnchor href={headerCarts.bulk.href} aria-label={bn.header.cart} className="flex size-10 flex-none items-center justify-center text-ink hover:text-ink">
                            <span className="relative flex">
                                <CartIcon />
                                <CartBadge count={priced.totalKilograms} />
                            </span>
                        </MenuAnchor>
                    )}
                </div>
                <SearchBox compact className="h-11 flex-none" />
            </div>

            {menuOpen && <MobileMenu items={menus.header} store={store} myOrdersHref={links.myOrders} />}
        </header>
    );
}

/** A Cart's size on its icon, once it holds any: the bulk Cart's Kilograms, the packet basket's Packs. */
function CartBadge({ count }: { count: number }) {
    if (count === 0) {
        return null;
    }

    return (
        <span className="absolute -top-[6px] -right-2 box-border flex h-5 min-w-5 items-center justify-center rounded-full border-2 border-white bg-cart-badge px-[5px] text-[11px] font-bold text-white">
            {toBanglaDigits(count)}
        </span>
    );
}
