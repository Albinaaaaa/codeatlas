import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, loadEnv } from 'vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    const phpBinary =
        process.env.WAYFINDER_PHP_BINARY ||
        env.WAYFINDER_PHP_BINARY ||
        'php';

    return {
        server: {
            host: '0.0.0.0',
            port: 5173,
            strictPort: true,
            hmr: {
                host: env.VITE_HMR_HOST || 'localhost',
            },
        },
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.tsx'],
                refresh: true,
                fonts: [
                    bunny('Instrument Sans', {
                        weights: [400, 500, 600],
                    }),
                ],
            }),

            inertia(),

            react({
                babel: {
                    plugins: ['babel-plugin-react-compiler'],
                },
            }),

            tailwindcss(),

            wayfinder({
                formVariants: true,
                command: `"${phpBinary}" artisan wayfinder:generate`,
            }),
        ],
    };
});
