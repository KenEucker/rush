# Sync protocol v1 (RUSH-009)

All endpoints are under `/api/v1/organizations/{organization}/sync`. They require
an active membership, same-origin Sanctum session and CSRF header. Responses use
`Cache-Control: no-store, private`; push and pull have a 120/minute authenticated
request throttle. Organization and actor are derived from the route/session,
never writable command fields. No Workbox domain-response cache is permitted.

## Push

`POST /push` accepts one command:

```json
{
  "operation_id": "01920000-0000-7000-8000-000000000009",
  "type": "member_profile.update",
  "record_id": "01920000-0000-7000-8000-000000000001",
  "expected_revision": 1,
  "payload": { "display_name": "Casey Updated", "phone": "555-0100" }
}
```

Both IDs are UUIDs; expected revision is an integer 1–2147483646. The sole command
type is `member_profile.update`. Payload keys are limited to `display_name` and
`phone`; domain rules match [member-profile.md](member-profile.md). Omitted phone
preserves it, explicit null clears it. Unknown command types are not executable.
The Client allowlists outgoing fields; the Server ignores unknown top-level
fields and rejects unknown payload fields. Actor/roles/timestamps cannot be set.

HTTP 200 means the operation was processed, not necessarily accepted:

| `status` | Additional fields | Meaning |
| --- | --- | --- |
| `accepted` | `profile` | Original accepted allowlisted profile and resulting revision |
| `conflict` | `error: {code,message,errors}` | `revision_conflict`; review current state and preserve local intent |
| `rejected` | `error: {code,message,errors}` | `validation_failed`; correct input and submit a new operation |

All results include `operation_id`; `errors` is an object of field/message arrays.
See [sync.example.json](sync.example.json), asserted by Pest and Vitest and decoded
against the real API in Playwright. A repeated ID with identical normalized input
returns its original result without rerunning business effects. Changed input
returns HTTP 409 `operation_id_reused`. Reordering payload keys is identical;
omission and explicit null are distinct. The original receipt is never replaced.

Malformed envelopes return HTTP 422 before storing a receipt. Unauthorized or
inactive users get 401/403; a missing/scoped-out target gets 404. Current policy is
checked even for replay. These errors and CSRF expiry (419), throttling (429),
and unexpected failures (5xx) use the existing API error envelope. No failed
infrastructure attempt establishes acceptance. Retry temporary failures with the
same operation ID; preserve work across authentication recovery. Never mint a new
ID merely because an accepted response may have been lost.

## Pull

`POST /pull` accepts `{ "checkpoint": null, "limit": 100 }`. Both fields are
optional. Checkpoint is null for a fresh synchronization or an opaque returned
string (maximum 4096 characters); limit is 1–100, default 100.

```json
{
  "changes": [
    {
      "sequence": 1,
      "record_type": "member_profile",
      "record_id": "01920000-0000-7000-8000-000000000001",
      "value": null
    }
  ],
  "checkpoint": "opaque-server-token",
  "has_more": false
}
```

`value` is a full `MemberProfile` or null for removal/revoked visibility. Sequence
is a positive Server integer, strictly increasing within a generation; it differs
from the profile's domain revision. Apply entries in sequence order, including
tombstones. A page has at most the requested limit. Persist the page and its
checkpoint atomically before requesting the next page. `has_more` concerns this
bounded pass; another pull after false can discover new changes. An empty completed
page is valid. Do not interpret/decrypt/check timestamp ordering on Client tokens.

Repeating a checkpoint safely replays the same bounded interval. Current
authorization can redact formerly visible values to null. A completed checkpoint
can discover a later interval. Requests without a checkpoint replay the generation
from the beginning and are intended for empty/reinitialized confirmed cache.

HTTP 409 `checkpoint_invalid` requires resetting confirmed data and checkpoint,
then restarting with null. Preserve pending commands and local representations.
Do not display old confirmed data as current after a visibility reset. HTTP 403
means access is unavailable and requires account-security handling, not a blind
retry loop. The coordinator/account lifecycle integration is RUSH-010/013.
