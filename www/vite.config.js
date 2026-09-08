import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
        /**
         * Dentro do contêiner o Vite escuta em 0.0.0.0, mas quem baixa os assets
         * é o navegador do host. Sem `origin`, o `public/hot` sairia apontando
         * para http://0.0.0.0:5173 — endereço que o navegador não resolve, e a
         * página vem sem CSS nem JS. Fora do Docker o endereço é o mesmo.
         */
        origin: 'http://localhost:5173',
        hmr: { host: 'localhost' },
        watch: {
            ignored: ['**/storage/framework/views/**'],
            // Bind mount do Windows não propaga eventos de inotify para o
            // contêiner; sem polling o hot reload nunca dispara.
            usePolling: Boolean(process.env.VITE_USE_POLLING),
        },
    },
});
