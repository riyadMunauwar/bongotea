import { Link } from '@inertiajs/react';
import type { Store } from '@/types';
import { cn } from '@/lib/utils';

type StoreLogoProps = {
    store: Store;
    compact?: boolean;
};

/**
 * The Merchant's logo when one is uploaded; otherwise the mockup's leaf mark
 * beside the store's name, with the tagline under it on the full header.
 */
export function StoreLogo({ store, compact = false }: StoreLogoProps) {
    return (
        <Link
            href="/"
            className={cn('flex items-center text-ink no-underline hover:text-ink', compact ? 'min-w-0 flex-[0_1_auto] gap-[7px] overflow-hidden' : 'flex-none gap-[9px]')}
        >
            {store.logoUrl ? (
                <img src={store.logoUrl} alt={store.name} className={cn('block w-auto', compact ? 'h-[30px]' : 'h-[42px]')} />
            ) : (
                <>
                    <span className={cn('block rounded-[50%_0_50%_0] bg-primary', compact ? 'size-[22px]' : 'size-[26px]')} aria-hidden="true" />
                    <span className="flex flex-col leading-[1.1]">
                        <span className={cn('font-bold', compact ? 'truncate text-[18px]' : 'text-[22px] tracking-[.01em]')}>{store.name}</span>
                        {!compact && store.tagline && <span className="text-[11px] font-medium text-muted">{store.tagline}</span>}
                    </span>
                </>
            )}
        </Link>
    );
}
