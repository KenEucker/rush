# ADR 0019: Ranger preference profiles

RUSH-019 · R-09, R-10 · October 13, 2026

## Owner decision

The owner selected **inherit unspecified fields from the default** for weekly
profiles, replacing the whole-profile proposal in Requirements R-10 and §11.
A weekly profile covers one configured season-local week. Each of preferred
shifts, desired hours, and days-off preference can inherit independently. An
inherited field resolves against the **current** default, not a copied snapshot.
The following week uses the default unless it has its own override.

## Representation and invariants

- One default per Ranger membership, across seasons. Weekly profiles are unique
  per membership, season and local week-start date. Scope cannot change on edit.
- Null in a weekly field means inheritance. Empty preferred shifts means explicitly
  no preference. `none` for hours/days off is explicitly neutral. Defaults must
  specify all fields; no profile means neutral inputs.
- Hours are `none`, a nonnegative numeric target stored in whole minutes, or
  `maximum` (as many as the configured policy permits). Hours mode and minutes
  form one inherited field. The 168-hour validation ceiling is representational,
  not an employment policy or approval to schedule those hours.
- Shift UUIDs reference current phase definitions. Default choices may span
  seasons; weekly explicit choices must apply to that season/week. Unselected
  shifts have no negative meaning. Management desirability remains independent.
- Season time zone and configured week start define the week. Boundary weeks
  may overlap the season partially. Existing SeasonCalendar handles UTC instants,
  DST and year boundaries; no elapsed-168-hour week arithmetic is used.
- Active Rangers edit/read only their own profiles through sync and the shared
  policy-protected save service. The internal effective-input resolver checks
  organization consistency and expects its scheduling caller to authorize access.
  It returns both default and weekly revisions for future input snapshots.
- Membership and organization row locks serialize duplicate scope creation and
  configuration validation. Database scope uniqueness, revisions, actor/before/
  after audit and sync receipt are transactional. A stale edit never overwrites
  current Server preferences.

## Offline and recovery

Dexie v4 adds confirmed preferences and a minimal season/shift catalog; migration
preserves all v1–v3 data and checkpoints. Catalog snapshots expose only names,
dates, time zone, week start and shift definitions, never peers' preferences or
staffing/fairness data. Ordered pull carries both kinds of record, updates and
tombstones. The existing queue, coordinator, Web Lock, retries, access-revocation
and account cleanup apply unchanged.

An atomic command/local projection write establishes local success. Accepted
intent stays visible until ordered pull confirms it. Rejected/conflicting intent
stays alongside current Server data, with explicit review/resubmit and confirmed
discard. Retry uses the same operation ID; a reviewed resubmission uses a new ID
and the current record revision. Reviewed replacement and removal of the old
terminal command occur in one transaction; a storage failure retains the old
intent. Preference views read one transactional snapshot of confirmed data,
commands and local projections. The PWA caches assets only.

## Later task boundaries

No generator or draft/publication lifecycle exists yet. RUSH-022/027 must snapshot
**both** effective input revisions, flag drafts stale when either dependency
changes, and enforce weekly editing at pre-draft/released-draft stages under the
same save transaction. This task does not fabricate drafts or infer publication
from individual official assignments. RUSH-024 owns preference weighting and
fairness; RUSH-017 owns configured hour limits. No official assignment, payable
hours, notification workflow or employment policy changes here.

## Deployment

Apply `2026_10_13_190000_create_preference_profiles` before serving the new Server
and Client. Existing profiles/availability remain unchanged. Close old PWA tabs
to activate the new schema-aware bundle. Rollback removes preference/audit/sync
tables and preference stream rows; back up first and do not downgrade a Client
with pending preference intent. Older Clients reject unknown sync record types
and preserve pending work until updated.
