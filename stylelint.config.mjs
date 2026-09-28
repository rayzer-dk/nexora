export default {
  extends: ['stylelint-config-standard'],
  ignoreFiles: ['public/build/**', 'var/**', 'vendor/**'],
  rules: {
    'selector-class-pattern': null,
    'custom-property-pattern': null,
    'declaration-block-no-redundant-longhand-properties': null,
    'color-function-notation': null,
    'alpha-value-notation': null,
    'media-feature-range-notation': null,

    // Nexora source still contains compact legacy CSS blocks. Keep the quality gate
    // focused on semantic correctness while the design system is normalized.
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

    // Semantic safeguards remain enabled by the standard config:
    // unknown properties/selectors/media features, duplicate declarations,
    // invalid hex values and malformed syntax still fail CI.
  },
};
