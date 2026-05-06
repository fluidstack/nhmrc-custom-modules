# NHMRC Archive Redirect (Drupal 10)

A lightweight Drupal 10 custom module that automatically redirects requests for **unpublished ("archived") nodes** to a configured **landing page** (internal path), based on the node's **content type (bundle)**. It also optionally creates **stored Redirect entities on delete** (for SEO/permanent external link hygiene) when the contrib **Redirect** module is enabled.

---

## Why this module exists

On NHMRC (and similar sites), content is frequently "archived" by **unpublishing** nodes. Users (and search engines) may still request old URLs, which can otherwise result in **403 Access denied** (unpublished content), "broken links", and poor UX.

This module provides:

- **Runtime redirects for unpublished nodes** (avoids 403/denied responses)
- An **admin UI** to manage redirect destinations per content type
- A **fallback path** for any enabled type without a specific mapping
- Optional **Redirect entity creation on node delete** (stored 301 redirects)

---

## Features

### Admin-configurable behaviour

- **Status code toggle:** `301` (Permanent) or `302` (Temporary)
- **Per-bundle enable/disable:** bundles are loaded dynamically from Drupal; admins choose which bundles apply
- **Per-bundle destination path:** internal path only (e.g. `/about-us/news-centre`)
- **Fallback destination path:** internal path only, used when a bundle is enabled but no mapping exists
- **Logging:** optional Notice-level logs on redirect events
- **SEO delete redirects (optional):** create stored Redirect entities when content is deleted (requires Redirect module enabled)

### Cache integration (Internal Page Cache & Dynamic Page Cache)

Redirect responses are fully cacheable and correctly integrate with both Drupal's **Internal Page Cache** and **Dynamic Page Cache** modules:

- All redirect responses use `LocalRedirectResponse` (restricts to internal paths) with proper cache metadata attached.
- Each redirect is tagged with the originating node's cache tag (`node:{nid}`) and varies by `url.path` cache context.
- When a node transitions from unpublished to published, the module's `hook_entity_update()` invalidates the relevant cache tags, immediately evicting any stale cached redirects.
- Stored delete-page redirects carry a custom cache tag (`nhmrc_archive_redirect:source:{path}`) so they are also invalidated when the path is reclaimed by new or re-published content.

This means visitors see the live page immediately after re-publication, with no manual cache clearing required.

### Safety / guardrails

- **Internal paths only** (enforced via `LocalRedirectResponse`)
- **Front page (`/`) is not allowed** (restricted to "normal internal paths only")
- **Loop protection:** if the current path equals the destination, no redirect is performed
- Only runs for `entity.node.canonical` requests, and only for unpublished nodes

---

## Requirements

- Drupal Core: **^10**
- Core dependency: `node`
- Optional: contrib **Redirect** module (`drupal/redirect`) if you want stored redirects on delete

> Note: The module does **not** hard-depend on Redirect. If Redirect is not enabled, the "create redirects on delete" option will have no effect.

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
  nhmrc_archive_redirect.permissions.yml
  nhmrc_archive_redirect.services.yml
  nhmrc_archive_redirect.module              # hook_entity_update (cache invalidation), hook_entity_predelete/delete
  config/
    install/
      nhmrc_archive_redirect.settings.yml
    schema/
      nhmrc_archive_redirect.schema.yml
  src/
    EventSubscriber/
      ArchivedNodeRedirectSubscriber.php     # Runtime redirects for unpublished nodes (onRequest + onException)
      DeletedPageRedirectSubscriber.php      # Serves stored redirects for previously deleted node URLs
    Form/
      ArchiveRedirectSettingsForm.php
  tests/
    src/
      Functional/
        ArchiveRedirectBehaviorTest.php
      FunctionalJavascript/
        ArchiveRedirectSettingsFormTest.php
      Unit/
        RemovePathRuleTest.php
  README.md
```
