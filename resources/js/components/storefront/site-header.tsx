import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { bn } from '@/lang/bn';
import { formatTaka } from '@/lib/bangla';
import { cn } from '@/lib/utils';
import { AnnouncementBar } from './announcement-bar';
import { DesktopNav } from './desktop-nav';
import { CartIcon, ParcelIcon, SearchIcon } from './icons';
import { MenuAnchor } from './menu-anchor';
import { MobileMenu } from './mobile-menu';
import { StoreLogo } from './store-logo';

/**
 * The sticky header on every storefront page: the announcement bar, then
 * either the full two-row header (from 1024px) or the compact one with its
 * drawer. Which is drawn is decided in CSS, so the server draws both and the
 * first paint never jumps.
 */
export function SiteHeader() {
    const { store, announcement, menus, links } = usePage().props;
    const [menuOpen, setMenuOpen] = useState(false);

    useEffect(() => router.on('navigate', () => setMenuOpen(false)), []);

    return (
        <header className="sticky top-0 z-40 bg-white shadow-header">
            {announcement && <AnnouncementBar announcement={announcement} store={store} />}

            <div className="hidden lg:block">
                <div className="shell-container flex h-[76px] items-center gap-7">
                    <StoreLogo store={store} />
                    <SearchForm action={links.search} className="h-[46px] max-w-[560px] flex-1" />
                    <div className="ml-auto flex flex-none items-center gap-[22px]">
                        <MenuAnchor href={links.myOrders} className="flex items-center gap-[9px] text-ink no-underline hover:text-ink">
                            <ParcelIcon />
                            <span className="flex flex-col leading-[1.25]">
                                <span className="text-[11px] text-muted">{bn.header.myOrdersEyebrow}</span>
                                <span className="text-[14px] font-semibold">{bn.header.myOrders}</span>
                            </span>
                        </MenuAnchor>
                        <MenuAnchor href={links.bulkOrder} className="flex items-center gap-[10px] text-ink no-underline hover:text-ink">
                            <span className="relative flex">
                                <CartIcon />
                            </span>
                            <span className="flex flex-col leading-[1.25]">
                                <span className="text-[11px] text-muted">{bn.header.cart}</span>
                                <span className="text-[14px] font-bold">{formatTaka(0)}</span>
                            </span>
                        </MenuAnchor>
                    </div>
                </div>
                <div className="border-t border-divider">
                    <div className="shell-container flex h-[58px] items-center gap-7 text-ink">
                        <DesktopNav items={menus.header} />
                        <MenuAnchor
                            href={links.bulkOrder}
                            className="my-2 ml-auto flex items-center gap-2 rounded-[8px] bg-primary px-[18px] py-[10px] text-[14px] font-bold text-white no-underline hover:bg-primary-dark hover:text-white"
                        >
                            {bn.header.bulkOrder}
                        </MenuAnchor>
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
                    <MenuAnchor
                        href={links.bulkOrder}
                        className="ml-auto flex h-[38px] flex-none items-center gap-1 rounded-full bg-primary px-3 text-[13px] font-bold whitespace-nowrap text-white no-underline shadow-pill hover:text-white active:bg-primary-dark"
                    >
                        {bn.header.bulkOrderShort}
                        <span className="text-[15px]">→</span>
                    </MenuAnchor>
                    <MenuAnchor href={links.bulkOrder} aria-label={bn.header.cart} className="flex size-11 items-center justify-center text-ink hover:text-ink">
                        <span className="relative flex">
                            <CartIcon />
                        </span>
                    </MenuAnchor>
                </div>
                <SearchForm action={links.search} compact className="h-11 flex-none" />
            </div>

            {menuOpen && <MobileMenu items={menus.header} store={store} myOrdersHref={links.myOrders} />}
        </header>
    );
}

function SearchForm({ action, compact = false, className }: { action: string; compact?: boolean; className?: string }) {
    return (
        <form action={action} method="get" role="search" className={cn('m-0 flex min-w-0 items-center overflow-hidden rounded-[10px] border-[1.5px] border-primary bg-white', className)}>
            <input
                type="search"
                name="q"
                placeholder={bn.header.searchPlaceholder}
                aria-label={bn.header.search}
                className={cn('h-full min-w-0 flex-1 border-0 bg-transparent px-[14px] text-ink outline-0', compact ? 'text-[14px]' : 'text-[15px]')}
            />
            <button
                type="submit"
                aria-label={bn.header.search}
                className={cn('flex h-full cursor-pointer items-center gap-[6px] border-0 bg-primary text-[14px] font-semibold text-white', compact ? 'px-[14px]' : 'px-[18px]')}
            >
                <SearchIcon />
                {!compact && bn.header.search}
            </button>
        </form>
    );
}
