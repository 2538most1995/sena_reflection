import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), 'VITE_');
    const appBasePath = (process.env.VITE_APP_BASE_PATH ?? env.VITE_APP_BASE_PATH ?? '').trim().replace(/^\/+|\/+$/g, '');

    return {
    ...(appBasePath ? { base: `/${appBasePath}/build/` } : {}),
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx', 'resources/js/document-snapshot.tsx'],
            refresh: true,
            fonts: [
                bunny('Noto Sans Thai', {
                    weights: [400, 500, 600, 700],
                    subsets: ['thai', 'latin'],
                }),
                bunny('Anuphan', { weights: [400, 500, 600, 700], subsets: ['thai', 'latin'] }),
                bunny('Sarabun', { weights: [400, 700], styles: ['normal', 'italic'], subsets: ['thai', 'latin'] }),
            ],
        }),
        tailwindcss(),
        react(),
    ],
    resolve: {
        alias: {
            '@': path.resolve(import.meta.dirname, './resources/js'),
        },
    },
    server: {
        fs: {
            allow: [path.resolve(import.meta.dirname), path.resolve(import.meta.dirname, '../fonts')],
        },
        watch: {
            ignored: ['**/storage/framework/views/**', '**/output/**', '**/tests/**'],
        },
    },
    };
});
