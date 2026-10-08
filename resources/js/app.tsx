import { createInertiaApp } from '@inertiajs/react';
import { createRoot, hydrateRoot } from 'react-dom/client';
import { resolvePage } from '@/resolve-page';

/**
 * Hydrates what the SSR process drew; when it drew nothing — the process is
 * down or SSR is off — renders the page from scratch, so the storefront works
 * either way.
 */
createInertiaApp({
    resolve: resolvePage,
    setup({ el, App, props }) {
        if (el.dataset.serverRendered === 'true') {
            hydrateRoot(el, <App {...props} />);

            return;
        }

        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: 'var(--primary)',
    },
});
