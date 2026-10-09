# 0004 Session authentication and policies

Date: 2026-10-09

## Status and scope

Implemented for RUSH-004, following RUSH-001–003. Addresses Technical §§8 and 12
and the authentication/privacy portion of Implementation Plan §11 item 8.
Provides the identity boundary used by R-01–R-38, without claiming implementation
of their later scheduling, hours, or offline acceptance scenarios.

## Decisions

- Use Sanctum's `web` cookie guard for the same-origin Client and Orchid. No
  personal access tokens, token table, public registration, remember-me option,
  password-reset delivery, or additional roles are introduced. Existing account
  provisioning is outside this slice.
- `GET /sanctum/csrf-cookie` initializes CSRF; `POST /login` validates credentials
  and requires at least one active organization membership. Regenerate the session
  ID on success. The Client sends the decoded XSRF cookie in `X-XSRF-TOKEN`.
  Laravel 13 also accepts its standard trusted same-origin Fetch Metadata signal.
  No mutation route is exempted from request-forgery protection.
- Login attempts are limited to five per minute per email/IP and thirty per
  minute per IP. Invalid, nonexistent, and inactive accounts return the same
  credentials error. An authenticated account must sign out before switching.
- `POST /logout` and Orchid's `POST /admin/logout` invalidate the database session
  and regenerate CSRF. Logout is idempotent and works after membership revocation.
  HTTP-only, host-scoped, SameSite=Lax cookies use Secure by default in production
  (Compose already explicitly enables Secure). Retain the existing configurable
  120-minute idle lifetime and Laravel's expired-session garbage collection.
- `GET /api/v1/session` returns only the current account's minimal identity and
  active memberships, organization labels, and own profiles. No password hashes,
  Orchid permission maps, or other accounts are serialized. An account whose last
  membership was revoked receives an empty membership list, can sign out, and
  cannot access domain records or administration.
- `OrganizationPolicy` checks current active membership for reads and active
  Management membership for management access. `MemberProfilePolicy` permits own
  profile reads/updates and Management reads within the same organization. This
  is a conservative identity boundary, not a decision about released-draft
  visibility or later operational records. Profile writes allow only display
  name and phone; they cannot change ownership, roles, or active status.
- Organization/profile identifiers must agree. Policies query current membership
  rather than trusting session roles, submitted roles, or loaded relationships.
  `UpdateMemberProfile` enforces the same policy for non-controller callers.
  The profile API is the minimal read/write authorization proof; no additional
  operational Client workflow or offline queue is added.
- Orchid shares the session and Management policy. Its starter global user/role,
  search, attachment, relation, async, and example tools are disabled at the route
  boundary. Only the read-only management entry is enabled. Each later admin
  feature must explicitly enable its routes with organization and record policies.
  Legacy Orchid permission JSON cannot grant access or bypass revoked membership.
  This supersedes ADR 0003's seeded global Orchid user/role permissions as authority.
- Auth/API/admin responses are private and no-store; Orchid Turbo caching and
  prefetch are disabled. Remove external Gravatar requests for account privacy.
  Workbox navigation fallback excludes Server authentication, API, and admin paths;
  no authenticated API data is cached by Workbox.
- Client identity is transient Pinia state only. Passwords are cleared after each
  submission. An expired session clears the displayed identity on the next session
  check; reload revalidates with the Server. Failed online sign-out remains visibly
  unconfirmed. RUSH-008/013 own durable account partitions, pending-command warnings,
  cross-tab cache reconciliation, and offline sign-out/account switching. There is
  no durable Client domain data to delete in this slice. Operational retention and
  export/integration credential decisions remain with their later owner tasks.

## API surface

| Method/path | Result / authorization |
| --- | --- |
| GET `/sanctum/csrf-cookie` | 204; CSRF/session cookies |
| POST `/login` | 200 identity; 409 already authenticated; 422 validation/credentials; 429 throttled |
| POST `/logout` | 204 JSON or redirect to `/sign-in` for HTML |
| GET `/api/v1/session` | 200 own identity; 401 guest/expired session |
| GET `/api/v1/organizations/{organization}/profiles/{profile}` | Own or same-organization Management read |
| PATCH same profile path | Owner-only display name/phone update; 422 invalid input |
| GET `/admin` and `/admin/main` | Active Management entry; guest redirect; 403 Ranger/revoked |

Unauthorized domain access returns 403, a mismatched nested profile ID returns 404,
and missing records return 404. Laravel remains authoritative regardless of Client UI.

## Validation and follow-on work

Pest covers both roles, secure cookies, session regeneration and expiry, replay
after logout, actual CSRF middleware, throttling, invalid credentials, denied
reads/writes, mass-assignment attempts, mismatched IDs, membership revocation, and
Orchid restrictions. Vitest covers CSRF initialization, cookie request options,
account switching, expiration/reauthentication, and failed sign-out.

Browser evidence is recorded in `docs/evidence/RUSH-004/README.md`. No database
migration is needed: the existing users, memberships, profiles, sessions, and
cache tables suffice. Run Composer install after checkout and clear/rebuild
Laravel config/route caches on deployment. RUSH-007 owns repeatable Playwright/CI
setup; RUSH-013 owns the durable offline session-security proof.

Reference: [Laravel Sanctum documentation](https://github.com/laravel/docs/blob/13.x/sanctum.md).
