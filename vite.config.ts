import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  plugins: [vue()],
  base: '/build/', // chunk preloads must resolve to /build/assets, not the static /assets folder
  publicDir: false, // outDir is inside public/: the default copy would duplicate index.php, setup.php, upgrade.php and the static assets into the web-served build folder
  build: {
    outDir: 'public/build',
    emptyOutDir: true,
    target: 'baseline-widely-available',
    manifest: true,
    cssCodeSplit: true,
    minify: 'esbuild',
    sourcemap: false,
    modulePreload: { polyfill: false },
    chunkSizeWarningLimit: 350,
    rollupOptions: {
      input: {
        admin: 'assets/admin/main.ts',
        storefront: 'assets/storefront/product-page.js',
        storefrontRuntime: 'assets/storefront/storefront-runtime.js',
        checkout: 'assets/storefront/checkout.js',
        cart: 'assets/storefront/cart.js',
        consent: 'assets/storefront/consent.js',
        slider: 'assets/storefront/slider.js',
        supportChat: 'assets/storefront/support-chat.js',
        catalogPage: 'assets/storefront/catalog-page.js',
        homeBlocks: 'assets/storefront/home-blocks.js',
        adminRuntime: 'assets/storefront/admin-runtime.js',
        adminBuilder: 'assets/admin/features/builder.js',
        adminMedia: 'assets/admin/features/media-library.js',
        adminAppearanceMedia: 'assets/admin/features/appearance-media.js',
        storefrontCss: 'assets/storefront/storefront.css',
        consentCss: 'assets/storefront/consent.css',
        adminRuntimeCss: 'assets/admin/admin-runtime.css',
        adminFeatureCss: 'assets/admin/admin-builder-media.css',
      },
      output: {
        manualChunks(id) {
          if (id.includes('node_modules/@tiptap/')) return 'editor-tiptap';
          if (id.includes('node_modules/@lucide/')) return 'icons';
          if (id.includes('node_modules/vue/')) return 'vue-core';
          return undefined;
        },
      },
    },
  },
});