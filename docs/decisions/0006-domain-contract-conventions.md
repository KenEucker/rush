# 0006 Domain and contract conventions

Date: 2026-10-09

## Scope and prerequisites

RUSH-006 follows merged RUSH-001–005 on `production`. It implements Technical
§§5 and 14, with cross-cutting support for R-01–R-38. The proof extends the existing
RUSH-004 member-profile API. It introduces no scheduling, hours, or offline workflow
and does not complete any of the nine functional scenarios. No bounded product
decision in Implementation Plan §11 is required for this foundation task.

## Entity and persistence conventions

- Keep domain services cohesive and small. Controllers resolve route scope, delegate
  to services, and serialize explicit resources. Orchid and API mutations must call
  the same service; neither may duplicate validation, authorization, or transitions.
- Syncable domain records use UUID keys and explicit organization ownership. Legacy
  Laravel account IDs remain integers. Use foreign keys, uniqueness constraints, and
  composite organization foreign keys where needed to prohibit cross-organization links.
- Use allowlists for writable and serialized fields. Never serialize an Eloquent
  model wholesale or trust submitted actor, organization, role, revision, or timestamps.
- Server instants are UTC; the wire representation is ISO 8601 ending in `Z`.
  Calendar dates remain date-only values interpreted in the configured season zone.
  Calendar/week and employment policies remain with RUSH-015/017/037.
- Use database transactions for a state change and its history. Side effects such as
  notification jobs must run after commit when their owner tasks add them.

## Revisions and states

- A revision is a positive Server-assigned integer for one record. Existing profiles
  start at 1; each accepted change increments once. Unchanged valid submissions leave
  the revision and timestamp unchanged and do not append an audit. A stale revision
  is rejected even when its requested values match the current values.
- Mutations carry `expected_revision`. Reload persisted state, authorize, validate,
  compare revision, then mutate inside the transaction. The proof locks the profile
  row and uses a conditional revision update, so only one competing writer can accept
  a given revision. A stale write returns 409; it never overwrites the newer record.
- Revisions order changes to a record, not an organization's change stream. Device
  clocks and `updated_at` are not checkpoints. RUSH-009 owns operation IDs, idempotent
  replay results, stable pull checkpoints and tombstones. This online PATCH is not a
  queued sync command: replay after a lost accepted response returns a conflict.
- Model actual lifecycle states with domain-specific enums and explicit service
  transition methods as each slice is implemented. Document allowed source/target
  states, actor policy, invariants, revision check, history, and failure cases. Never
  accept a free-form `status` update through mass assignment. Profiles have no
  lifecycle status; there is no artificial state machine in this proof.
- Keep draft and published assignments separate, and shift activity independent of
  payable hours. Pending Client intent is not a Server state transition. Publication,
  approvals and finalization require Server authorization. Their transitions and
  product rules remain with the existing roadmap owners.

## API and Client boundaries

The maintained [profile contract](../contracts/member-profile.md) specifies fields,
errors, omission/null semantics and compatibility. Laravel's `MemberProfileResource`
owns serialization. TypeScript has separate read and command interfaces, endpoint
functions, and runtime ingress checks for the proof. A shared JSON example is asserted
by Pest and consumed by Vitest to catch contract drift without a new code generator.
Update the contract, resource, Client types/decoder, and both tests together.

The session's nested profile remains a small identity summary; it is not a versioned
editable profile. Call the profile GET before constructing a profile mutation.
The Client HTTP helper handles same-origin cookies, XSRF, no-store and typed errors.
Endpoint adapters return confirmed responses, propagate failures, and never retry a
mutation silently. Unknown/malformed responses fail rather than become valid records.

These adapters are transport, not repositories or a new operational store. No new
profile UI is enabled. The existing transient session remains in Pinia. Future durable
Client views must use Dexie repositories and the centralized coordinator after the
Milestone 2 proof. No Workbox API cache or independent queue is added.

## Validation and error conventions

Validate within the domain service, after policy checks, for API and non-HTTP callers.
Use Laravel validation rules and `ValidationException` for field errors; allow only
validated business fields into persistence. A domain conflict is a distinct exception.
API/auth JSON failures use `{ code, message, errors }`; `errors` is always an object of
field-to-message-array entries. Retain HTTP status and headers such as `Retry-After`.
Client behavior uses code/status, not English message matching. The contract lists
recovery expectations. Never return SQL details, traces, secrets, or whole records in
errors, including with debug enabled. Unexpected errors remain in Server diagnostics.
Orchid HTML error/redirect behavior remains Laravel's standard behavior.

## Audit proof

`member_profile_changes` is an append-only application history table. Every accepted
profile change inserts a UUID, organization/profile link, actual authenticated actor,
resulting revision, before/after display name and phone, and Server UTC instant in the
same transaction as the profile update. Failure to append rolls back the update.
Only these two profile fields are captured; submitted secrets and privileged fields
are ignored. Creation seeds a revision-1 baseline rather than fabricating old history.

Database constraints enforce one audit per profile/revision and a matching organization.
Restrictive foreign keys prevent profile/account deletion from silently cascading away
history. There is no audit update/delete API, Client audit exposure, or purge job.
This is application append-only history, not a tamper-proof store against database
administrators. Retention, redaction, and deletion procedures need the explicit privacy
decision owned by RUSH-048; export histories and sensitive domain actions retain their
own later owners. Future correction/finalization actions add a reason when required by
their requirements; do not build a generic event-sourcing or audit framework now.

## Deployment and evidence

Run `php artisan migrate --force` before deploying the new profile endpoint contract.
The migration adds `member_profiles.revision`, its composite unique key, and
`member_profile_changes`; existing profiles keep their data and receive revision 1.
Deploy any external profile writers together: PATCH now requires `expected_revision`.
The built-in shell has no profile write UI. GET adds organization, revision and UTC
update time. API/auth errors gain stable codes while preserving `message` and `errors`.

Rollback removes the new columns/table and therefore loses any newly recorded audit
history. Back up first, stop writes, and prefer a forward fix once history exists.
No dependency, environment, UI, Dexie schema, sync protocol or Workbox change is needed.
See [verification evidence](../evidence/RUSH-006/README.md). Human review and merge
remain necessary for the task definition of done.
