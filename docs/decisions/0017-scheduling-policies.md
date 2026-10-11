# ADR 0017: Phase scheduling policies

Task: RUSH-017. Requirements: R-09, R-11, R-29.
Status: owner decisions approved October 11, 2026; implementation pending review.

## Approved scope

The owner selected both weekday and dated overrides for Management's phase-specific
shift desirability in the RUSH-017 chat. A dated override takes precedence over a
weekday override, then the shift baseline. Each value is desirable, neutral or
undesirable. The applicable date is the shift's local start date in the season
zone, including overnight shifts; weekday uses ISO 1 Monday through 7 Sunday.
This classification describes Management's scheduling baseline only. It neither
creates Ranger preferences nor treats a missing positive preference as undesirable.

The owner approved configuration-only weekly targets, planned caps and separate
planned/actual warning thresholds without assumed legal defaults. Mixed-phase
aggregation and employment/meeting-pay validation remain explicit dependencies
before the calculations are implemented in RUSH-022/037 or any payroll use.

## Persistence and concurrency

Each phase has an optional versioned hour-policy aggregate, with exact whole-minute
weekly target, planned cap, planned warning threshold and actual warning threshold.
Zero is explicit; missing configuration remains null. Values are nonnegative
32-bit integers, a storage bound rather than an employment rule. Target cannot
exceed planned cap. Warning thresholds are independent of the cap and one another.
Exceeding means strictly greater than the threshold; these settings never block
legitimately reported time or change Ready/Finished, assignments or finalized hours.

Phase-owned shift baselines and optional weekday/date overrides are normalized,
with unique shift/scope keys. Every configured shift needs a baseline on policy
save. Overrides refer to current phase shifts and dates inside inclusive phase
bounds. Omitted overrides are removed by the whole-aggregate save.
New shifts have no implicit neutral baseline. Removed shifts cascade only their
current classifications; historical policy audit snapshots remain. Calendar or
coverage edits make the saved policy require review until resaved against current
season/coverage revisions. Consumers must use the complete revision tuple, not
only the policy revision. There is no silent policy inheritance between phases.

All reads/writes require current Management access in the selected organization.
Writes follow the existing membership/organization/season lock order and check
policy, season and coverage revisions. One transaction replaces the aggregate and
records actor, reason, before/after and revision. Snapshot reads share the
organization lock. Orchid and API use the same services; stale form input survives.
Administration stays online; no Dexie, sync command or Workbox data cache changes.

## Downstream dependencies

- RUSH-019 owns individual desired hours and positive preferences.
- RUSH-022/024 must resolve mixed-phase weekly caps/targets, rest and override
  rules before generation uses these settings, and snapshot the input revisions
  and resolved classification for explainability and historical fairness.
- RUSH-020/037 must establish applicable employment jurisdiction and validated
  meeting/work-time treatment; RUSH-037/041 own actual-hour evaluation and UI
  warnings. Configured thresholds are scheduling advice, not legal certification.
- No payroll calculation, pay rate, overtime multiplier, automatic schedule change
  or fairness weighting is introduced here.

RUSH-001–016 are merged prerequisites, including the Milestone 2 offline proof.
All nine release acceptance scenario owners remain unchanged.
