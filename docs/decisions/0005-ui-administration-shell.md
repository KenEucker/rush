# 0005 UI and administration shell

Date: 2026-10-09

## Scope and prerequisites

RUSH-005 follows merged RUSH-001–004. It implements Technical §§3 and 9:
role-aware Quasar navigation, accessible responsive layout, Orchid entry, and
the QCalendar/QDate/QTime baseline. It is cross-cutting presentation support for
R-01–R-38; it does not implement their operational workflows or acceptance scenarios.

## State and boundaries

- Existing Server identity and active memberships determine the displayed Ranger
  or Management workspace. Any active Management membership exposes the same-origin
  Orchid entry, matching the existing Server entry policy. There is no new role,
  organization selector, or authorization rule. Each later domain page must use its
  organization and record policies.
- The shared layout revalidates the session when a protected page is entered.
  Guests/expired sessions return to sign-in; network failures clear displayed
  identity and show a retry action. A revoked role removes Management navigation
  on the next check. Server policies remain authoritative between checks.
- The existing Orchid home and its return-to-Client/sign-out actions already
  satisfy the administration baseline. Reuse them without enabling the disabled
  global starter tools or adding management workflows.
- Overview, Calendar preview, and Your account are usable Client destinations.
  Future Ranger and Management tools are labeled unavailable and are not links.
  Account sign-out retains RUSH-004's confirmed-online behavior.
- Date, time, overnight checkbox, and drawer state are transient Vue state.
  Calendar preview explicitly says that assignments are not loaded and values
  are not saved. No API, database migration, operational record, Dexie store,
  command queue, or Workbox data cache is introduced.
- Network availability is only a browser hint. The shell explicitly says sync
  is not yet available; it does not invent pending counts or last-sync timestamps.
  RUSH-008–014 will supply real persistent sync state and offline account handling.
- Preview dates start at the device's current calendar date. Date-only navigation
  uses UTC calendar arithmetic solely to avoid browser DST shifting a displayed
  date. It does not convert a schedule to an instant. Preview ranges use weekdays,
  AM/PM, and both dates for explicitly selected overnight ranges. Season time zones,
  week allocation, and employment rules remain RUSH-015/037 decisions.

## Accessibility conventions

- One main landmark per page; a skip link moves focus without navigation or resetting
  the preview. Page headings receive focus on route changes and titles describe pages.
- Named Quasar actions, visible focus, current-page navigation, readable contrast,
  labeled sections, and text status/error messages support keyboard and screen readers.
- The drawer collapses below 901 CSS pixels and closes after mobile navigation.
  Cards and date/time controls stack at narrow widths. No essential drag or hover action.
- QDate cannot unset the required selected date. QTime exposes keyboard spinbuttons
  and AM/PM buttons. Same-day end-before-start displays an error; explicitly selecting
  next-day makes the preview overnight. These are presentation examples, not saved
  scheduling validation rules.

## Validation and deployment

The focused Playwright suite runs against the production-built SPA with synthetic
session responses, on desktop Chromium and a 360px mobile viewport. It checks actual
components and keyboard/pointer interactions. It is not a full-stack authentication
or production-PWA offline test. Existing Pest authorization tests cover real Server
session/Orchid boundaries; Vitest covers date formatting and the existing session suite.
RUSH-007 retains full-stack CI/E2E ownership, and RUSH-011–014 retain offline proof ownership.

Install the committed Client lockfile with npm ci, then build as usual. No Server
dependency, migration, config, or cache change is required. Browser test reproduction
and screenshots are in [verification](../evidence/RUSH-005/README.md).
