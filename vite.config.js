import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'path';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },
    test: {
        globals: true,
        environment: 'jsdom',
        include: ['tests/**/*.test.js'],
        setupFiles: [],
    },
    build: {
        outDir: 'public_html/build',
        emptyOutDir: true,
        manifest: true,
        rollupOptions: {
            input: {
                // Запись `hall-editor` удалена вместе с самим компонентом
                // (`resources/js/components/HallEditor.vue`) — см. P1.9.8 в
                // docs/CODE-QUALITY-GUIDE.md. Сборка `assets/hall-editor.*.js`
                // не загружалась ни одной страницей: `public/hall-editor.html`
                // (и его копия в `public_html/build/`) самодостаточен, внутри
                // него инлайновый скрипт и ни одной ссылки на `assets/`.
                // Пока запись оставалась здесь, `vite build` без `--config`
                // падал с «Could not resolve entry module».
                'ticket-builder': 'resources/js/app/ticket-builder.js',
            },
            output: {
                entryFileNames: `assets/[name].[hash].js`,
                chunkFileNames: `assets/[name].[hash].js`,
                assetFileNames: `assets/[name].[hash].[ext]`,
            },
        },
    },
});
