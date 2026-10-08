import { defineConfig } from 'vitest/config';
import { fileURLToPath } from 'node:url';

/**
 * Kept apart from `vite.config.js` so the Laravel plugin, which expects a
 * Laravel app around it, never runs under the test runner.
 */
export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        include: ['resources/js/**/*.test.ts'],
    },
});
