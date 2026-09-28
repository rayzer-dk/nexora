export default {
  extends: ['stylelint-config-standard'],
  ignoreFiles: ['public/build/**', 'var/**', 'vendor/**', 'node_modules/**'],
  rules: {
    'selector-class-pattern': null,
    'custom-property-pattern': null,
    'declaration-block-no-redundant-longhand-properties': null,
    'declaration-block-single-line-max-declarations': null,
    'color-function-notation': null,
    'color-function-alias-notation': null,
    'alpha-value-notation': null,
    'media-feature-range-notation': null,
    'no-descending-specificity': null,
    'no-duplicate-selectors': null,
    'selector-not-notation': null,
    'property-no-vendor-prefix': null,
    'value-no-vendor-prefix': null,
  },
};
