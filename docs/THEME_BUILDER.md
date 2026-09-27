# Theme Token and Builder system

The presentation layer is stored as validated structured data, never as arbitrary PHP.

Inheritance order: Core defaults -> active theme tokens -> user overrides.

Four first-party builders share the same block contract: Home/Page, Header, Footer and Product. Each published layout creates a revision; draft and preview changes do not replace the live revision. If a revision is invalid or cannot be loaded, the runtime falls back to Safe Layout.

A block may define properties, style tokens and visibility rules. Unknown block types are rejected at publish time. Raw PHP is never accepted. Custom JavaScript is not part of normal theme settings and requires a privileged developer extension.
