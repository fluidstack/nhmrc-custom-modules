# NHMRC Archive Redirect (Drupal 10/11)

A lightweight Drupal custom module that automatically redirects requests for **unpublished ("archived") nodes** to a configured **landing page** (internal path), based on the node's **content type (bundle)**. It also creates **stored redirect records on delete** (for SEO/permanent external link hygiene) using path-prefix rules.

---

## Why this module exists

On NHMRC (and similar sites), content is frequently "archived" by **unpublishing** nodes. Users (and search engines) may still request old URLs, which can otherwise result in **403 Access denied** (unpublished content), "broken links", and poor UX.

This module provides:

- **Runtime redirects for unpublished nodes** (avoids 403/denied responses)
- An **admin UI** to manage redirect destinations per content type
- **Path-prefix rules** for fine-grained control over redirect destinations
- A **fallback path** for any enabled type without a specific mapping
- **Stored redirect records on node delete** (matching path-prefix rules)
- A **stored redirects listing page** for visibility into all active redirect records
- A **purge function** to bulk-remove stored records

---

## Features

### Admin-configurable behaviour

- **Status code toggle:** `301` (Permanent) or `302` (Temporary)
- **Per-bundle enable/disable:** bundles are loaded dynamically from Drupal; admins choose which bundles apply
- **Per-bundle destination path:** internal path only (e.g. `/about-us/news-centre`)
- **Fallback destination path:** internal path only, used when a bundle is enabled but no mapping exists
- **Delete path rules:** prefix-based rules that determine redirect destinations when nodes are deleted
- **Logging:** optional Notice-level logs on redirect events
- **Editor bypass:** configurable permission-based bypass so editors can preview unpublished content
- **Preview token:** configurable query parameter for anonymous preview URLs

### Stored redirects management

- **Listing page** at `/admin/config/content/archive-redirects/stored` — sortable, paginated table showing all stored redirect records with source path, destination, status code, and creation date
- **Path filter** — search stored redirects by source path substring
- **Purge** — bulk delete all stored redirect records from the settings form
- **Auto-sync** — when path rules are modified or removed, associated stored records are updated or deleted automatically

### Cache integration (Internal Page Cache & Dynamic Page Cache)

Redirect responses are fully cacheable and correctly integrate with both Drupal's **Internal Page Cache** and **Dynamic Page Cache** modules:

- All redirect responses use `LocalRedirectResponse` (restricts to internal paths) with proper cache metadata attached.
- Each redirect is tagged with the originating node's cache tag (`node:{nid}`) and varies by `url.path` cache context.
- When a node transitions from unpublished to published, the module's `hook_entity_update()` invalidates the relevant cache tags, immediately evicting any stale cached redirects.
- Stored delete-page redirects carry a custom cache tag (`nhmrc_archive_redirect:source:{path}`) so they are also invalidated when the path is reclaimed by new or re-published content.

This means visitors see the live page immediately after re-publication, with no manual cache clearing required.

### Safety / guardrails

- **Internal paths only** (enforced via `LocalRedirectResponse`)
- **Loop protection:** if the current path equals the destination, no redirect is performed
- Only runs for `entity.node.canonical` requests (unpublished nodes) or stored-record lookups (deleted pages)
- **No-store headers** prevent reverse proxies from caching stale redirects

---

## Requirements

- Drupal Core: **^10 || ^11**
- Core dependency: `node`

---

## Installation

1. Copy the module into your codebase:
   ```
   web/modules/custom/nhmrc_archive_redirect/
   ```

2. Enable it via Drush:
   ```bash
   drush en nhmrc_archive_redirect -y
   drush cr
   ```
   Or via the UI: **Admin → Extend → enable NHMRC Archive Redirect**

3. Configure it at:
   ```
   /admin/config/content/archive-redirects
   ```

4. View stored redirect records at:
   ```
   /admin/config/content/archive-redirects/stored
   ```

---

## Recommended rollout

1. Start with **302** (Temporary) and enable **logging**
2. Test a few unpublished node pages to confirm redirects work as expected
3. Once confirmed, switch to **301** (Permanent)

---

## File structure

```
nhmrc_archive_redirect/
  nhmrc_archive_redirect.info.yml
  nhmrc_archive_redirect.install
  nhmrc_archive_redirect.routing.yml
  nhmrc_archive_redirect.links.menu.yml
  nhmrc_archive_redirect.links.task.yml       # Local task tabs (Settings / Stored redirects)
  nhmrc_archive_redirect.permissions.yml
  nhmrc_archive_redirect.services.yml
  nhmrc_archive_redirect.module               # hook_entity_update (cache invalidation), hook_entity_predelete/delete
  config/
    install/
      nhmrc_archive_redirect.settings.yml
    schema/
      nhmrc_archive_redirect.schema.yml
  src/
    Controller/
      StoredRedirectsController.php           # Paginated listing of stored redirect records
    EventSubscriber/
      ArchivedNodeRedirectSubscriber.php      # Runtime redirects for unpublished nodes (onRequest + onException)
      DeletedPageRedirectSubscriber.php       # Serves stored redirects for previously deleted node URLs
    Form/
      ArchiveRedirectSettingsForm.php
  README.md
```
