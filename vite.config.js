import { defineConfig } from 'vite'
import laravel, { refreshPaths } from 'laravel-vite-plugin'
import { viteStaticCopy } from "vite-plugin-static-copy";
import tailwindcss from '@tailwindcss/vite';
import { existsSync, readdirSync, readFileSync } from 'node:fs';

// Every theme's declared entry points, so a channel's theme is in the manifest. See docs/THEMES.md.
const themeInputs = existsSync('themes')
    ? readdirSync('themes').flatMap((name) => {
        const manifest = `themes/${name}/theme.json`;
        if (!existsSync(manifest)) return [];
        const { css = [], js = [] } = JSON.parse(readFileSync(manifest, 'utf8')).assets ?? {};
        return [...css, ...js].map((path) => `themes/${name}/${path.replace(/^\//, '')}`);
    })
    : [];


export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css', 
                'resources/js/app.js',
		        'resources/css/filament/admin/theme.css',
                ...themeInputs,
            ],
            refresh: [
                ...refreshPaths,
                'app/Filament/**',
                'app/Forms/Components/**',
                'app/Livewire/**',
                'app/Infolists/Components/**',
                'app/Providers/Filament/**',
                'app/Tables/Columns/**',
            ],
        }),
        tailwindcss(),
        viteStaticCopy({
            targets: [
                {
                    src: "resources/images/*",
                    dest: "images",
                },
            ],
        }),
    ],
})
