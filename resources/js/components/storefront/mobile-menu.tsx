import { useState } from 'react';
import type { HeaderItem, Store } from '@/types';
import { bn } from '@/lang/bn';
import { formatPhone } from '@/lib/bangla';
import { ChevronDownIcon, ParcelIcon } from './icons';
import { MenuAnchor } from './menu-anchor';
import { cn } from '@/lib/utils';

type MobileMenuProps = {
    items: HeaderItem[];
    store: Store;
    myOrdersHref: string;
};

/**
 * The drawer that opens under the compact header: every Menu entry at full
 * width, a Dropdown unfolding its children beneath it, then My Orders and
 * the number to order by phone.
 */
export function MobileMenu({ items, store, myOrdersHref }: MobileMenuProps) {
    const [open, setOpen] = useState<number | null>(null);

    return (
        <div className="flex flex-col border-t border-border bg-white px-4 pt-1 pb-5 lg:hidden">
            {items.map((item, index) => {
                const isOpen = open === index;

                return (
                    <div key={index} className="flex flex-col border-b border-divider">
                        {item.children.length === 0 ? (
                            <MenuAnchor href={item.href ?? '/'} external={item.external} className="py-[15px] text-[18px] font-medium text-ink no-underline hover:text-ink">
                                {item.label}
                            </MenuAnchor>
                        ) : (
                            <>
                                <button
                                    type="button"
                                    aria-expanded={isOpen}
                                    onClick={() => setOpen(isOpen ? null : index)}
                                    className="flex cursor-pointer items-center justify-between border-0 bg-transparent px-0 py-[15px] text-left text-[18px] font-medium text-ink"
                                >
                                    {item.label}
                                    <ChevronDownIcon size={18} strokeWidth={2} className={cn('transition-transform duration-200', isOpen && 'rotate-180')} />
                                </button>
                                {isOpen && (
                                    <div className="mx-0 mt-0 mb-3 ml-[2px] flex flex-col border-l-2 border-drawer-rule pb-[10px] pl-[14px]">
                                        {item.children.map((child, childIndex) => (
                                            <MenuAnchor
                                                key={childIndex}
                                                href={child.href}
                                                external={child.external}
                                                className="min-h-[22px] py-[11px] text-[16px] text-drawer-text no-underline hover:text-drawer-text"
                                            >
                                                {child.label}
                                            </MenuAnchor>
                                        ))}
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                );
            })}
            <MenuAnchor href={myOrdersHref} className="flex items-center gap-[10px] border-b border-divider py-[15px] text-[18px] font-medium text-primary no-underline">
                <ParcelIcon size={22} withFlap />
                {bn.drawer.myOrders}
            </MenuAnchor>
            {store.hotline && (
                <a href={store.hotline.href} className="mt-4 text-[15px] text-muted no-underline hover:text-muted">
                    {bn.drawer.phoneOrder} {formatPhone(store.hotline.number)}
                </a>
            )}
        </div>
    );
}
