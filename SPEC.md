# Avesta Sync 1.2.0: WooCommerce catalog and settings sync

## Goal
Let a store owner or developer design the catalog and store configuration on a local/staging site and push it to the live store (or pull live edits back) item by item, with the same compare, review, apply flow as pages and files, without ever disturbing orders, customers or live stock.

"Correct" means: after a push, the product behaves on the target exactly as on the source (prices, variations, attributes, images, categories, linked products, filters and sorting all work) with no manual repair step.

## Scope

### In
- **Products**: simple, variable (with all variations), grouped, external. All core product data, custom (non-internal) meta, images and gallery, downloadable file entries.
- **Catalog taxonomies**: product categories (hierarchy, thumbnail, display type, order), tags, shipping classes, global attributes and their terms (including term order and swatches stored as term meta).
- **Coupons**, with product and category restrictions remapped.
- **Store settings** from an allow-list: General, Products, Tax options and tax rate tables, Shipping zones with methods and their instance settings, shipping options, Accounts and privacy options.
- Two-way: compare, per-item push or pull, conflicts, diff preview, backups, history, CLI. Same engine as today.
- Stock overwrite as an explicit per-run choice, off by default.

### Out (never written by this plugin)
- Orders (posts or HPOS tables), refunds, order notes.
- Customers, users, sessions, carts, download permissions.
- Reviews and ratings, sales counters.
- Payment gateway settings, API keys, webhooks, email sender settings, licence keys, integration credentials. Not selectable, no opt-in.
- Analytics, reports, action scheduler, logs.
- Third-party product types (subscriptions, bookings, bundles, composites): listed as "unsupported type", not synced. Filter `avesta_sync_wc_product_types` for developers.
- Multi-currency and translation plugins.

## Stack and files
PHP in the existing plugin, no new dependencies. All product writes go through the WooCommerce CRUD API (`wc_get_product`, setters, `save()`), never raw post meta, so lookup tables, transients and HPOS compatibility stay correct. Every file under 500 lines.

| File | Owner | Purpose |
|---|---|---|
| `includes/woo/class-woo.php` | wp-builder | Availability, version preflight, item-type registry, ordering, hooks into Content/Importer |
| `includes/woo/class-woo-refs.php` | wp-builder | Portable references: product by SKU then slug, term by taxonomy + slug, attachment by file path |
| `includes/woo/class-woo-products.php` | wp-builder | Export and import of products with embedded variations |
| `includes/woo/class-woo-taxonomies.php` | wp-builder | Global attributes, categories, tags, shipping classes |
| `includes/woo/class-woo-coupons.php` | wp-builder | Coupons |
| `includes/woo/class-woo-settings.php` | wp-builder | Allow-listed options, tax rates, shipping zones |
| `includes/class-content.php`, `class-importer.php`, `class-settings.php`, `class-rest.php`, `class-ajax.php`, `class-sync.php`, `class-cli.php` | wp-builder | Wiring only: new scope, type dispatch, preflight data in `/info` |
| `includes/views/settings.php`, `views/sync.php`, `views/i18n.php`, `assets/as-sync*.js`, `as-apply.js`, `assets/*.css` | ui-builder | Scope card, WooCommerce group, stock checkbox, blocked notice |

## Data and contracts

### New scope
`woocommerce` added to `Settings::scopes()`. Sub-options (booleans, default true): `wc_products`, `wc_taxonomies`, `wc_coupons`, `wc_settings`. When the scope is on, the generic post sync must skip `product`, `product_variation`, `shop_coupon`, `shop_order*` so nothing is handled twice or raw.

### Item keys (identity across sites)
| Key | Identity | Notes |
|---|---|---|
| `wc_attr:<slug>` | attribute slug | name, type, orderby, public |
| `wc_term:<taxonomy>:<slug>` | taxonomy + slug | parent by slug, description, term meta, thumbnail by file path |
| `wc_product:sku:<sku>` or `wc_product:slug:<slug>` | SKU when set, else slug | variations embedded; one item per product, applied atomically |
| `wc_coupon:<code>` | coupon code | product and category restrictions as portable refs |
| `wc_set:<group>` | group name | `general`, `products`, `tax`, `tax_rates`, `shipping_zones`, `shipping_options`, `accounts` |

Variations are matched inside their parent by SKU, else by their attribute combination. A variation missing on the source is trashed on the target only when the parent item is applied.

### References are never raw IDs
Upsells, cross-sells, grouped children, coupon restrictions, parent categories, images, gallery, default attributes: exported as portable refs, resolved on import. An unresolved ref is skipped and reported in the item's result message, never guessed.

### Apply order
attributes, terms, media entries, products, coupons, settings (`ord` values). Linked products that do not exist yet are linked on a later apply; the result message says so.

### Hash rules
Excluded from hashes and from normal writes on an existing product: stock quantity, stock status, total sales, rating data, review count, dates modified, IDs, GUIDs, site URLs (tokenised as today). A new product receives the source stock values once.

### Stock overwrite
Apply request gains `stock: bool`. When true, stock quantity and stock status are written for the product items in that run. UI: one checkbox in the apply confirm dialog, shown only when product items are selected, unchecked on every open.

### Preflight (`/info` additions)
`wc: { active, version, hpos }` on both sides. WooCommerce sync is blocked with an explanation when either side has it inactive or major versions differ; minor differences show a warning. Other scopes keep working.

### Deletion
Pushing or pulling a deletion moves the product or coupon to Trash (never permanent). Terms and attributes are deleted only when no product on the target uses them; otherwise the item fails with a clear message. Never preselected, always confirmed, JSON backup first.

### Settings
Allow-list of option names per group, defined in code, filterable with `avesta_sync_wc_setting_keys` to remove keys only. Anything not on the list is not exported. Tax rates and shipping zones are compared as whole sets and replaced as a set, after backup. Shipping methods whose settings contain credentials (live-rate carriers) are skipped and reported.

### Backups and history
Existing mechanism. Each overwritten product, coupon, term or settings group is saved as JSON importable by the same importer, so one-click restore works.

## UI and responsive behaviour
- Settings: a "WooCommerce" scope card with four sub-checkboxes; disabled with a reason when WooCommerce is inactive locally.
- Sync: items appear in a "WooCommerce" group with sub-headers (Attributes, Categories and tags, Products, Coupons, Store settings). Product label: name plus SKU, type badge for variable/grouped.
- Diff: side-by-side readable JSON of the item (variations listed under the product).
- Blocked state: notice at the top of the Sync tab naming the reason (inactive, version mismatch).
- Same rules as 1.1.0: no horizontal scroll at 375, 44px targets at touch widths, RTL, all strings translatable.

## Edge cases
- Product without SKU on both sides: matched by slug. SKU added later: old slug item shows as deleted plus new; documented.
- Same SKU on two products of one site: item reported as ambiguous, not synced.
- Variable product with 100+ variations: one item, chunk-safe request size; refuse above the remote `post_max_size` with a clear message.
- Attribute used by products but missing on target: created first by apply order; if the user deselects it, the product import creates it.
- Image file not yet on target: product imports without that image and says so (sync Media uploads first).
- Target has open orders for a product being trashed: allowed, since Trash keeps order history.
- A 1.1.0 peer: WooCommerce items are not listed by it; pushing to it yields a clean per-item "update Avesta Sync on both sites" error.
- `DISALLOW_FILE_MODS` does not affect catalog sync (database only).

## Acceptance criteria
Tested on two throwaway Studio sites with WooCommerce and its sample products, never on this site or production.
- [ ] Push all sample products to an empty store: compare afterwards shows zero differences; a second compare is identical.
- [ ] On the target: a variable product can be added to cart per variation, layered attribute filters and price sorting work, category pages show the right products and thumbnails.
- [ ] Target product IDs differ from source IDs and upsells, cross-sells, grouped children and coupon restrictions still point at the right products.
- [ ] Change price and description on source, place a test order on target that lowers stock, push: price and description update, stock and sales count on target are unchanged, the order is intact.
- [ ] Same push with the stock checkbox ticked: stock is overwritten.
- [ ] Edit a product on both sides: shown as conflict, no default direction.
- [ ] Pull a product edited on target back to source.
- [ ] Push a deletion: product is in Trash on target, restorable, order history intact.
- [ ] Tax rates and a shipping zone with two methods push and work at checkout on target; payment gateway settings on target are byte-identical before and after.
- [ ] Orders, customers, reviews tables on target have identical row counts and checksums before and after every test above.
- [ ] WooCommerce inactive on one side or major version mismatch: catalog sync blocked with message, file and page sync still work.
- [ ] Restore of an overwritten product from Backups returns it to the previous state.
- [ ] Plugin Check reports no errors; security review has no blocker or high finding; visual QA passes at 375, 768, 1280 in RTL and LTR.

## Open questions (defaults chosen)
- Product reviews: not synced (they are customer data). Default stands unless asked.
- Downloadable product files: the file entries sync; the files themselves travel only through the Media uploads scope.
- Product matching when SKU differs but slug matches: treated as the same product only if the SKU is empty on one side; otherwise two different products.
- Sale schedule dates sync as stored (site timezone differences are not adjusted).
- Version: 1.2.0, first WordPress.org submission; 1.1.0 is not submitted separately.
