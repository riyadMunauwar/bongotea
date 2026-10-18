import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { totalText } from '@/components/cart/figures';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { useCart } from '@/lib/use-cart';
import { useCurrentCarts } from '@/lib/use-current-carts';
import { useRetailCart } from '@/lib/use-retail-cart';
import { useTaka } from '@/lib/use-taka';
import { cn } from '@/lib/utils';
import type { CartKind } from '@/types';
import { BagIcon, CartIcon } from './icons';
import { MenuAnchor } from './menu-anchor';

/**
 * The header's icon for one kind of Cart (ADR-0038): the Current Cart of that
 * kind, its size on the icon — Kilograms, or Packs — and, from 1024px, its
 * name and what it all comes to. Never a sum of several Carts, since each is
 * checked out alone. Where the store has more than one open Cart of the kind,
 * a list beside it switches the Current Cart and goes to its page.
 */
export function CurrentCartLink({ kind, compact = false }: { kind: CartKind; compact?: boolean }) {
    const currentCarts = useCurrentCarts();
    const cart = currentCarts[kind];
    const bulk = useCart(kind === 'bulk' ? (cart?.id ?? null) : null);
    const retail = useRetailCart(kind === 'retail' ? (cart?.id ?? null) : null);
    const taka = useTaka();
    const [open, setOpen] = useState(false);
    const menu = useRef<HTMLDivElement>(null);
    const choices = currentCarts.ofKind(kind);

    /** A click anywhere else closes the list. */
    useEffect(() => {
        if (!open) {
            return;
        }

        const closeOutside = (event: MouseEvent) => {
            if (!menu.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        document.addEventListener('click', closeOutside);

        return () => document.removeEventListener('click', closeOutside);
    }, [open]);

    if (cart === null) {
        return null;
    }

    const size = kind === 'bulk' ? bulk.priced.totalKilograms : retail.priced.packCount;
    const total = kind === 'bulk' ? totalText(bulk.priced, taka) : totalText(retail.priced, taka);
    const Icon = kind === 'bulk' ? CartIcon : BagIcon;

    return (
        <div ref={menu} className="relative flex flex-none items-center">
            <MenuAnchor
                href={cart.href}
                aria-label={compact ? cart.name : undefined}
                className={cn('flex items-center text-ink no-underline hover:text-ink', compact ? 'size-10 justify-center' : 'gap-[10px]')}
            >
                <span className="relative flex">
                    <Icon />
                    <CartBadge count={size} />
                </span>
                {!compact && (
                    <span className="flex max-w-[150px] flex-col leading-[1.25]">
                        <span className="truncate text-[11px] text-muted">{cart.name}</span>
                        <span className="text-[14px] font-bold">{total}</span>
                    </span>
                )}
            </MenuAnchor>
            {choices.length > 1 && (
                <button
                    type="button"
                    aria-label={bn.header.otherCarts}
                    aria-expanded={open}
                    onClick={() => setOpen((isOpen) => !isOpen)}
                    className="flex h-8 w-5 flex-none cursor-pointer items-center justify-center border-0 bg-transparent p-0 text-[11px] text-muted"
                >
                    ▾
                </button>
            )}
            {open && (
                <div role="menu" className="absolute top-full right-0 z-50 mt-2 flex w-[220px] flex-col rounded-[12px] border border-border bg-white p-1 shadow-card">
                    {choices.map((choice) => (
                        <button
                            key={choice.id}
                            type="button"
                            role="menuitemradio"
                            aria-checked={choice.id === cart.id}
                            onClick={() => {
                                currentCarts.mind(kind, choice.id);
                                setOpen(false);
                                router.visit(choice.href);
                            }}
                            className={cn(
                                'cursor-pointer rounded-[8px] border-0 px-3 py-2 text-left text-[14px] font-semibold',
                                choice.id === cart.id ? 'bg-green-50 text-primary-dark' : 'bg-transparent text-ink hover:bg-surface',
                            )}
                        >
                            {choice.name}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

/** A Cart's size on its icon, once it holds any: a bulk Cart's Kilograms, a retail Cart's Packs. */
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
