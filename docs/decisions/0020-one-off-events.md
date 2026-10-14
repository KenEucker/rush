# 0020 One-off events and paid attendance

Date: 2026-10-13  
Task: RUSH-020 · Requirement: R-06  
Status: Accepted; cross-area counting approved by the owner in this task.

Management chooses for each event whether already-scheduled Rangers count.
The owner resolved Implementation Plan §11.6: **count scheduled Rangers across
all areas**. There is no event-area filter. Ordinary area coverage and event
staffing remain independent; event evaluation never changes staffing rows,
official assignments, or coverage history.

Events belong to one season and have one UTC interval, a name, a paid/unpaid
flag, an optional integer number/percentage target, and explicit full-event
attendance assignments. No recurrence model, generation, or cancellation series
exists. Percentage targets use the current active Ranger roster in that
organization, rounded upward to a whole person. This denominator is displayed;
unavailability does not shrink it. A zero target is valid. Generation must
snapshot roster and event revisions in RUSH-022/023.

Evaluation splits the event at overlapping shift/unavailability boundaries.
Each active Ranger counts at most once in each interval, whether explicitly
assigned or covered by one or more scheduled shifts. Unavailability removes
that Ranger from reliable event staffing for its intersecting interval. Merely
touching boundaries do not overlap. Partial shifts therefore cannot falsely
satisfy the entire event. The form shows minimum staffing, maximum shortfall,
interval details, and unavailable/inactive attendance warnings. Shortfalls and
unavailability do not prevent Management from saving its explicit intent.
If an assigned Ranger becomes inactive, the form retains the selected entry
with a warning; Management must explicitly remove it before saving, preventing
an unrelated edit from silently removing attendance.

Explicit attendance is persisted separately from ordinary shifts. Its UUID
survives removal and reassignment; removed entries remain marked unassigned.
Paid event source intervals are allocated across local weeks/phases with the
existing elapsed-time calendar service. Counting a scheduled Ranger alone
never creates another attendance record or paid source. These allocations are
source inputs, not summed payable totals: RUSH-022/023 consume scheduling inputs
and RUSH-037 owns ledger construction, overlap handling, and employment-rule
validation. This slice does not invent meeting-pay rules or finalize hours.

The event aggregate revision includes attendance changes. Saves require the
expected event and season revisions, current Management authorization, active
same-organization Ranger attendees, a reason, and one atomic audit. Organization
locking serializes different managers and existing season/assignment saves;
stale requests fail instead of overwriting. Audits retain before/after intervals,
attendance identities, configuration, and actor. Foreign keys prevent cross-tenant
season/attendance linkage and deletion of referenced history.

The online Orchid form and API share the same services. Local event times use
the season zone; DST gaps are rejected and folds require explicit offsets.
Events must lie within the season. Later season edits preserve saved instants
and mark the event for review. No new durable Client store or offline queue is
introduced. Published Ranger event visibility belongs to RUSH-029; this task's
configuration and evaluation remain Management-only.

## Management year browsing (owner-approved follow-up)

The owner requested a year selector and read-only past years, and explicitly
chose to group a cross-year season under its **starting year**, locking the
entire season when that year becomes past. The boundary is local January 1 in
the season's IANA time zone. Seasons, phases, coverage, policies and events
remain viewable; their shared Server services reject edits, new events within
archived seasons, and creation/backdating of seasons into past years.

Year switching lives in a collapsed sidebar item labeled with the selected year,
so occasional switching does not occupy the working area of every screen. Opening
it reveals year links; choosing one returns to the Management overview. Archived
screens retain a visible read-only notice. Menus and season lists contain only
seasons starting in the selected year. Existing record URLs derive their
year from the record; list/create links carry the year in the URL. No shared
session preference is used, so separate tabs remain independent. Available
season/notice years, the current year and next year are selectable. Current/future
configuration keeps its existing validation and revision checks.

Unavailability notices are grouped by their starting instant's calendar year
in the organization's zone. Management cannot edit or acknowledge past-year
notices. Ranger availability sync behavior is unchanged. This is a Management
configuration archive, not a new hours-finalization or publication policy.
No database migration, Client persistent store or sync protocol is required.
