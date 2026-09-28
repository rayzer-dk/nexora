import js from '@eslint/js';
import vue from 'eslint-plugin-vue';
import tseslint from 'typescript-eslint';

export default [
  { ignores: ['vendor/**', 'public/build/**', 'var/**', 'node_modules/**'] },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  ...vue.configs['flat/recommended'],
  {
    files: ['assets/**/*.{js,ts,vue}', 'tests/e2e/**/*.ts', 'vite.config.ts', 'playwright.config.ts'],
    languageOptions: { ecmaVersion: 'latest', sourceType: 'module' },
    rules: {
      'no-console': ['warn', { allow: ['warn', 'error'] }],
      'no-debugger': 'error',
      'eqeqeq': ['error', 'always'],
      'prefer-const': 'error',
      'vue/multi-word-component-names': 'off'
    }
  }
];
