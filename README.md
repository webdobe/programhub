# programhub

Drupal backend for the ProgramHub / CTE Network platform. Serves JSON:API to
the per-program Next.js sites (`cite-nextjs`, `welearndesign`, future
`cyber-site`, `culinary-site`, `paralegal-site`).

```
.
├── drupal/                    Drupal 11 app (composer root, docroot at web/)
├── docker-compose.production.yml   php + nginx + varnish + redis stack
├── dploy.yml                  local + production deploy targets
└── .dploy/scripts/            production deploy scripts
```

## Local dev

```bash
ddev start                     # starts the stack at https://programhub.ddev.site:8443
cd drupal && ddev composer install
ddev drush cim -y              # import config from drupal/config/sync
ddev drush cr
ddev drush uli                 # one-time admin login link
```

`dploy local` runs the same sequence end-to-end.

## Deploy

GitHub Actions deploys on push to `main`. Ad-hoc / hotfix path:

```bash
dploy production
```

Both routes invoke `.dploy/scripts/prod/deploy-drupal.sh`. Database + file
snapshots:

```bash
dploy capture production
dploy snapshots production
dploy restore <snapshot-id> production
```

## Operational drush commands

These are the recurring data-loading + maintenance jobs. All are wrappers
around services in `drupal/web/modules/custom/programhub_*`.

| Command | Aliases | What it does |
| --- | --- | --- |
| `drush programhub:careers:refresh` | `phcr` | Refresh `career_outcome` nodes from BLS OEWS wages + O*NET tasks. Add `--dry-run` to preview. |
| `drush programhub:courses:import` | `phci` | Scrape the NIC catalog and create/update `course` nodes for one or all programs. |
| `drush programhub:certificates:import` | `phcert` | Import certificates from their configured catalog URLs. |
| `drush programhub:profile-paths:rebuild` | `php-paths` | Backfill or rebuild `field_path` on every `profile`. |

`drush list --filter=programhub` for full details on flags.

### Careers refresh — quick reference

The careers refresh is the long-running one (parses an ~80 MB / 414k-row BLS
XLSX). Two ways to run it:

**1. Drush (recommended for production)** — no HTTP layer, no proxy timeouts.

```bash
# Local
ddev drush programhub:careers:refresh --dry-run
ddev drush programhub:careers:refresh

# Production — SCP the source files in, then run via docker exec
scp all_data_M_2024.xlsx \
    user@host:/path/to/programhub/files/private/programhub_careers/bls_master.xlsx
scp 'Task Statements.txt' \
    user@host:/path/to/programhub/files/private/programhub_careers/onet_tasks.tsv
ssh user@host 'docker exec programhub-php-1 drush programhub:careers:refresh'
```

**2. Admin form** — `/admin/config/programhub/careers`. Editor-friendly but
the upload + parse goes through traefik → varnish → nginx → php-fpm, and
each layer's timeout/body-size cap has to be cleared. The web path works in
production with the values currently set in `docker-compose.production.yml`
(128 MB body, 600s varnish backend timeout). If a future BLS release breaks
either, bump those.

Source files (download once a year):

- BLS OEWS: <https://www.bls.gov/oes/tables.htm> → `oesm<YY>all.zip` →
  unzip → `all_data_M_<YYYY>.xlsx`
- O*NET tasks: <https://www.onetcenter.org/database.html> → tab-delimited
  bundle → `Task Statements.txt`

Module-level docs (architecture, field mapping, why-it's-shaped-this-way) are
in [`drupal/web/modules/custom/programhub_careers/README.md`](drupal/web/modules/custom/programhub_careers/README.md).

## Custom modules

| Module | Purpose |
| --- | --- |
| `programhub_careers` | BLS + O*NET career outcomes refresh |
| `programhub_course_import` | NIC catalog scrape → `course` nodes |
| `programhub_certificate_import` | Certificate catalog imports |
| `programhub_profile_paths` | Path-alias backfill for `profile` nodes |
| `fieldable_path` | Custom field type for slug-controlling node paths |
| `gdes` | Graphic & web design site customisations |

## Production gotchas

- **Long-running batch ops** — the careers refresh BLS phase takes ~100s.
  Anything in front of it (varnish, traefik, CDN) needs a backend timeout
  >= 600s or it will 503. The values in `docker-compose.production.yml`
  are sized for this. Drush bypasses the whole stack.
- **Large uploads** — the BLS master is ~80 MB. `NGINX_CLIENT_MAX_BODY_SIZE`
  and `PHP_POST_MAX_SIZE` / `PHP_UPLOAD_MAX_FILESIZE` are set to 128 MB.
- **Private file uploads** — bind-mounted at `./files/private` on the host,
  `private://` inside Drupal. Gitignored — content stays on the server.
- **JSON:API consumers** — Next.js sites pull from `/jsonapi/...`. Every
  career_outcome / program / course change should propagate via Drupal's
  varnish_purge integration; see `revalidate` callback in the Next sites.

## Latest OG to Groups upgrade
drush phupgrade && drush cim -y && drush cr && drush deploy:hook -y && drush pgesync && drush phcert && drush phcr




