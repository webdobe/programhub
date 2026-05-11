# Programhub smoke test runbook

End-to-end walkthrough of the dashboard + permissions + moderation
flow. Run this against a fresh DDEV restore (or staging) after every
significant Phase change. Each scenario is a discrete user persona —
sign in as that user, click through the steps, verify the expected
outcomes.

You'll need at minimum:
- A site admin account (`administrator` global role)
- One test user per persona: student, graduate, instructor, manager
- One GDES program node (group)

If those don't exist yet, create them as admin via
`/admin/people/create` for users and the standard "Add content" UI for
the program. Then enroll each user in the GDES program with the
appropriate OG role at
`/group/node/<program_nid>/admin/members/add`.

---

## Scenario 1: Student submits a project

**Persona:** Test student user, enrolled in GDES with the
`node-program-student` OG role.

1. Sign in. Visit `/dashboard`.
2. **Expected:** see widgets: Profile, Quick actions (with a "+ Project"
   button), My programs (showing GDES with role "Student"), Recent
   activity, Upcoming events. **Not:** Pending approvals, Program
   summary, Manage members.
3. Click **+ Project** in Quick actions.
4. Fill in title, body, save. The form should default to
   moderation_state = `draft`.
5. Return to `/dashboard`.
6. **Expected:** My drafts widget appears with the new project labelled
   "Title — Draft".
7. Click the project, edit it. Change moderation state to
   **Submit for review**. Save.
8. Return to `/dashboard`.
9. **Expected:** My drafts widget shows the project labelled "Title —
   In review".

**Side-effect to check:** at step 7, an email gets queued to every
reviewer in GDES (instructor / manager / administrator OG roles). On
DDEV check `ddev logs -s web` for mail output (DDEV sends to mailpit
by default — open `ddev launch -m` to inspect).

---

## Scenario 2: Instructor reviews and approves

**Persona:** Test instructor user, enrolled in GDES with
`node-program-instructor` OG role. Should see the project from
Scenario 1 in their queue.

1. Sign in. Visit `/dashboard`.
2. **Expected:** Profile, Quick actions, My programs, Recent activity,
   Upcoming events, **Pending approvals** (with the Scenario 1 project
   listed). **Not:** Program summary, Manage members.
3. Click the project title in Pending approvals → opens the edit form.
4. Change moderation state to **Approve**. Save.
5. **Expected:** redirected to the canonical node page; the node is now
   published.
6. Visit `/dashboard` again.
7. **Expected:** Pending approvals no longer shows that project.
   Recent activity shows the project as recently changed.

**Side-effect to check:** student author receives an "Approved" email.

---

## Scenario 3: Instructor rejects, student revises

1. Repeat Scenario 1 to submit another project.
2. As instructor, change moderation state to **Reject**. Save.
3. **Expected:** student receives a "Revision requested" email.
4. As student, visit `/dashboard`.
5. **Expected:** the project shows in My drafts labelled "Needs
   revision".
6. Edit the project. State should now be **Rejected**. Change to
   **Send back to draft**. Save.
7. **Expected:** state is now Draft. The student can edit and resubmit.

---

## Scenario 4: Manager sees the right scope

**Persona:** Test manager user, enrolled in GDES with
`node-program-manager` OG role.

1. Sign in. Visit `/dashboard`.
2. **Expected:** Profile, Quick actions (with manager-tier creates
   like newsletter), My programs, **Program summary**, **Manage
   members**, Pending approvals, Recent activity, Upcoming events.
3. Click **Manage** in the Manage members widget → opens
   `/group/node/<gdes_nid>/admin/members`.
4. **Expected:** sees current GDES members; can add/edit roles.

---

## Scenario 5: Graduate self-reports a job

**Persona:** Test graduate user with a graduate profile.

1. Sign in. Visit `/dashboard`.
2. **Expected:** Profile, Quick actions, My programs, **Your career &
   experience** widget. The career widget shows "Nothing added yet" if
   no entries.
3. Click **Edit profile** in the career widget → graduate profile
   edit form.
4. Add an experience paragraph: title = "Junior Designer",
   location = "AcmeCorp", start_date = today. Save.
5. Return to `/dashboard`.
6. **Expected:** the career widget now lists "Junior Designer —
   AcmeCorp".

**Verify:** the entry is on the user's graduate profile, not on any
BLS-imported `career_outcome` node. Inspect via `/admin/content` → the
career_outcome list should be unchanged.

---

## Scenario 6: Cross-program isolation (the load-bearing test)

This is the test that proves OG access is doing its job.

1. Add a second program node (e.g. CITE) if one doesn't exist.
2. Enroll your test instructor user in GDES as instructor — but
   NOT in CITE.
3. Sign in as another instructor user who IS in CITE — submit a
   project in the CITE program.
4. Sign back in as the GDES-only instructor. Visit `/dashboard`.
5. **Expected:** Pending approvals does NOT include the CITE
   submission. Recent activity is limited to GDES content. Program
   summary lists only GDES.

If the GDES-only instructor sees CITE content anywhere, the OG
audience filter is broken — open an issue tagged `access` and stop
shipping.

---

## Scenario 7: Per-group permission override (sanity check)

This validates that `og_permissions_override` actually does what
ACCESS.md says it does.

1. As site admin, create a `config/sync/og_permissions_override.override.test_grant.yml`:

   ```yaml
   id: test_grant
   label: 'Test grant — CITE student can create menu'
   og_role: node-program-student
   group_entity_type: node
   group_id: <CITE program nid>
   granted:
     - 'create menu content'
   revoked: []
   ```

2. `ddev drush cim` to import.
3. Sign in as a CITE student. Visit `/dashboard`.
4. **Expected:** Quick actions shows **+ Menu** (it wouldn't otherwise —
   `menu` is a program-scoped type, no bundle-default access).
5. Sign in as a GDES student. Visit `/dashboard`.
6. **Expected:** Quick actions does NOT show + Menu — the override is
   scoped to the CITE group only.
7. Remove the YAML, re-import. CITE student no longer sees + Menu.

---

## Diagnostic checklist if something's wrong

| Symptom | Likely cause | Where to look |
| --- | --- | --- |
| Dashboard loads but no widgets | User has no OG memberships | `/group/node/<nid>/admin/members` |
| Profile widget only | User has only `authenticated`; no OG roles | Add OG role to membership |
| Pending Approvals empty for a reviewer | No pending content in their programs | Check `moderation_state` field on the relevant nodes |
| Notification emails not arriving | Mail system not delivering | `ddev launch -m` (mailpit) or `dblog` for mail errors |
| Page errors on `/dashboard` | New widget class broken | `/admin/reports/dblog` for the traceback |
| User can edit content in another program | `og_audience` filter wrong on a widget or OG access misconfigured | Check the widget's entity_query for `og_audience` condition |
