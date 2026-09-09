# Scythe Snippet Set Inheritance

Shopware 6.7 plugin that lets a snippet set inherit unmaintained translation
keys from another, freely selectable snippet set — dynamically, at runtime, in
both the Administration and the Storefront.

## What it does

Shopware snippet sets only fall back to their **base file** for keys you have not
translated. This plugin adds an optional **parent set** to every snippet set. For
a key that is not maintained in a set, the effective value is resolved in this
order:

1. The set's own maintained value (unchanged core behaviour).
2. The effective value of its **parent set**, resolved recursively along the
   whole parent chain — a maintained (DB) value of the nearest ancestor first,
   then a base-file value of the nearest ancestor.
3. The effective value of the configured **system fallback set** (plugin
   configuration).
4. The set's own **base file** (unchanged core behaviour).
5. Empty / "not translated" (unchanged core behaviour).

Inheritance is **live**: changing a value in a parent set, or re-pointing a
parent link, immediately affects every dependent child set (the affected
translation catalogs are invalidated automatically).

## Where you maintain things

### Assign a parent set

**Settings → Snippets** (the snippet set list). Each row has an inline
**"Parent set"** column — pick any other snippet set, or clear it. A freshly
added set drops straight into inline edit, so this works for both creating and
editing a set. A set cannot be its own parent, and cycles are rejected on save.

### Plugin configuration

**Extensions → My extensions → Scythe Snippet Set Inheritance → Configure** (or
Settings → System → Plugins):

| Setting | Meaning |
|---|---|
| **Default snippet set (system language fallback)** | Used as step 3 for every set that does not resolve a value through its own parent chain. Leave empty to skip step 3. |
| **Maximum inheritance depth** | Hard cap on how many parent levels are traversed (default 10). A safety net only — cycles are prevented on save regardless. |

Config keys: `ScytheSnippetSetInheritance.config.fallbackSnippetSetId`,
`ScytheSnippetSetInheritance.config.maxInheritanceDepth`.

### Snippet values

The normal snippet editors (**Settings → Snippets → open a set**, and the
single-key editor):

- An inherited value shows an **"Inherited from &lt;set&gt;"** badge / hint.
- The single-key editor's "Original" line shows the value a **reset** would fall
  back to — the inherited value, not the base-file default.
- Resetting your own value (the existing core "restore" action) simply lets the
  inheritance chain take over again.

## Things to watch out for

- **No freezing.** Inherited values are always resolved live; there is no
  "copy the parent's values into the child" action. That is intentional.
- **Parent base file vs. child base file.** A parent's own base file is part of
  its effective value, so if parent and child use *different* base files, the
  parent's base-file value wins over the child's for keys the child does not
  maintain.
- **Deleting a parent set** does not cascade: the FK is `ON DELETE SET NULL`, so
  child sets just lose the link and fall back to step 3/4.
- **Performance.** Resolution happens once per set-catalog build. For the
  Storefront that means only on a cache miss, so there is no per-request or
  per-snippet cost. Deep chains cost one extra catalog build per ancestor on
  that cache miss.
- **Core coupling.** The plugin decorates `Shopware\Core\System\Snippet\SnippetService`
  and overrides three `sw-settings-snippet` Administration templates. Re-test
  after every Shopware minor update.
- **Rebuild after admin changes.** The compiled Administration assets in
  `src/Resources/public/` are committed. Run `bin/build-administration.sh` and
  commit the result whenever you change anything under
  `src/Resources/app/administration/`.

## Data model

The plugin adds one nullable, self-referencing column to the core `snippet_set`
table: `parent_id` (FK to `snippet_set.id`, `ON DELETE SET NULL`), exposed via a
DAL `EntityExtension` as `parentId` plus `parent` / `children` associations.
On a clean uninstall (user did not choose "keep data") the column and FK are
dropped again.

## Install

```
composer require scythe/snippet-set-inheritance
bin/console plugin:refresh
bin/console plugin:install --activate ScytheSnippetSetInheritance
bin/console assets:install
bin/console cache:clear
```

The migration adds `snippet_set.parent_id` automatically on install, including
for snippet sets that already existed.

## Tests

```
vendor/bin/phpunit -c custom/plugins/ScytheSnippetSetInheritance/phpunit.xml.dist
```

Unit tests cover the inheritance resolver (chain, cycle detection, depth) and the
`SnippetService` decorator (priority order, multi-level chains, badge metadata);
integration tests cover the entity extension, the write validation and the real
Storefront catalog path.
