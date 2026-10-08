import { Head, usePage } from '@inertiajs/react';

/**
 * The page's title, description, canonical address and share tags, under
 * the same keys the root template draws them with, so a client-side visit
 * replaces the server's tags instead of stacking beside them.
 */
export function SeoHead() {
    const { seo } = usePage().props;

    return (
        <Head>
            <title>{seo.title}</title>
            {seo.description && <meta head-key="description" name="description" content={seo.description} />}
            <link head-key="canonical" rel="canonical" href={seo.canonical} />
            <meta head-key="og:url" property="og:url" content={seo.canonical} />
            <meta head-key="og:type" property="og:type" content={seo.type} />
            <meta head-key="og:site_name" property="og:site_name" content={seo.siteName} />
            <meta head-key="og:locale" property="og:locale" content="bn_BD" />
            <meta head-key="og:title" property="og:title" content={seo.title} />
            {seo.description && <meta head-key="og:description" property="og:description" content={seo.description} />}
            {seo.image && <meta head-key="og:image" property="og:image" content={seo.image} />}
            <meta head-key="twitter:card" name="twitter:card" content={seo.image ? 'summary_large_image' : 'summary'} />
        </Head>
    );
}
