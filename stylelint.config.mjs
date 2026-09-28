export default {
  extends: ['stylelint-config-standard'],
  ignoreFiles: ['public/build/**', 'var/**', 'vendor/**'],
  rules: {
    'selector-class-pattern': null,
    'custom-property-pattern': null,
    'declaration-block-no-redundant-longhand-properties': null,
    'color-function-notation': null,
    'alpha-value-notation': null,
    'media-feature-range-notation': null
  }
};
