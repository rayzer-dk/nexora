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
    'at-rule-empty-line-before': null,
    'rule-empty-line-before': null,
    'comment-empty-line-before': null,
    'declaration-empty-line-before': null,
    'custom-property-empty-line-before': null,
    'selector-attribute-quotes': null,
    'selector-pseudo-element-colon-notation': null,
    'color-hex-length': null,
    'value-keyword-case': null,
    'shorthand-property-no-redundant-values': null,
    'declaration-property-value-keyword-no-deprecated': null,

    // Keep Stylelint focused on defects that can break rendering or browser parsing.
    // Design-system duplication/specificity debt is tracked separately during the 3.6.x CSS normalization.
  },
};
