# Access & permissions

This document is the **spec** that the permission YAML in `config/sync/`
implements. If you add a content type, a role, or change who can do what,
this file must change in the same PR — that's how we keep the matrix and
the YAML from drifting apart.

Read **alongside** `CONVENTIONS.md` (which covers how config reaches prod).
Permissions ride the same deploy: every change is CMI YAML or an update
hook, never a one-off drush command.

---

## How permissions resolve

Two layers, applied in order:

1. **Global core role** — what kind of user this is (`student`, `graduate`,
   `instructor`, `tac_member`, `program_manager`, `division_admin`,
   `site_admin`). Carries only the bare "access the dashboard" + "edit own
   profile" permissions. Does **not** carry operational content
   permissions.

2. **OG role per group** — what the user can do *within a specific
   program or division*. This is where create/edit/publish/moderate
   permissions live. A user can hold different OG roles in different
   programs (e.g. instructor in GDES, graduate in CITE).

`og.settings.yml` has `node_access_strict: true`, so OG governs visibility
on group-affiliated content. A user with no OG role in a program sees only
the program's published, non-restricted content.

### Per-group overrides

Most programs use the bundle-level OG role defaults below. Specific
programs can override permissions for an individual group via the
`og_permissions_override` custom module — entries are CMI-tracked in
`config/sync/og_permissions_override.*.yml`. **Any override must add a
row in §5 of this document for that program**, so reviewers can see who
can do what in CITE vs. GDES at a glance.

---

## 1. Role catalog

### Global roles

| Role              | Description                                              | Scope                |
| ----------------- | -------------------------------------------------------- | -------------------- |
| `anonymous`       | Not logged in. Public site browsing only.                | Global               |
| `authenticated`   | Any logged-in user. Dashboard shell + own profile.       | Global               |
| `student`         | Active student. Operational perms come from OG.          | Global tag           |
| `graduate`        | Alumnus. Operational perms come from OG.                 | Global tag           |
| `instructor`      | Faculty. Operational perms come from OG.                 | Global tag           |
| `tac_member`      | Technical Advisory Committee. Operational perms via OG.  | Global tag           |
| `program_manager` | Program chair/coordinator. Operational perms via OG.     | Global tag           |
| `division_admin`  | Cross-program operations within a division.              | Global, OG-scoped    |
| `site_admin`      | Full Drupal admin. Bypasses OG.                          | Global               |

> A user can hold more than one global role (e.g. an instructor who is
> also a TAC member). Global roles tell the dashboard which **modules** to
> show; they don't control content access on their own.

### OG roles (per group bundle)

OG group bundles are `node:division` and `node:program`. Each has its own
set of roles. Permissions on these roles are where the real work happens.

| Bundle         | OG role                       | Description                                |
| -------------- | ----------------------------- | ------------------------------------------ |
| `node:program` | `node-program-non-member`     | Logged-in user not in this program         |
| `node:program` | `node-program-member`         | Generic membership; base read perms        |
| `node:program` | `node-program-student`        | Active student in this program             |
| `node:program` | `node-program-graduate`       | Alumnus of this program                    |
| `node:program` | `node-program-instructor`     | Teaches in this program                    |
| `node:program` | `node-program-tac_member`     | TAC member attached to this program        |
| `node:program` | `node-program-manager`        | Program chair / lead                       |
| `node:program` | `node-program-administrator`  | Highest authority for this program         |
| `node:division`| `node-division-non-member`    | Logged-in user not in this division        |
| `node:division`| `node-division-member`        | Generic division membership                |
| `node:division`| `node-division-administrator` | Division-level admin (cross-program ops)   |

> The non-member / member / admin roles in `og.og_role.*.yml` are
> required by OG and already configured (empty). New roles
> (`*-student`, `*-graduate`, `*-instructor`, `*-tac_member`, `*-manager`)
> are introduced in Phase 1.

---

## 2. Content classification

The classification determines whether a content type is moderated and who
can touch it.

| Class             | Behaviour                                                   |
| ----------------- | ----------------------------------------------------------- |
| **Admin-managed** | Admin-edited only. No moderation workflow.                  |
| **Community**     | User-generated. Goes through `community_content` workflow (Draft → Pending → Published). |

| Content type        | Class                            | Notes                                              |
| ------------------- | -------------------------------- | -------------------------------------------------- |
| `division`          | Admin-managed                    | OG group bundle.                                   |
| `program`           | Admin-managed                    | OG group bundle.                                   |
| `certificate`       | Admin-managed                    | Catalog data, imported.                            |
| `course`            | Admin-managed                    | Catalog data, imported.                            |
| `venue`             | Admin-managed                    | CULA training venues (restaurants, food trucks, cafés). |
| `career_outcome`    | Admin-managed                    | **BLS-imported via `phcr`.** BLS-tracked fields (pay numbers, SOC code, title, tasks) are overwritten on every refresh; editorial fields (description, pay_range string, career_track) survive. Edits by anyone other than admin / program manager risk loss on next refresh. |
| `project`           | Community                        | Student/graduate work submissions.                 |
| `award`             | Community                        | Award nominations / records.                       |
| `student_spotlight` | Community                        | Suggested → moderated → published.                 |
| `event`             | Community                        | Program events.                                    |
| `article`           | Community                        | News / blog content.                               |
| `newsletter_issue`  | Community                        | Newsletter editions.                               |
| `portfolio_show`    | Community                        | Cohort show + submissions.                         |
| `menu`              | Community **(program-scoped)**   | CULA culinary menus published for a venue.         |
| `game`              | Community **(program-scoped)**   | GDES game-artifact (dormant; revived only if a portfolio show creates a game). |
| `high_score`        | Community **(program-scoped)**   | Leaderboard entries on a game; pairs with `game`.  |
| `outcome`           | Community **(program-scoped)**   | Game session result (despite the name — fields are `field_game`, `field_score`, `field_win`). Pairs with `game`. |

### Program-scoped content types

The four content types tagged **(program-scoped)** above are used by only
one program at a time (`menu` → CULA, `game`/`high_score`/`outcome` →
GDES). Bundle-level OG roles do **not** grant them by default — that would
silently give every program editing rights on content types they don't
use. Permissions for these types live entirely in **§5 per-group
overrides**, scoped to the specific program that actually uses them.

If a new program adopts one of these types (e.g. a future portfolio show
brings games back), grant the relevant perms with an override on that
group, not by widening the bundle role.

---

## 3. Moderation workflow

A single workflow `community_content` applies to every Community content
type. States and transitions:

```
draft ─submit─▶ pending_review ─approve─▶ published
                              ─reject──▶ rejected ─revise─▶ draft

published ─archive─▶ archived ─restore─▶ draft
```

| State            | Default | Who sees the content                    |
| ---------------- | ------- | --------------------------------------- |
| `draft`          | yes     | Author + reviewers in the same group    |
| `pending_review` | no      | Author + reviewers in the same group    |
| `published`      | no      | Public, scoped by OG                    |
| `rejected`       | no      | Author + reviewers                      |
| `archived`       | no      | Reviewers + admins                      |

Transition permissions (per OG role within the content's group):

| Transition         | program-student | program-graduate | program-tac_member | program-instructor | program-manager | program-administrator |
| ------------------ | :-: | :-: | :-: | :-: | :-: | :-: |
| create_new_draft   | ✓ own           | ✓ own            | ✓ own              | ✓                  | ✓               | ✓                     |
| submit             | ✓ own           | ✓ own            | ✓ own              | ✓                  | ✓               | ✓                     |
| approve            | —               | —                | —                  | ✓                  | ✓               | ✓                     |
| reject             | —               | —                | —                  | ✓                  | ✓               | ✓                     |
| revise             | ✓ own           | ✓ own            | ✓ own              | ✓                  | ✓               | ✓                     |
| archive            | —               | —                | —                  | —                  | ✓               | ✓                     |
| restore            | —               | —                | —                  | —                  | ✓               | ✓                     |

`create_new_draft` is a "save changes without changing state" operation —
it accompanies any edit-access on the content type and isn't a governance
gesture by itself. Real governance transitions are `submit`, `approve`,
`reject`, `revise`, `archive`, `restore`.

`site_admin` and `division-administrator` can perform any transition on
content within their scope.

---

## 4. Permission matrix (per-bundle defaults)

Read as: which OG role can do which action on a given content type
**within the same group**. `own` = only on entities authored by the user.

Legend: ✓ = yes, — = no, `own` = only own entries.

### Project

| Action          | student | graduate | tac_member | instructor | manager | administrator |
| --------------- | :-----: | :------: | :--------: | :--------: | :-----: | :-----------: |
| view (any)      | ✓       | ✓        | ✓          | ✓          | ✓       | ✓             |
| create          | ✓       | ✓        | —          | ✓          | ✓       | ✓             |
| edit own        | ✓       | ✓        | —          | ✓          | ✓       | ✓             |
| edit any        | —       | —        | —          | ✓          | ✓       | ✓             |
| delete own      | ✓ draft | ✓ draft  | —          | ✓          | ✓       | ✓             |
| delete any      | —       | —        | —          | —          | ✓       | ✓             |
| submit          | ✓ own   | ✓ own    | —          | ✓          | ✓       | ✓             |
| approve/publish | —       | —        | —          | ✓          | ✓       | ✓             |

### Award

| Action          | student | graduate | tac_member | instructor | manager | administrator |
| --------------- | :-----: | :------: | :--------: | :--------: | :-----: | :-----------: |
| view (any)      | ✓       | ✓        | ✓          | ✓          | ✓       | ✓             |
| create          | ✓       | ✓        | —          | ✓          | ✓       | ✓             |
| edit own        | ✓       | ✓        | —          | ✓          | ✓       | ✓             |
| edit any        | —       | —        | —          | ✓          | ✓       | ✓             |
| approve/publish | —       | —        | —          | ✓          | ✓       | ✓             |

### Career Outcome

Admin-managed. BLS-imported by the `programhub_careers` module
(`drush programhub:careers:refresh`). The importer is authoritative for
pay numbers, SOC code, title, and tasks; those fields are overwritten on
every refresh. Editorial fields (description, pay_range string,
career_track) survive.

| Action                | student | graduate | tac_member | instructor | manager | administrator |
| --------------------- | :-----: | :------: | :--------: | :--------: | :-----: | :-----------: |
| view (any)            | ✓       | ✓        | ✓          | ✓          | ✓       | ✓             |
| create                | —       | —        | —          | —          | —       | ✓ (admin only, but normally created by `phcr`) |
| edit BLS data fields  | —       | —        | —          | —          | —       | — (overwritten on refresh anyway) |
| edit editorial fields | —       | —        | —          | —          | ✓       | ✓             |
| delete                | —       | —        | —          | —          | —       | ✓ (admin only) |

> **Graduate self-reported career data** belongs in a separate construct
> (paragraph on the graduate profile, or a future `graduate_career`
> content type) — *not* on the BLS-imported node. Tracked as a future
> design decision; until then, graduates have no edit on `career_outcome`.

### Student Spotlight

| Action          | student | graduate | tac_member | instructor | manager | administrator |
| --------------- | :-----: | :------: | :--------: | :--------: | :-----: | :-----------: |
| suggest (create draft) | ✓ | ✓ | —      | ✓          | ✓       | ✓             |
| edit own        | ✓       | ✓        | —          | ✓          | ✓       | ✓             |
| edit any        | —       | —        | —          | ✓          | ✓       | ✓             |
| approve/publish | —       | —        | —          | ✓          | ✓       | ✓             |

### Event

| Action          | student | graduate | tac_member | instructor | manager | administrator |
| --------------- | :-----: | :------: | :--------: | :--------: | :-----: | :-----------: |
| view (any)      | ✓       | ✓        | ✓          | ✓          | ✓       | ✓             |
| RSVP            | ✓       | ✓        | ✓          | ✓          | ✓       | ✓             |
| create          | —       | —        | —          | ✓          | ✓       | ✓             |
| edit own        | —       | —        | —          | ✓          | ✓       | ✓             |
| approve/publish | —       | —        | —          | ✓          | ✓       | ✓             |

### Article

| Action          | student | graduate | tac_member | instructor | manager | administrator |
| --------------- | :-----: | :------: | :--------: | :--------: | :-----: | :-----------: |
| view (any)      | ✓       | ✓        | ✓          | ✓          | ✓       | ✓             |
| create          | —       | —        | ✓ (submit) | ✓          | ✓       | ✓             |
| edit own        | —       | —        | ✓ draft    | ✓          | ✓       | ✓             |
| edit any        | —       | —        | —          | ✓          | ✓       | ✓             |
| approve/publish | —       | —        | —          | ✓          | ✓       | ✓             |

### Outcome (learning outcomes)

| Action          | student | graduate | tac_member | instructor | manager | administrator |
| --------------- | :-----: | :------: | :--------: | :--------: | :-----: | :-----------: |
| view (any)      | ✓       | ✓        | ✓          | ✓          | ✓       | ✓             |
| create          | —       | ✓        | —          | ✓          | ✓       | ✓             |
| edit own        | —       | ✓        | —          | ✓          | ✓       | ✓             |
| approve/publish | —       | —        | —          | ✓          | ✓       | ✓             |

### Newsletter Issue

| Action          | student | graduate | tac_member | instructor | manager | administrator |
| --------------- | :-----: | :------: | :--------: | :--------: | :-----: | :-----------: |
| view            | ✓       | ✓        | ✓          | ✓          | ✓       | ✓             |
| create          | —       | —        | —          | —          | ✓       | ✓             |
| edit            | —       | —        | —          | —          | ✓       | ✓             |
| publish         | —       | —        | —          | —          | ✓       | ✓             |

### Portfolio Show

| Action                | student | graduate | tac_member | instructor | manager | administrator |
| --------------------- | :-----: | :------: | :--------: | :--------: | :-----: | :-----------: |
| view (any)            | ✓       | ✓        | ✓          | ✓          | ✓       | ✓             |
| submit work           | ✓ own   | ✓ own    | —          | ✓          | ✓       | ✓             |
| create show           | —       | —        | —          | ✓          | ✓       | ✓             |
| edit show             | —       | —        | —          | ✓          | ✓       | ✓             |
| approve/publish       | —       | —        | —          | ✓          | ✓       | ✓             |

### Game / High Score

| Action          | student | graduate | tac_member | instructor | manager | administrator |
| --------------- | :-----: | :------: | :--------: | :--------: | :-----: | :-----------: |
| view            | ✓       | ✓        | ✓          | ✓          | ✓       | ✓             |
| create (game)   | ✓       | ✓        | —          | ✓          | ✓       | ✓             |
| edit own (game) | ✓       | ✓        | —          | ✓          | ✓       | ✓             |
| post score      | ✓       | ✓        | —          | —          | —       | —             |

### Institutional content (Division / Program / Certificate / Course)

| Action          | student | graduate | tac_member | instructor | manager | administrator |
| --------------- | :-----: | :------: | :--------: | :--------: | :-----: | :-----------: |
| view            | ✓       | ✓        | ✓          | ✓          | ✓       | ✓             |
| create          | —       | —        | —          | —          | —       | ✓ (admin only)|
| edit            | —       | —        | —          | —          | —       | ✓ (admin only)|

> Institutional content is edited by `site_admin` or by the
> custom import modules (`programhub_certificate_import`,
> `programhub_course_import`, `programhub_careers`). Even
> `program-administrator` does not get edit on these — preserves
> governance separation.

---

## 5. Per-group overrides

Empty by default. If a specific program needs to deviate from the
defaults in §4, add an entry to
`config/sync/og_permissions_override.<og_role>.<group_uuid>.yml` and
record it here:

| Group (program/division)  | OG role  | Granted permissions | Revoked permissions | Why |
| ------------------------- | -------- | ------------------- | ------------------- | --- |
| _(none yet)_              |          |                     |                     |     |

---

## 6. Change protocol

Any PR that touches roles, content types, or moderation **must** update
this file. Reviewers should block on it.

### Adding a content type

1. Decide: Institutional / Community / System. Add a row to §2.
2. Add a per-type matrix block to §4 (even if all rows are "—" for now).
3. If Community: apply the `community_content` workflow to it in
   `workflows.workflow.community_content.yml` and add the
   `content_moderation_state` config for the new type.
4. Wire OG role permissions for the new type in
   `og.og_role.node-program-*.yml` (and `node-division-*.yml` if
   division-scoped).
5. Update the Next.js fetchers' access assumptions where relevant.

### Adding a role (global or OG)

1. Add a row to §1.
2. Update every matrix in §4 with a new column for the role.
3. Create the role YAML in `config/sync/`:
   - Global → `user.role.<id>.yml`
   - OG → `og.og_role.node-<bundle>-<id>.yml`
4. Permissions on the YAML must match §4 exactly.

### Adding a per-group override

1. Add a row to §5 with the program and the specific delta.
2. Create the override config: `config/sync/og_permissions_override.<og_role>.<group_uuid>.yml`.
3. The override module reads the file at access-resolution time and
   applies the delta on top of the bundle defaults.

### Adding a moderation state or transition

1. Update the diagram + table in §3.
2. Update the transition permissions table in §3.
3. Update `workflows.workflow.community_content.yml`.

### When ACCESS.md is out of sync with the YAML

Treat it like a failing test. Either:
- The YAML changed and ACCESS.md wasn't updated → update the doc.
- The doc says something the YAML doesn't enforce → fix the YAML or
  remove the doc claim. Never leave a false promise in this file.
