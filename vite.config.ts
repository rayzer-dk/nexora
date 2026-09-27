import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  // Absolute URLs such as /assets/branding/*.svg are runtime paths served from public/, not modules.
  plugins: [vue({ template: { transformAssetUrls: { includeAbsolute: false } } })],
  // public/ is the web root (index.php, setup.php, media). Copying it into public/build
  // published stale front controllers and the installer under /build/.
  publicDir: false,
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
          if (id.includes('node_modules/chart.js/')) return 'charts';
          if (id.includes('node_modules/@lucide/')) return 'icons';
          if (id.includes('node_modules/vue/') || id.includes('node_modules/pinia/')) return 'vue-core';
          return undefined;
        },
      },
    },
  },
});
