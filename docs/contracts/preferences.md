# Preference profiles and catalog

RUSH-019 · R-09, R-10. See [ADR 0019](../decisions/0019-ranger-preference-profiles.md).

Use the existing authenticated, CSRF-protected
`POST /api/v1/organizations/{organization}/sync/push` and `/sync/pull`.

Command type: `preference_profile.save`; UUID `operation_id` and `record_id`;
`expected_revision: null` for creation, current positive revision for edits.

```json
{
  "membership_id": "Ranger membership UUID",
  "season_id": null,
  "week_starts_on": null,
  "preferred_shift_ids": [],
  "hours_mode": "target",
  "desired_weekly_minutes": 1920,
  "days_off": "consecutive"
}
```

For weekly profiles provide a season UUID and `YYYY-MM-DD` configured week start.
Null `preferred_shift_ids`, `hours_mode` (with null minutes), or `days_off` means
inherit that field. All wire keys are present: null expresses an unspecified
weekly field, allowing an existing override to be reset to inheritance.
An empty shift array or `none` is explicitly neutral. `maximum` means as many
hours as permitted; it requires null minutes. Numeric targets are 0–10080 minutes.
Days off: `none`, `consecutive`, `separated`. Negative shift preferences are absent.

Accepted receipts contain `preference_profile`, with payload fields plus `id`,
`organization_id` and `revision`. Conflicts/rejections use the existing durable
error envelope; Server validation messages remain visible on the saved command.
Duplicate creation of the same default/week is a revision conflict even when
the competing devices chose different record UUIDs. Ownership/scope is immutable.

Pull `record_type: preference_profile` uses the same representation. Pull
`record_type: preference_catalog` uses the season UUID as `record_id` and contains
`id`, `organization_id`, `name`, `starts_on`, `ends_on`, `timezone`, ISO weekday
`week_starts_on` (1–7), and `shifts`. Each shift exposes `id`, `name`, `phase_name`,
`start_time` (HH:mm), `duration_minutes`, `starts_on`, `ends_on`. Catalog changes
are ordered by the sync sequence/checkpoint, not device timestamps or a derived
revision. Both types support null tombstones and account/organization isolation.

`EffectivePreferences::forInstant` returns resolved fields, local week start and
default/weekly `{id, revision}` dependencies for an authorized scheduling caller.
Inheritance is dynamic. Consumers treat preferred IDs as positive choices only
when evaluating those shifts; no preference is unavailability or an hour limit.
