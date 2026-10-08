import type { ReactNode } from 'react';
import { SeoHead } from '@/components/storefront/seo-head';
import { SiteFooter } from '@/components/storefront/site-footer';
import { SiteHeader } from '@/components/storefront/site-header';

/**
 * The shell every storefront page sits in: head tags, the sticky header and
 * the footer around the page.
 */
export default function StorefrontLayout({ children }: { children: ReactNode }) {
    return (
        <div className="min-h-screen bg-white">
            <SeoHead />
            <SiteHeader />
            <main>{children}</main>
            <SiteFooter />
        </div>
    );
}
