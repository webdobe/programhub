# programhub_careers

Refreshes `career_outcome` nodes from public BLS OEWS wage data and O*NET task
descriptions, keyed on SOC code. Idempotent — re-running produces the same
nodes; editorial fields are preserved on existing nodes.

## What it does

For every SOC code tagged on a published `program` node (`field_soc_codes`),
this module looks up:

- **BLS OEWS wages** — 25th / 50th / 75th percentile annual pay, picking the
  most-local non-suppressed row available (Coeur d'Alene MSA → Idaho → US).
- **O*NET tasks** — the canonical day-to-day task list for the occupation.

…and upserts a `career_outcome` node per SOC, populating:

| Field | Source |
| --- | --- |
| `title` | OCC_TITLE from BLS |
| `field_soc_code` | SOC (dedup key) |
| `field_pay_low` / `field_pay_median` / `field_pay_high` | BLS A_PCT25 / A_MEDIAN / A_PCT75 |
| `field_pay_source` | Citation showing which BLS row was used |
| `field_tasks` | O*NET Task Statements |
| `og_audience` | Programs that listed this SOC (union — never removes) |

Editorial fields (`field_description`, `field_pay_range`, `field_career_track`)
are never touched by the importer.

## Source files

The two source files live at canonical paths under `private://`:

- `private://programhub_careers/bls_master.xlsx` — the BLS OEWS combined master
  (`all_data_M_<YYYY>.xlsx`). Download `oesm<YY>all.zip` from
  <https://www.bls.gov/oes/tables.htm>, unzip, and use the inner XLSX.
- `private://programhub_careers/onet_tasks.tsv` — `Task Statements.txt` from
  the O*NET database TSV bundle at
  <https://www.onetcenter.org/database.html>.

Replace once a year — BLS publishes in May, O*NET when they roll a new
database. The importer's nodes are idempotent, so re-running with new files
just refreshes the verbatim fields.

## Running the import

There are two paths and they share the same code (`CareersBatchBuilder` →
Drupal Batch API). Pick the one that fits the environment.

### Drush (recommended for production)

```bash
drush programhub:careers:refresh              # live
drush programhub:careers:refresh --dry-run    # preview, no writes
```

The Drush path runs the batch on the CLI with no HTTP layer in front, so it
sidesteps every proxy/varnish/nginx timeout. The BLS parse op alone takes
~100s on the 414k-row master — the web path needs the timeouts below
configured carefully; Drush just runs to completion.

In production this typically looks like:

```bash
ssh user@host 'docker exec programhub-php-1 drush programhub:careers:refresh'
```

### Admin form

`/admin/config/programhub/careers` — edit settings, upload either source file
(form replaces the canonical-path file), and run "Refresh now" or "Dry run".
Same Batch API, same code, just driven through HTTP.

## Configuration

Module settings (`programhub_careers.settings`):

- `bls_year` — two-digit suffix matching the uploaded file. Used in the
  citation written to `field_pay_source`. `24` = May 2024 release.
- `bls_state_code` — FIPS code for the state row filter. Idaho = `16`.
- `bls_msa_code` — FIPS code for the MSA row filter. Coeur d'Alene = `17660`.

Edit via the admin form or directly:

```bash
drush config:set programhub_careers.settings bls_year 24
```

## Production caveats

The web-form path involves these layers, in order: traefik → varnish → nginx
→ php-fpm → Batch API op. Each one has its own caps that need to clear the
~80 MB file and the ~100s parse op:

| Layer | Caps that matter | Where to set |
| --- | --- | --- |
| traefik | (defaults are generous) | n/a |
| varnish | `VARNISH_BACKEND_FIRST_BYTE_TIMEOUT` (default 60s — too short) | `docker-compose.production.yml` |
| nginx | `NGINX_CLIENT_MAX_BODY_SIZE` (default 1 MB) | `docker-compose.production.yml` |
| php | `PHP_POST_MAX_SIZE`, `PHP_UPLOAD_MAX_FILESIZE`, `PHP_MAX_EXECUTION_TIME` | `docker-compose.production.yml` |

Current production values live in `docker-compose.production.yml` — bumped to
128 MB / 600s respectively. Bump again if a future BLS release blows past
them.

If the form upload itself is too painful, SCP the file into place and use
Drush:

```bash
scp all_data_M_2024.xlsx user@host:/path/to/programhub/files/private/programhub_careers/bls_master.xlsx
ssh user@host 'docker exec programhub-php-1 drush programhub:careers:refresh'
```

## Architecture

```
RefreshForm  ────┐
                 ├──▶ CareersBatchBuilder ─▶ batch_set
DrushCommand ────┘                              │
                                                ▼
                                  ┌─────────────────────────────┐
                                  │ CareersBatchOps (static):   │
                                  │   1. parseBls   (~100s)     │
                                  │   2. parseOnet  (~5s)       │
                                  │   3. collectSocs            │
                                  │   4. upsert (chunks of 25)  │
                                  └─────────────────────────────┘
                                                │
                                                ▼
                                  ┌─────────────────────────────┐
                                  │ BlsLoader  (OpenSpout)      │
                                  │ OnetLoader (fgetcsv)        │
                                  │ CareersImporter.upsertOne   │
                                  └─────────────────────────────┘
```

The BLS pass is single-shot per batch run because OpenSpout's reader can't
persist across PHP processes — chunking by row range would force an O(n²)
skip-from-start cost on every chunk. The 100s wall time is the real
constraint, which is why proxy/varnish timeouts matter.

The upsert phase IS resumable (chunks of 25 SOCs per HTTP request) so it
fits comfortably under any sensible proxy timeout.

Key files:

- `src/Service/BlsLoader.php` — streaming XLSX read via `openspout/openspout`
- `src/Service/OnetLoader.php` — streaming TSV read
- `src/Service/CareersImporter.php` — `collectSocs()`, `upsertOne()`
- `src/Service/CareersBatchBuilder.php` — builds the Batch definition
- `src/Batch/CareersBatchOps.php` — static op callbacks
- `src/Form/RefreshForm.php` — admin form
- `src/Drush/Commands/ProgramhubCareersCommands.php` — Drush wrapper
