=== Avesta Sync ===
Contributors: avestatech
Tags: sync, deploy, staging, woocommerce, migration
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Push and pull only what changed between two WordPress sites: themes, plugins, content, media and WooCommerce catalog, with review and backups.

== Description ==

Avesta Sync keeps a local or staging site and a live site in step without full-site migrations. It compares both sites by SHA-256 hash, shows you exactly what differs, and transfers only the items you approve, in either direction.

Install it on both sites, paste one site's secret key into the other, and click Compare.

= What it syncs =

* **Theme files** for the themes you choose
* **Plugin folders** you choose (activation state is never touched)
* **Content**: pages, posts, custom post types, block templates and template parts, navigation menus, and a few core reading options
* **Media**: upload files and their Media Library entries
* **WooCommerce catalog and store settings** (optional, see below)

= How it works =

1. **Compare.** Both sites build a list of hashes. Nothing is written.
2. **Review.** Every differing item gets a status: changed locally, changed remotely, new, deleted, or conflict. Search, filter, and open a side-by-side diff of any text file or content item.
3. **Apply.** Choose push or pull per item or in bulk. Items transfer one at a time with a live log.

After the first sync the plugin remembers what was last in step, so later compares know which side changed and suggest the right direction. Items changed on both sides are flagged as conflicts and never get a default direction.

= Safety =

* **Automatic backups.** Anything overwritten or deleted is copied to a protected folder first. Browse past runs and restore a file with one click.
* **Deletions are opt-in.** They are never preselected and always ask for confirmation.
* **Signed requests.** Every call between the two sites is signed with HMAC-SHA256 and time-limited, write requests are replay-protected, and failed attempts are throttled. HTTPS is required for non-local sites.
* **Guard rails.** `wp-config.php`, `.env` files, `.git`, `node_modules`, logs and must-use plugins are never synced. PHP files that fail to parse are refused. Executable file types are never written into uploads.

= WooCommerce =

Design your catalog on staging and push it to the live store, or pull live edits back, product by product.

* **Products**: simple, variable (with all variations), grouped and external, with prices, attributes, images, linked products and downloads
* **Categories, tags, brands, shipping classes, attributes** and their terms
* **Coupons**
* **Store settings** from a fixed list: general, product, tax options and tax rates, shipping zones with flat rate, free shipping and local pickup, account options

Products are matched by SKU (or slug) and written through WooCommerce itself, so variations, filters, price sorting and linked products keep working on the other site even though database IDs differ.

What it never writes: orders, customers, sessions, reviews, sales counts, coupon usage counts, payment gateway settings, API keys, webhooks and email settings.

**Live stock is protected.** A push leaves stock quantity, stock status and stock management on the receiving store untouched, so sales made since your last sync are not lost. Tick "Also overwrite stock quantities" for a single run when you really want to replace them.

WooCommerce sync must be enabled in Avesta Sync settings on both sites, and both must run the same major WooCommerce version.

= More =

* **Exclude patterns** such as `*.map` or `themes/my-theme/src/*`
* **History** of every sync run: who, when, what, and the result
* **Scheduled check** that compares on a schedule and emails you when the sites drift apart. It never applies changes on its own.
* **WP-CLI**: `wp avesta-sync status`, `push`, `pull` (with `--dry-run`), `backups`, `restore`
* Large files transfer in chunks sized to the remote server's limits
* Translated into Persian, Arabic, Spanish, French, German, Italian, Portuguese (Brazil), Russian, Turkish, Chinese (Simplified) and Japanese; RTL ready

= Who it is for =

Developers and site builders who work on a local or staging copy and want to ship theme changes, a new page, or a batch of images to the live site (or bring live edits back) without exporting databases or overwriting everything.

= External connections =

Avesta Sync connects only to the remote WordPress site whose URL you enter in its settings, and only when you run a test, compare, sync, or enable the scheduled check. It sends no data to the plugin author.

One exception, for Persian only: when your WordPress profile language is Persian, the Avesta Sync admin screen loads the Vazirmatn font from Google Fonts. Your browser then contacts `fonts.googleapis.com` and `fonts.gstatic.com`, which receive your IP address and browser details. No site data is sent. This service is provided by Google: [terms of service](https://policies.google.com/terms), [privacy policy](https://policies.google.com/privacy).

== Installation ==

1. Install and activate Avesta Sync on **both** sites.
2. On the remote site (for example production), open **Tools > Avesta Sync > Settings** and copy **This site's secret key**.
3. On the site you work from, open **Tools > Avesta Sync > Settings**, enter the remote site URL and paste that key into **Remote secret key**.
4. Choose what to sync (themes, plugins, content, media, WooCommerce) and save. For WooCommerce, enable it in the settings of both sites.
5. Click **Test connection**, then open the **Sync** tab and click **Compare**.

== Frequently Asked Questions ==

= Does it need to be installed on both sites? =

Yes. The site you click from talks to the plugin's REST endpoints on the other site.

= Does Compare change anything? =

Compare does not change any site content or files. It records the hashes of identical items so later compares can tell which side changed, and it tidies the plugin's own old backups according to your retention setting.

= What is a conflict? =

An item that differs on both sides with no record of which one changed, or that changed on both sides since the last sync. On a first compare every differing item is a conflict. Use the diff viewer, then pick push or pull per item or for all conflicts.

= What happens to files that get overwritten or deleted? =

They are copied to `wp-content/uploads/avesta-sync-backups-<random>/<run>/` on the receiving site before the change. The folder has a random, unguessable name, carries a deny rule for Apache, and stores files under a neutral extension so nothing in it can be executed. Restore from the **Backups** tab.

= Is it safe to give another site my key? =

Whoever holds a site's key can write theme and plugin files to that site, so treat it like an administrator password. Use HTTPS, share it only with sites you control, and regenerate it in Settings if it leaks.

= Does it sync the whole database? =

No. It syncs selected items, not database tables. Users, orders, comments and the settings of other plugins are not synced. With WooCommerce sync enabled, products, catalog terms, coupons and a fixed list of store settings are synced as items.

= Will pushing products overwrite my live stock or orders? =

No. Orders and customers are never written. Stock quantity, stock status and the manage-stock setting of an existing product are left alone unless you tick "Also overwrite stock quantities" in the apply dialog for that run. A brand-new product receives the stock values from the sending site once.

= Which WooCommerce data is not synced? =

Orders, refunds, customers, reviews, sales counts, coupon usage, payment gateway settings, API keys, webhooks, email settings, analytics, and shipping methods other than flat rate, free shipping and local pickup. Product types added by other plugins (subscriptions, bookings, bundles) are listed as skipped. Product data that other plugins store in hidden fields may not travel.

= What happens when I delete a product on one site and sync the deletion? =

The product is moved to Trash on the other site, never deleted permanently, so old orders keep their link to it and you can restore it. Deletions are never preselected.

= Does it sync plugin activation? =

No. Only plugin files are transferred. Activate or deactivate plugins yourself.

= Can it run automatically? =

The scheduled check can compare automatically and email you a summary. Applying changes always needs a person, in the admin screen or with WP-CLI.

= Does it work with page builders or custom post types? =

Content sync covers pages, posts, custom post types that have an admin screen, block templates, template parts, menus and media entries. A custom post type must be registered on both sites. It works best with the block editor. Content and its custom fields are copied as stored, so page builder layouts usually travel, but page builders are not officially tested: image IDs inside layouts are not remapped, builder caches may need regenerating, and builder settings kept in site options or separate tables are not synced. Developers can adjust the list with the `avesta_sync_post_types` filter.

= Which hosts does it work on? =

Any host where the WordPress REST API is reachable. If pretty permalinks are off, the plugin falls back to `?rest_route=`. Some security plugins or firewalls block custom headers or REST writes; allow the `avesta-sync/v1` routes if the connection test fails.

== Screenshots ==

1. Dashboard: connection status, last sync, last check and backups at a glance.
2. Compare results grouped by theme, plugin, content and media, with search and status filters.
3. Side-by-side diff of a file before you push or pull it.
4. Backups: browse past runs and restore a file with one click.
5. History of sync runs.
6. Settings: remote site, what to sync, exclude patterns, scheduled check.
7. WooCommerce products, categories and store settings in the compare list, with the stock option in the apply dialog.

== Changelog ==

= 1.2.0 =
* New: WooCommerce sync for products (simple, variable, grouped, external), categories, tags, brands, attributes, shipping classes, coupons and a fixed list of store settings.
* New: live stock protection with an explicit "Also overwrite stock quantities" option per run.
* New: orders, customers, coupon usage, payment gateways and API keys are never written.
* New: readable names in Backups and plain-language results in the log and History.
* Fixed: two requests sent in the same second could be rejected as a replay.
* Security: receiving sites enforce their own sync settings; item types must match their keys.

= 1.1.0 =
* New: tabbed admin screen with dashboard, sync, backups, history and settings.
* New: search, status filters and per-group selection in compare results.
* New: side-by-side diff preview for text files and content items.
* New: backups browser with one-click restore and automatic pruning.
* New: sync history.
* New: custom exclude patterns.
* New: Media Library entries are synced along with upload files.
* New: scheduled compare with email notice.
* New: `--dry-run` for `wp avesta-sync push` and `pull`; `backups` and `restore` commands.
* Improved: all messages are translatable; RTL styles.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.2.0 =
Adds WooCommerce catalog and store settings sync with live stock protection. Update both sites.

= 1.1.0 =
New admin screen, diff preview, one-click restore, history, exclude patterns and Media Library sync. Update both sites.
