import type { ResolvedComponent } from '@inertiajs/react';
import type { ReactNode } from 'react';
import StorefrontLayout from '@/layouts/storefront-layout';

/**
 * Finds a page component by the name the server rendered, for the browser
 * and the SSR bundle alike, and wraps every page that names no layout of its
 * own in the storefront shell. The layout is persistent, so the header and
 * footer are not re-mounted between visits.
 */
export async function resolvePage(name: string): Promise<ResolvedComponent> {
    const pages = import.meta.glob<{ default: ResolvedComponent }>('./pages/**/*.tsx');
    const loader = pages[`./pages/${name}.tsx`];

    if (!loader) {
        throw new Error(`Page not found: ${name}`);
    }

    const page = (await loader()).default;

    page.layout ??= (child: ReactNode) => <StorefrontLayout>{child}</StorefrontLayout>;

    return page;
}
