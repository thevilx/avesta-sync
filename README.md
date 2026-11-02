# Avesta Sync

Two-way sync (push/pull) of theme files, custom plugins, content and media between a local site and production. Only items whose SHA-256 differs are transferred, in chunks, over a signed REST API.

## Install on production
1. Copy the `avesta-sync` folder to `wp-content/plugins/` on production and activate it (a secret key is generated on activation).
2. Go to Tools > Avesta Sync and copy "This site's secret key".

## Configure local
1. On the local site open Tools > Avesta Sync.
2. Enter the production URL (HTTPS required; localhost, `*.localhost`, `*.test` excepted) and paste the production key under "Remote secret key".
3. Pick scopes (theme, plugins, content, uploads), the theme(s) and the plugin folders. Save.
4. Click "Test connection".

## First sync
1. Click **Compare** only (nothing is written by Compare, except baseline hashes of identical items).
2. With no baseline yet, every differing item is a **conflict** and has no default direction. Use "Conflicts: push" or "Conflicts: pull" for the whole list, or choose per row.
3. Review, then **Apply selected**. Items run one by one with a live log. Deletions are never preselected and ask for confirmation.
4. Later compares use a baseline, so one-sided changes get a sensible default (local change = push, remote change = pull).

## CLI
```
wp avesta-sync status [--scope=theme,plugins,content,uploads]
wp avesta-sync push   [--scope=...] [--yes]
wp avesta-sync pull   [--scope=...] [--yes]
```
(On Studio: `studio wp avesta-sync ...`.) CLI push/pull applies only the default direction; conflicts and deletions are left for the admin UI.

## Backups and restore
Anything overwritten or deleted on the receiving side is copied first to `wp-content/avesta-sync-backups-<token>/<YYYYmmdd-His>/<relative path>` (content items as JSON under `content/`). The directory is outside uploads, has a random name, and carries deny files. To restore, copy the file back by hand. If an old `wp-content/uploads/avesta-sync-backups` exists from an early version, delete it; it is excluded from sync. Add `avesta-sync-backups-*` to your own VCS ignore list (this plugin does not edit `.gitignore`).

## Security notes
- Every request is signed (HMAC-SHA256 over method, route, timestamp, body hash); 300 s skew limit, replayed signatures rejected, failed attempts throttled.
- **Whoever holds a site's key can write PHP files to that site.** Keep it secret, use HTTPS only, regenerate the key on the "This site's key" card if it leaks (then update the other site).
- PHP files that fail to parse are refused. Executable file types are never written into uploads. `wp-config.php`, `.env`, `.git`, `node_modules`, logs, mu-plugins and this plugin are never synced.
- Plugin activation state is never synced.

## Limits
- Attachment DB rows are not synced, only the files; regenerate thumbnails / re-import media if new files do not appear in the library.
- `wp_navigation` block references (`ref` IDs) are not remapped between sites.
- Posts with an empty slug are skipped; featured images map by file name only if the attachment already exists.
- Options synced: blogname, blogdescription, show_on_front, page_on_front, page_for_posts (pages mapped by slug).
