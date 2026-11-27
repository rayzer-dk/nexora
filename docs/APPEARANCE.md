# Appearance system

Admin and storefront use design tokens rather than hard-coded theme values.

The appearance layer supports:

- light, dark and automatic color modes;
- primary/accent/status colors;
- compact, comfortable and spacious density;
- configurable corner radius and content width;
- mobile-first responsive layouts;
- container queries where component-local adaptation is preferable;
- safe-area insets for modern mobile devices;
- reduced-motion accessibility;
- page/component composition without absolute-position layout builders.

Product pages remain block-composable. Theme settings change presentation, while commerce logic and structured data remain independent.

## Nexora Dynamic

Opt-in preset: locally hosted Inter, neutral ink, warm accent, restrained radius and no decorative shadow. The category navigation indicates the current internal route (including a selected submenu item) with a thin underline. The existing banner builder supports full-bleed rows and sliders, mobile artwork, four heights, copy position and overlay. The existing card styles, header builder, sticky header and motion preference remain independently configurable. Dynamic enhances the confirmed cart flight with a curved trajectory and a brief arrival pulse; minimal/off and reduced-motion settings still disable the flight. Other presets remain unchanged.

### Independent presentation controls

The storefront editor offers automatic/classic/compact/spacious/wide-search headers, automatic/underline/pill/plain category navigation, automatic/flight/pulse/minimal/off cart effects, and standard/compact/editorial product sections. Values are allowlisted and stored per shop; defaults preserve the previous layout. Existing card styles, banner controls and grid/carousel builder options remain available. Reduced motion and the browser's Off preference take precedence.
