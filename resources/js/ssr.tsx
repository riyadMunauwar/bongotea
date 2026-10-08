import { createInertiaApp } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import { renderToString } from 'react-dom/server';
import { resolvePage } from '@/resolve-page';

/**
 * The SSR process: `php artisan inertia:start-ssr` runs the built bundle,
 * and the app posts each first visit here to be drawn as HTML.
 */
createServer((page) =>
    createInertiaApp({
        page,
        render: renderToString,
        resolve: resolvePage,
        setup: ({ App, props }) => <App {...props} />,
    }),
);
