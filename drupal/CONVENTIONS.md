# Drupal conventions

How changes to this Drupal app reach production. Read this before making
backend changes — every workflow below exists because something that "worked
in DDEV" later broke the deploy.

The contract: **everything we change has to run automatically when
`drush deploy` runs on production.** No manual SSH steps, no "I'll remember
to run that one command." If a change can't be expressed as config-sync YAML
or an update/post_update hook, it doesn't get done.

`drush deploy` (run from `.dploy/scripts/prod/deploy-drupal.sh`) executes,
in order:

1. `drush updatedb` — runs `hook_update_N()` (schema) and
   `hook_post_update_NAME()` (data, after schema is current).
2. `drush config:import` — applies everything in `drupal/config/sync/`.
3. `drush cache:rebuild`.
4. `drush deploy:hook` — runs `hook_deploy_NAME()` (post-config tasks).

Two practical implications:

- **`hook_post_update_NAME` runs BEFORE config import.** It can't reference
  config that's only being added in the same deploy.
- **`hook_deploy_NAME` runs AFTER config import.** Use this for any data
  setup that depends on newly imported fields, vocabularies, pathauto
  patterns, etc.

That ordering decides which hook your code goes in. See "Picking the right
hook" below.

---

## Configuration changes (CMI)

Every change to fields, content types, displays, vocabularies, pathauto
patterns, views, OG group bundles, etc. is **configuration**. Configuration
lives as YAML in `drupal/config/sync/` and is applied via
`drush config:import`.

### To add or change config

1. Make the change in the DDEV admin UI (or modify the YAML by hand).
2. Export to disk: `ddev drush config:export -y` (writes the diff into
   `drupal/config/sync/`).
3. Review the diff. Commit only the relevant files.
4. On deploy, `drush deploy` calls `drush cim` and the change applies.

### Never do this

```php
// ❌ Wrong — bypasses config sync. Lives only in the local DB.
\Drupal::configFactory()
  ->getEditable('pathauto.pattern.career_outcomes')
  ->setData(Yaml::parseFile('...'))
  ->save();
```

This is fine **as a one-off in the DDEV shell** to get a quick effect, but
it does not deploy. The YAML on disk is the source of truth; if you want it
in prod, commit the YAML and let `drush cim` apply it on every environment.

### When you need to set defaults / form weights / view modes

Edit the corresponding `core.entity_form_display.<bundle>.default.yml` and
`core.entity_view_display.<bundle>.default.yml` files in `config/sync/`.
Don't call `EntityDisplay->setComponent()->save()` from a shell — same
problem.

---

## Content + data setup (terms, nodes, file moves, alias regen)

Configuration ≠ content. Taxonomy *terms*, content *nodes*, *files*, and
generated *path aliases* live in the database, not in `config/sync/`. They
don't deploy with `drush cim`. To make them ride along on `drush deploy`,
put them in `hook_post_update_NAME()` (or `hook_deploy_NAME()`).

### Where it goes

- **One module per concern** — `programhub_careers`, `programhub_certificate_import`,
  `programhub_course_import`, `programhub_profile_paths`. Pick the module
  that owns the domain. Don't dump everything into one.
- File: `web/modules/custom/<module>/<module>.deploy.php` (post-config) or
  `<module>.post_update.php` (pre-config). See "Post-update vs deploy hook"
  below for which to pick.
- Function name: `<module>_deploy_<NN>_<short_description>()` —
  numeric prefix forces alphabetical ordering when one hook depends on
  another (Drupal runs hooks alphabetically by name).

### Idempotency rule

Post-update hooks run once per environment. They might be re-run on a
restored snapshot, a fresh build, or a recovery. Always check-then-create:

```php
// web/modules/custom/programhub_certificate_import/programhub_certificate_import.post_update.php
<?php

declare(strict_types=1);

/**
 * Add the "Industry Certification" certificate_type taxonomy term.
 */
function programhub_certificate_import_post_update_seed_industry_cert_term(
  array &$sandbox,
): string {
  $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  $existing = $storage->loadByProperties([
    'vid' => 'certificate_type',
    'name' => 'Industry Certification',
  ]);
  if ($existing) {
    return 'Term already exists; skipped.';
  }
  $term = $storage->create([
    'vid' => 'certificate_type',
    'name' => 'Industry Certification',
    'description' => [
      'value' => 'Third-party / vendor exam credential (CompTIA, Cisco, Microsoft, etc.)…',
      'format' => 'basic_html',
    ],
  ]);
  $term->save();
  return sprintf('Created term tid=%d.', $term->id());
}
```

Run it locally with `ddev drush updatedb -y`. The same code path runs on
prod via `drush deploy`.

### Post-update vs deploy hook

| Use | Hook | Runs |
| --- | --- | --- |
| Schema changes (table creation, column adds) | `hook_update_N()` | Step 1 |
| Data setup that depends only on **existing** config | `hook_post_update_NAME()` | Step 1 |
| Anything that depends on config added **in the same deploy** | `hook_deploy_NAME()` | Step 4 |

Decision rule: if the hook's first action references a field, vocabulary,
pathauto pattern, or content type that is being added by the same PR, it
belongs in `hook_deploy_NAME()`. Otherwise `hook_post_update_NAME()` is fine.

Symptom of the wrong choice: post_update fails with "field X does not
exist" or pathauto regen runs before the new pattern is loaded and produces
no aliases. If you see either, the hook should have been a deploy hook.

### Bulk pathauto regen

Pathauto generates aliases on entity save, not on pattern change. After
adding a pattern, regenerate from a post_update:

```php
function mymodule_post_update_regenerate_aliases(array &$sandbox): void {
  $nodes = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadByProperties(['type' => 'career_outcome']);
  foreach ($nodes as $node) {
    $node->path->pathauto = 1;
    $node->save();
  }
}
```

Or use `drush pathauto:aliases-generate all canonical_entities:node` from a
post_update with `\Drush\Drush::input()` — the resave loop above is more
reliable.

---

## Default content (seed nodes that ship with the code)

Content nodes that should exist on every environment (the 8 industry
certification nodes, for example) belong in a post_update too — same
check-then-create pattern as terms. Don't create them via
`drush php:eval` and expect them to follow you.

If a piece of content gets large or has lots of references, consider the
`default_content` contrib module instead — it serializes nodes to YAML you
can ship with a module. We don't currently use it; default to post_update
hooks.

---

## Common patterns to avoid

| Don't | Do |
| --- | --- |
| `drush php:eval '...'` for schema/data setup | `hook_post_update_NAME()` |
| `Drupal::configFactory()->getEditable()->setData()` from the shell | Edit YAML in `config/sync/` + `drush cim` |
| `EntityDisplay->setComponent()->save()` from the shell | Edit `core.entity_form_display.*.yml` + `drush cim` |
| `Term::create()` / `Node::create()` from the shell | post_update with check-then-create |
| Manual `drush en <module>` on prod | Add to module's `core.extension.yml` (autorun by `drush cim`) |
| Editing the database directly | Schema change in `hook_update_N()`; data change in `hook_post_update_NAME()` |

---

## Reviewer checklist

Before opening a PR for backend changes, ask:

1. **Will `drush deploy` apply everything in this PR with no manual steps?**
   If not, what's missing?
2. **Are the YAML diffs in `config/sync/` minimal?** A misclick in the UI
   can re-export every form display in the project. Trim the diff.
3. **Did anything that should have been a `hook_post_update_NAME()` end up
   as a one-off shell command in the PR description?** Move it to a hook.
4. **Will the post_update succeed if it's run twice?** (Restored snapshot,
   re-deploy.) Add the existence check.
5. **Did you run `ddev drush updatedb -y && ddev drush cim -y && ddev drush
   cr` locally on a fresh DB to confirm a clean install works?**

---

## Quick references

- Existing custom modules: `web/modules/custom/programhub_*`
- Config sync: `config/sync/`
- Module post_update files: `web/modules/custom/<module>/<module>.post_update.php`
- Deploy script: `../.dploy/scripts/prod/deploy-drupal.sh`
- Drush commands inventory: see `../README.md`
