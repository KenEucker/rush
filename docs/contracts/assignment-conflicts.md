# RUSH-013 assignment conflict proof contract

Requirements: R-07, R-08 (foundation); Technical §§7–8.

## Online official interval

PUT /api/v1/organizations/{organization}/official-assignments/{assignment-uuid}

Same-origin Sanctum session and CSRF are required. Only current active Management
in that organization can write; the target must be an active Ranger in it.

Request: membership_id (UUID), starts_at and ends_at (UTC YYYY-MM-DDTHH:mm:ssZ),
expected_revision (null for creation, positive integer for update), reason
(nonempty string, at most 500 characters). End must be after start.

Success returns id, membership_id, starts_at, ends_at, revision. A stale revision
returns the established 409 revision_conflict envelope; invalid input returns
422; unauthenticated/unauthorized requests return 401/403. Every successful write
has an actor/reason/before/after audit entry. This endpoint is never queued offline.

## Unavailability projection

The existing unavailability resource adds assignment_conflicts: an array of
{id, revision, starts_at, ends_at} for overlapping official assignments belonging
to that report's Ranger and organization. An empty array means no current overlap.
This projection is owner-only under the existing pull authorization. It does not
publish a team calendar or expose another Ranger's private report.

An overlapping report is accepted, not rejected. The official interval and its
revision are untouched. Assignment changes emit a new sequenced unavailability
projection even if the report revision is unchanged. Ordered pull may replace an
equal revision; receipt replay must not overwrite the pulled projection.

## Workspace binding

Ranger workspace requests send X-RUSH-Account (numeric user ID) and
X-RUSH-Membership (Ranger membership UUID). An account mismatch or lost Ranger
membership returns 403 before data is exposed. Membership validation occurs under
the existing sync transaction lock. These headers restrict a cookie-authorized
request and never grant permission.

403 leaves a rejected local operation and locks the workspace. 401/419 retain
retryable failed intent until the same account is verified. Validation rejection
details and stale-revision explanations remain durable and visible.
