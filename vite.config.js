import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { viteStaticCopy } from 'vite-plugin-static-copy';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    server: {
        host: 'localhost',
        port: 5173,
        hmr: {
        host: process.env.VITE_DEV_SERVER_HOST || 'localhost',
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
        viteStaticCopy({
            targets: [
                {
                    src: 'resources/assets/svg/*',
                    dest: 'svg'
                }
            ]
        })
    ],
});
