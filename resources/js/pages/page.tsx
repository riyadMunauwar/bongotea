import { usePage } from '@inertiajs/react';
import { Blocks } from '@/components/blocks';
import { CartBar } from '@/components/cart/cart-bar';
import type { PageProps } from '@/types/blocks';

/**
 * Any Page — Home and the other System Pages among them — drawn as its
 * Blocks, inside the storefront shell. Home also carries the Cart's summary
 * bar while the Cart holds anything.
 */
export default function Page() {
    const { page } = usePage<PageProps>().props;

    return (
        <div className="flex flex-col">
            <Blocks blocks={page.blocks} />
            {page.isHome && <CartBar />}
        </div>
    );
}
