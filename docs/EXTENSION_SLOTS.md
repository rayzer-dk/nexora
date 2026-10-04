# Extension slots

A module puts its own block into a page through a **slot** declared in `manifest.json` under `slot_contributions` (declarative modules supply content, trusted modules can supply logic). `ui_slots` lists the slots a module uses.

The list is the `ExtensionPoint` enum; `php bin/console commerce:extension:list-points` prints the same names. `ExtensionSlotDocsTest` fails when this table and the code differ, so add a row here whenever you add a slot.

| Slot | Where | Position |
| --- | --- | --- |
| `product.before_gallery` | Storefront · product page | before |
| `product.after_gallery` | Storefront · product page | after |
| `product.before_title` | Storefront · product page | before |
| `product.after_title` | Storefront · product page | after |
| `product.after_price` | Storefront · product page | after |
| `product.before_buy` | Storefront · product page | before |
| `product.after_buy` | Storefront · product page | after |
| `product.before_description` | Storefront · product page | before |
| `product.after_description` | Storefront · product page | after |
| `product.after_attributes` | Storefront · product page | after |
| `product.after_reviews` | Storefront · product page | after |
| `category.before_grid` | Storefront · category page | before |
| `category.after_grid` | Storefront · category page | after |
| `cart.before_summary` | Storefront · cart | before |
| `cart.after_summary` | Storefront · cart | after |
| `account.dashboard` | Storefront · customer account | block / list |
| `checkout.before_contact` | Storefront · checkout | before |
| `checkout.after_contact` | Storefront · checkout | after |
| `checkout.before_shipping` | Storefront · checkout | before |
| `checkout.after_shipping` | Storefront · checkout | after |
| `checkout.before_payment` | Storefront · checkout | before |
| `checkout.after_payment` | Storefront · checkout | after |
| `checkout.before_summary` | Storefront · checkout | before |
| `checkout.after_summary` | Storefront · checkout | after |
| `admin.product.sidebar` | Admin · product form | block / list |
| `admin.product.after_main` | Admin · product form | after |
| `admin.category.after_main` | Admin · category form | after |
| `admin.order.actions` | Admin · order page | block / list |
| `admin.order.sidebar` | Admin · order page | block / list |
| `admin.customer.sidebar` | Admin · customer page | block / list |
| `admin.dashboard` | Admin · dashboard | block / list |
| `admin.extensions.toolbar` | Admin · extensions page | block / list |
| `navigation.items` | Storefront · navigation | block / list |
| `content.page` | Storefront · content page | block / list |
| `storefront.product.after_price` | Storefront · product page | legacy alias, kept for old modules |
| `storefront.checkout.before_payment` | Storefront · checkout | legacy alias, kept for old modules |

Rules: a slot name is stable API (renaming it is a breaking change); a `slot_contributions` entry for an unknown slot is rejected when the package is installed.
