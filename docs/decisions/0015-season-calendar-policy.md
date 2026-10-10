# ADR 0015: Seasons, phases and calendar policy

Task: RUSH-015. Requirements: R-01, R-11. Status: implemented, pending review.

## Product decision

On October 10, 2026, the project owner explicitly approved this policy in the
RUSH-015 implementation chat: each season has an explicit IANA time zone and
configurable week-start weekday at midnight; overnight intervals split at local
week and phase boundaries using actual elapsed time. Nonexistent DST wall times
are rejected, and repeated times require an explicit UTC offset. Employment and
pay rules remain with RUSH-017/037. This resolves the calendar portion of
Requirements §11 and Implementation Plan §11.3.

In the same chat on October 10, 2026, the owner explicitly chose a strict
next-day minimum for season and phase end dates, rather than a picker suggestion
alone. Inclusive end dates therefore cannot equal their start dates. SaveSeason
enforces this for both Orchid and API creates/updates. Existing one-day records
are not rewritten; they remain readable but must be corrected before the next
aggregate save. No schema or historical audit migration is needed; existing
database checks still guard reversed ranges and the shared service applies the
stricter product rule.

## Model and invariants

- A UUID season belongs to one organization, with a name, inclusive start/end
  calendar dates, IANA zone, ISO weekday (1 Monday through 7 Sunday), and revision.
  There is no implicit device, server or organization-zone fallback.
- UUID phases belong to the season; names and inclusive ranges are editable.
  Ends must follow starts by at least one calendar day, within the season, and
  ranges must be nonoverlapping. Adjacent phases
  therefore start the day after the previous inclusive end. A season may initially
  have no phases; gaps explicitly mean unconfigured dates, never inherited coverage.
  Season overlap across separately selected seasons is not prohibited.
- One aggregate revision covers the season and all its phases. A single save can
  move adjacent phase boundaries together. Existing phase identities must remain
  present. Deletion and scheduling configuration beyond these dates are outside
  this slice. Up to 100 phases and dates from 1900 through 9998 bound configuration.
- Management's current membership and the existing organization policy authorize
  every read/write. Transactions lock membership, organization and season, check
  the expected revision, persist all changes, and append actor/reason/before/after
  audit. The organization lock serializes different managers and new seasons.
  A stale or replayed save returns 409 without further effects.
- Changing dates, time zone or week start creates a new audited configuration
  revision. It does not rewrite existing assignment UTC instants, unavailability,
  activity or hours. Later planning/publication/timesheet tasks must bind their
  calculations to the relevant configuration revision and preserve historical
  results; they must not recalculate finalized records from the latest settings.

## Calendar arithmetic

SeasonCalendar resolves a local date/time to an authoritative UTC instant by
round-tripping the zone's possible offsets. It rejects invalid dates, DST gaps,
unspecified folds and offsets inconsistent with the zone. No one-hour transition
assumption is used. Input/output precision is whole seconds.

Weeks are full local calendar weeks, including partial weeks at a season's edge;
they are not fixed 168-hour durations or clipped to a phase. Allocation partitions
a half-open UTC interval at week, phase and season boundaries. Exact boundary
instants belong to the following interval. Every elapsed second occurs once;
unconfigured/outside-season portions carry a null phase. Original assignments
remain whole records. This is calendar allocation, not payroll or a rule for
combining phase-specific caps in a mixed-phase week (RUSH-017/037).

For rare midnight transitions, an automatic date boundary is the first instant
of that local date: earliest midnight during a fold, transition instant during a
gap. An entirely skipped date has zero duration. This deterministic boundary
convention is distinct from manually entering an ambiguous/nonexistent time.
Tests cover Los Angeles spring/autumn, Lord Howe half-hour changes, Havana's
midnight fold, Apia's skipped date, overnight phase/week changes and year edges.

## Interfaces and offline scope

Orchid exposes organization-scoped season administration from its Management
home. Named actions, date fields, time-zone/week selectors and phase rows invoke
the same SaveSeason service as the API. Validation keeps form input; a stale edit
keeps its original revision and instructs the manager to compare in another tab.
The administrator deliberately reapplies changes against the current revision.

Native end-date inputs use a minimum of start + one calendar day. Focusing an
empty end field suggests that minimum when it fits the season's end; phase end
pickers are capped at the entered season end. Start changes update the limits
without overwriting an existing end. New phase starts are only suggested when
there is room for the following day's minimum end. Calendar-day arithmetic is
independent of browser time zone and daylight-saving transitions.

Orchid administration requires the Server under Technical §7.4. No Client
operational store, Dexie migration, Workbox data cache, offline command or new
mutation queue is introduced. Ranger cached scheduling views and configuration
integration remain in their owner tasks. Quasar's date/time convention continues
to apply to the Client; this interface uses Orchid's native form controls.

## Traceability

RUSH-001–014 are merged prerequisites on production. RUSH-014's recorded
production-PWA regression proves the required offline/real-manager-conflict gate.
RUSH-015 implements the season/calendar foundation of R-01 and R-11, supporting
scenarios 1–2. Coverage models (016), hour policies (017), worked-hour ledgers
(037), and the integrated configuration UI test (021) retain their ownership.
No complete nine-scenario release acceptance is claimed.

See [contract](../contracts/seasons.md) and [test evidence](../evidence/RUSH-015/README.md).
