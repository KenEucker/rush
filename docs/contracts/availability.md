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
