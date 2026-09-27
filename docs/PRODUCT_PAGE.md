# Product Page Composer

The product page is a conversion-critical surface, so the platform does not use a free-form pixel canvas.

A free-form canvas makes responsive behaviour, accessibility, structured data, Core Web Vitals and extension compatibility fragile. Instead, the platform uses a constrained Product Page Composer:

- regions define stable responsive layout areas;
- blocks define business/UI capabilities;
- administrators can reorder blocks, move blocks to compatible regions and configure block settings;
- extensions add new block types through ProductBlockProviderInterface;
- templates are resolved only through ProductBlockRegistry, never from an arbitrary user-provided path;
- desktop and mobile order can differ;
- every saved layout is schema-versioned and migratable;
- product data is passed in one product projection; blocks do not query the database themselves.

Default regions:
- hero_media
- hero_summary
- below_primary
- below_secondary
- mobile_sticky

This keeps the page flexible without allowing layouts that break at mobile widths or after an extension update.

Future Composer admin:
- drag-and-drop reorder;
- visibility rules by store, market, product type, category and customer group;
- reusable presets;
- per-category default layout;
- per-product override only when needed;
- safe preview for desktop/tablet/mobile;
- layout history and rollback;
- extension blocks with declared settings schema;
- performance budget validation before publish.
