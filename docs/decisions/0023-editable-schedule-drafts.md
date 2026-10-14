# ADR 0023: Generation and editable proposals

Task: RUSH-023. Requirements: R-02, R-06, R-12, R-17.
Status: implementation pending review. Product constraint rules remain ADR 0022.

## Boundary and generation

Management generates an online, unreleased draft from an immutable, current
planning snapshot. The snapshot supplies whole configurable shifts, independent
event targets, eligibility, availability, weekly policies and published commitments.
No method in this slice writes official assignments or event attendance. Repeating
generation creates another independent draft, leaving all earlier drafts intact.

The initial feasibility engine visits shifts in start/identifier order, then
events in start/identifier order, trying active Rangers in stable UUID order.
Every addition is evaluated together with the already-selected proposals and
published commitments; it is accepted only if all candidates remain automatically
eligible. This is a deterministic baseline, **not a fairness policy or optimizer**.
RUSH-024 owns the approved scoring weights and replacement of that baseline order.
It can leave avoidable gaps; it never relaxes constraints silently. The UI states
this limitation. Generation is bounded to 1,000 proposals; a larger result requires
a smaller planning range and rolls back the attempt.

Existing published coverage with matching shift/model/target context contributes
to that opportunity. Uncontextualized commitments still constrain availability,
overlap, rest and hours, but cannot be guessed into a coverage target. Event demand
uses the existing number/percentage rule, distinct Rangers and interval boundaries.
When configured, scheduled shifts across all areas count alongside explicit
attendance. Draft event assignments remain whole events, including unpaid events.
Counting a shift for an event creates no extra paid attendance. Unavailable and
inactive Rangers do not count toward demand. Assignment conflicts remain visible;
this interval preview is not RUSH-025's official gap ledger or recommendations.

## Editing and persistence

`schedule_drafts` holds a UUID, tenant-bound planning snapshot, draft state and
revision. `draft_assignments` holds UUID proposals, tenant-constrained membership,
opportunity, UTC interval, immutable source context, constraint evaluation and
explicit override reason. `draft_changes` retains each full before/after set with
actor, revision, reason and time, including removals and reassignment. Unchanged
member/opportunity pairs retain their assignment UUID. Reassignment creates a new
proposal identity with the prior identity retained in history.

Managers may add, reassign, shorten or remove proposals. Partial shifts must be
positive intervals inside their source whole shift. Events cannot be shortened.
Two Rangers can cover separate portions of a shift. The same Ranger/opportunity
pair is unique; disjoint fragments for one Ranger are not modeled in this slice.
Each conflicting candidate needs its own override reason; the overall edit reason
does not bypass constraints. A batch that introduces conflicts on multiple
assignments must acknowledge each affected assignment (existing reasons can be
saved before adding an overlapping proposal through the single-assignment UI).
Structural errors, inactive membership and missing configuration cannot be overridden.

Writes lock the draft, check its expected revision, validate the entire proposed
set and transactionally replace assignments plus audit. PostgreSQL REPEATABLE READ
provides coherent input reads; serialization retries rerun authorization/revision
checks. A concurrent source change after the transaction snapshot becomes visible
as stale on the next read. It never changes a saved proposal automatically.

Stale snapshots remain inspectable. Writes against stale sources return 409 and
require a fresh capture/new draft, preserving the previous draft. RUSH-027 owns
released-draft lifecycle and subsequent staleness workflows. Past starting years
remain readable and cannot generate/edit drafts. All access is Management-only;
no Ranger draft visibility is decided here. Orchid retains rejected/conflicting
form input and links to saved drafts through each season's planning page.

Administration stays online. There is no Dexie schema, sync command, Workbox cache,
notification, release, publication, Ready/Finished or payable-hours change.
RUSH-028 retains publication; R-17 is covered only for preservation of official
records during generation. Prerequisites RUSH-001–022 are merged through `5878cfa`.

## Operations

The additive migration creates the three draft tables and a composite snapshot
key. Rollback discards draft data/history, preserving planning inputs and official
records. No data backfill or new dependency is required.

Root `package.json` already provided generic `artisan`, `docker:artisan` and
`docker:dev:artisan` runners, plus migration/cache aliases. This task completes
development-Compose inspection, queue, scheduler, maintenance and storage helpers.
Use `npm run docker:dev:artisan -- <command> [arguments]` for any Artisan command;
use `npm run docker:dev:help -- <command>` to inspect it. Helpers never add `--force`.
