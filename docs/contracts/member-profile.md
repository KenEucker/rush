# Member profile contract — RUSH-006

The proof is the existing authenticated online identity endpoint:
`/api/v1/organizations/{organization}/profiles/{profile}`. Use Sanctum same-origin
cookies and JSON `Accept`; PATCH also sends JSON content and the XSRF token.
Responses are private/no-store. UUID route identifiers must refer to the same organization.

GET permits the profile owner or active Management in that organization. PATCH remains
owner-only, including for Management. Revoked memberships have no access. Organization
denial is 403; a profile under the wrong authorized organization or missing ID is 404.

## GET / PATCH response (200)

| Field | Type | Meaning |
| --- | --- | --- |
| id | UUID string | Profile ID |
| organization_id | UUID string | Authorized organization ID |
| display_name | string | Profile display name |
| phone | string or null | Contact value; not omitted |
| revision | positive integer | Server revision, starting at 1 |
| updated_at | ISO 8601 UTC string | Server update instant, ending in Z |

There is no `data` wrapper. The session API's nested profile is an identity summary
with only id/display_name/phone; it is not this editable resource. The
[shared example](member-profile.example.json) is tested by Server and Client.

## PATCH command

| Field | Type | Rules |
| --- | --- | --- |
| expected_revision | integer | Required, 1–2147483646, equal to the current revision |
| display_name | string | Required, nonblank, at most 255 characters |
| phone | string or null | Optional, at most 50 characters; omission preserves, null clears |

Laravel HTTP middleware trims strings and converts empty strings to null. The service
also rejects blank names for direct callers. Identifiers, roles, ownership, actor IDs,
Server revisions/timestamps and any other unknown attributes are never writable.
An actual change increments revision once and appends history in the same transaction.
An unchanged current-revision submission returns 200 without changing revision/history.

## Errors

```json
{
  "code": "validation_failed",
  "message": "Check your details and try again.",
  "errors": { "display_name": ["The display name field is required."] }
}
```

The envelope applies to `/api/*` exceptions and JSON authentication failures.
Field messages can change with Laravel/localization; clients use stable codes and keys.
An error with no field messages has `errors: {}`. Exception details are never returned.

| HTTP | Code | Caller response |
| --- | --- | --- |
| 400 | bad_request | Correct malformed input |
| 401 | unauthenticated | Reauthenticate before retrying |
| 403 | forbidden | Stop; do not retry with the same revoked/unauthorized access |
| 404 | not_found | Reload the authorized context; do not infer hidden record details |
| 405 | method_not_allowed | Use a documented method |
| 409 | revision_conflict | Preserve input, GET current record, review and explicitly resubmit with its revision |
| 409 | conflict | Resolve the incompatible state; login requires sign-out before switching accounts |
| 419 | csrf_expired | Refresh CSRF/session before explicitly retrying |
| 422 | validation_failed | Correct the named fields |
| 429 | rate_limited | Respect Retry-After before retrying |
| 503 | service_unavailable | Reconnect after service recovery; outcome may need confirmation |
| other failure | server_error | Display a safe error; do not assume a mutation was rejected or accepted |

Network failures and malformed proxy responses are not domain acceptance. The Client
keeps status/code/field errors in `ApiError`, falls back to safe messages for non-contract
errors, and rejects malformed or incorrectly scoped profile success payloads. It does
not persist, queue, overwrite caller input or automatically retry mutations. A lost
successful PATCH response followed by a replay produces a stale-revision conflict;
idempotent queued commands/checkpoints belong to RUSH-009.

## Compatibility and verification

PATCH's expected revision is newly required; pre-RUSH-006 writers must GET a profile
and include it. Coordinate deployment with such callers. GET fields are additive.
Keep the JSON example, PHP resource, TypeScript contracts and ingress checks aligned.
`ProfileContractTest.php` asserts the exact response/example, validation, policy,
replay, rollback and audit behavior; `profiles.test.ts` verifies the same wire example,
typed requests, error preservation and rejection of malformed/wrong-account payloads.
