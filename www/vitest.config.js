import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    /**
     * O jsdom tem um `Uint8Array` próprio, e o build Node do web3.js entrega
     * `Buffer` do Node para o hash — realms diferentes, derivação de PDA quebrada
     * só no teste. Apontar para o mesmo build que o navegador recebe evita isso e
     * mantém o teste rodando o código que vai para produção.
     */
    resolve: {
        alias: {
            '@solana/web3.js': fileURLToPath(new URL('./node_modules/@solana/web3.js/lib/index.browser.esm.js', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.js'],
        restoreMocks: true,
    },
});
