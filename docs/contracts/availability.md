# Unavailability sync contract — RUSH-012 / R-07

Use the existing authenticated, private, same-origin endpoints:

- POST /api/v1/organizations/{organization}/sync/push
- POST /api/v1/organizations/{organization}/sync/pull

Example create command:

```json
{
  "operation_id": "01920000-0000-7000-8000-000000000010",
  "type": "unavailability.save",
  "record_id": "01920000-0000-7000-8000-000000000011",
  "expected_revision": null,
  "payload": {
    "membership_id": "01920000-0000-7000-8000-000000000003",
    "starts_at": "2026-11-01T07:00:00Z",
    "ends_at": "2026-11-01T12:00:00Z"
  }
}
```

The membership must match the currently authenticated Ranger in the organization.
Dates are exact UTC strings in YYYY-MM-DDTHH:mm:ssZ format, with end after start.
An update supplies the last accepted positive expected_revision and a new
operation_id. Every retry of the same intent uses the original operation ID.

An accepted response contains operation_id, status=accepted and unavailability:
id, organization_id, membership_id, starts_at, ends_at, revision.
The returned revision starts at 1 and increments on each accepted save.
Conflicts and validation failures use the existing status/error envelope.
Unknown fields, commands and malformed revisions fail envelope validation.
Authorization is repeated before replaying receipts.

Pull changes use record_type=unavailability, record_id and the same record shape
in value (or null for a tombstone). Pagination shares the profile stream and its
opaque checkpoint. Only current authorized ranges are returned. The Client
preserves rejected/conflicting intent separately from confirmed records.

See [the existing sync protocol](sync.md) and
[ADR 0012](../decisions/0012-availability-proof.md).

## Management workflow — RUSH-018 / R-07, R-08

Management-only online endpoints under `/api/v1/organizations/{organization}`:

- `GET /unavailability`: `{data: [...]}` of organization reports.
- `PUT /unavailability/{uuid}`: membership_id, starts_at, ends_at,
  expected_revision (null for create), reason (required, at most 500 characters).
  Returns `{data: <report>}` with the same resource as sync. Creation returns
  201, update 200. Ownership cannot be moved to a different Ranger.
- `POST /unavailability/{uuid}/acknowledge`: expected_revision (positive integer).
  Returns 200 `{data: <report>}`. Repeating the same acknowledgment preserves the
  original actor/time; a stale revision returns 409 without acknowledging it.

Same-origin Sanctum/CSRF, current active Management and organization scope are
required. Save targets must be active Rangers. Unauthenticated/unauthorized calls
return 401/403, missing scoped records 404, invalid inputs 422, stale edits 409.
Orchid uses the same services and preserves submitted input on validation/conflict.

The report projection adds `acknowledged_at: null | UTC-instant`, applying only to
the current range revision. Null means awaiting acknowledgment. Legacy Client
snapshots may omit it. Pull changes this field even at the same range revision;
it is durable in the existing Dexie value. A new accepted revision resets it to
null while retaining prior acknowledgment audit rows. Pending local revisions
are never presented as acknowledged. Acknowledgment never repairs assignments.

Management notices are the accepted reports, surfaced with a pending count on
the Orchid home and revision-specific actions in its notice list. Rangers see
their own acknowledgment in Availability, including offline after sync.
See [ADR 0018](../decisions/0018-unavailability-workflow.md).
