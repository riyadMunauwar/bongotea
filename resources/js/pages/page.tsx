import { usePage } from '@inertiajs/react';
import { Blocks } from '@/components/blocks';
import type { PageProps } from '@/types/blocks';

/**
 * Any Page — Home and the other System Pages among them — drawn as its
 * Blocks, inside the storefront shell.
 */
export default function Page() {
    const { page } = usePage<PageProps>().props;

    return <Blocks blocks={page.blocks} />;
}
