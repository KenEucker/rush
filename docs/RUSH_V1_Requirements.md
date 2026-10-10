# Ranger Scheduling & Hours — V1 Requirements

**Status:** Consolidated working specification  
**Audience:** Team leadership, Rangers, and developers  
**Target operation:** Seasonal Ranger team, normally 5–15 Rangers with 1–2 staffing positions per shift  
**Scope:** Schedule planning, fairness, schedule changes, hours confirmation, and export

## 1. Purpose and principles

Build a small-team application that produces fair, usable Ranger schedules and provides a trustworthy record of hours worked. The season changes substantially by phase: early and late phases may have limited staffing and incomplete coverage, while middle phases may require 24/7 coverage. Availability changes, special events, and management covering shortages are expected parts of operations.

The application should:

- Prioritize reliable coverage and **weekly fairness**, considering season-long history as a secondary objective.
- Let management control assignments, exceptions, and publication without unnecessary administration.
- Let Rangers indicate when they **cannot work**, separately from positive scheduling preferences.
- Make gaps, competing constraints, and scheduling compromises visible. An imperfect schedule is still publishable.
- Default timesheets to scheduled hours, with a weekly confirmation and explicit exception review.
- Stay understandable for a five-person team while supporting modest future growth.

**V1 roles:** **Ranger** and **Management**. All Rangers are interchangeable for assignment purposes; V1 has no Ranger qualifications, seniority, or role-based shift eligibility.

## 2. Season, phases, shifts, and coverage

**R-01 Season and phases.** Management creates a season containing dated operational phases. Each phase has its own coverage model, staffing requirements, shift definitions, and scheduled-hours policies. Phase configurations can differ.

**R-02 Configurable shifts.** Management defines shift start times and durations. The recent norm is three eight-hour shifts daily, but the app must not hardcode that pattern. Schedule generation assigns **whole shifts** by default; management may manually create partial assignments.

**R-03 One coverage model per phase.** Management chooses one model for each phase:

- **Dedicated areas:** Individual staffing requirements per coverage area and shift.
- **Shared areas:** Rangers on one shift jointly cover a defined set of areas, with a group-level staffing requirement.

A phase cannot mix these models. A shift communicates a Ranger's scheduled area or area group; granular movement tracking is outside V1.

**R-04 Coverage gaps.** The system compares required positions with Ranger assignments. Draft and published gaps remain visible. Published gaps are understood as management's responsibility, but are not automatically represented as confirmed coverage.

**R-05 Shortage recommendations.** If coverage is impossible, the generator preserves gaps and suggests possible changes. Only management can accept changes to coverage requirements or assignments.

**R-06 Events and meetings.** Management creates individual dated events, including a paid event such as “Monday Meeting.” There is **no recurrence feature**. An event can specify staffing targets (number or percentage), and management decides whether already-scheduled shifts count or whether additional assignments are necessary. Event hours contribute to weekly scheduling and timesheets; ordinary area coverage and event staffing must be evaluated independently.

## 3. Ranger availability and preferences

**R-07 Unavailability.** Rangers mark periods when they **cannot work**; all other time is potentially schedulable. Unavailability is distinct from preferences and must be prominently considered by the scheduler. Management receives a notice and can acknowledge it so the Ranger knows it was seen. Acknowledgment does not mean the affected schedule has been repaired.

**R-08 Emergency changes.** Management can record or edit unavailability on behalf of a Ranger, including after publication, with an audit trail. Conflicting assignments and affected gaps are flagged.

**R-09 Positive preferences only.** Rangers specify preferred shifts, desired weekly hours, and preferences for consecutive or separated days off. Absence of a preferred shift does **not** mean the other shifts are undesirable. Management sets a baseline desirable/neutral/undesirable classification of shifts; personal preferences are considered separately.

**R-10 Persistent and weekly preferences.** Each Ranger has one editable default preference profile. A complete week-specific preference profile may temporarily replace it for one week, returning to the default afterward. Weekly preferences may be changed during that week's pre-draft and released-draft stages. A draft is flagged as potentially stale if preferences change after generation; it does not silently regenerate.

**R-11 Weekly hour policies.** Management configures phase-specific scheduling targets and limits, including the planned cap and thresholds for overtime warnings. Rangers may have different desired weekly hour targets (for example 24, 32, or as many hours as permitted). Work-hour and meeting rules must be configurable and validated against applicable employment requirements before payroll use.

## 4. Schedule generation, fairness, and publication

**R-12 Assisted generation.** Management selects any planning date range and generates a proposed draft. The engine uses phase coverage requirements, events, unavailability, preferences, scheduled hour limits, and fairness history. Management can edit all proposed assignments and publish the result.

**R-13 Scheduling priorities.** Required availability and schedule conflicts are flagged; the engine attempts to meet coverage, permitted hour limits, and event staffing targets while prioritizing **fairness within each week**. Season-long balancing is secondary and must not routinely produce a clearly inequitable week. Manager-approved changes may leave unmet preferences or gaps.

**R-14 Fairness rotation.** The engine considers management-defined desirability, Ranger preferences, hours relative to individual targets, accumulated difficult shifts, weekday/time-off rotation, and preferences for consecutive or separated days off. It should help prevent a Ranger repeatedly missing the same weekly social time (such as Monday evening). There is no requirement for a single opaque “fairness score.”

**R-15 Fairness visibility.** Rangers can see their own fairness history alongside **anonymous team averages**, never other Rangers' individual preference data. Management can view identified statistics across the team. Comparisons must give context for differing targets and availability.

**R-16 Draft release.** Management chooses when to release a draft. All Rangers receive an in-app notification. During review, Rangers can update weekly preferences. The app does **not** provide formal draft objections, discussions, or a process by which Rangers can delay publication; they may speak directly to management.

**R-17 Rolling publication.** Management plans any range but publishes in weekly or multiweek blocks. Each released draft has a configured automatic publication date/time. The latest saved draft is published at that time if management has not published earlier. Published assignments are not overwritten by later draft generation.

**R-18 Publish despite conflicts.** Publication proceeds even with unfilled positions, unmet preferences, or newly reported unavailability. Conflicts are conspicuously flagged and management is alerted; a conflicting assignment must not be misrepresented as reliable coverage. Ranger notification distinguishes released drafts from published schedules.

**R-19 Manager reminders.** Management can set lightweight reminders to prepare or release a draft, without a fixed recurring planning calendar.

**R-20 Replacement suggestions.** When a Ranger can no longer work a published assignment, the app offers a short list of possible replacements based on availability, schedule conflicts, hours, preferences, and fairness. Management chooses the replacement; the app does not reassign automatically.

## 5. Published schedule changes and visibility

**R-21 Team visibility.** Rangers can view the **full published team schedule**, including shift assignments. Only management can edit official assignments directly. Drafts, individual preference details, and detailed unavailability remain appropriately limited.

**R-22 Swaps and transfers.** A Ranger may propose a two-way shift exchange or a one-way transfer to another Ranger. All Rangers involved must agree, and **every change requires management approval** before the published schedule is updated. Pending requests never change the official schedule. Conflict warnings and an assignment history are retained.

**R-23 Management changes.** Management can directly adjust published assignments, including in emergencies, with recorded changes and in-app notification to affected Rangers.

## 6. Shift activity and weekly timesheets

**R-24 Ready action.** A Ranger can mark **Ready for Shift** up to 30 minutes before a scheduled start. The app records the exact action timestamp. This expresses readiness and does **not** establish a paid work start or modify scheduled hours.

**R-25 Finished action.** A Ranger may record a shift-finished timestamp after the shift. The application may provide a limited convenience window (the proposed window was up to nine hours after start); this must not prevent a Ranger from accurately reporting worked time later. Activity timestamps remain distinct from payable hours.

**R-26 Scheduled hours as default.** Each week's timesheet is prepopulated from scheduled shifts and paid event assignments. Ready/finished timestamps do not automatically change actual hours. Rangers can report early work, late work, missing time, and other corrections regardless of the convenience-action windows.

**R-27 Weekly submission.** Rangers review and submit the entire week's timesheet once, by a **management-configurable deadline**. Statuses should be simple: Open, Submitted, Needs Review, Finalized. A submission matching scheduled hours can finalize without separate management approval; exceptions require management resolution.

**R-28 Management finalization.** Management can finalize missing timesheets on a Ranger's behalf. Records clearly distinguish Ranger-confirmed hours from management-finalized hours, including edits, reasons where appropriate, and the acting person.

**R-29 Overtime awareness.** The system warns about planned and actual hours exceeding configured limits. It is not a payroll calculation or a substitute for legal compliance. All legitimately reported worked time remains reviewable even if outside the schedule.

**R-30 Management gap coverage.** Published unfilled position-hours are recorded as expected management responsibility. Management can subsequently record actual coverage in full or partial intervals and mark a gap as uncovered. Actual management coverage is separate from Ranger paid hours; V1 does **not** require attributing it to a named manager. Historical gaps remain reportable even if eventually filled.

**R-31 Transport edge case.** A simple optional transport notation may be added to a shift record if helpful for hours/coverage context. There is no dispatch board or detailed deployment activity timeline in V1.

## 7. Reports, exports, and integration

**R-32 Views and summaries.** Rangers see personal scheduled, submitted, and finalized hours plus personal fairness statistics. Management sees team hours, timesheet statuses, staffing gaps, management gap coverage, and fairness patterns by week, phase, and season.

**R-33 Download.** Management can export timesheets for a chosen date range (week, phase, or custom range) as CSV or JSON.

**R-34 Configurable export profiles.** Management creates reusable profiles defining selected fields, field/column names, field order, and appropriate output structure. A preview is displayed before sending or downloading. The same profile can be used for files and API delivery.

**R-35 Manual API delivery.** Management can manually push exports via HTTPS POST to a configured external endpoint or webhook URL, with appropriate authentication, delivery result, and manual retry. **No automatic or scheduled export pushes** are included in V1.

**R-36 Export audit.** Preserve which hours/records and profile version were included in an export. Corrections made afterward remain identifiable. Pending exceptions are identified rather than silently presented as finalized pay data.

## 8. Notifications and Signal sharing

**R-37 In-app notifications only.** Draft release, schedule publication, assignment changes, swap/transfer actions, unavailability acknowledgment, time exceptions, and management reminders use in-app notifications. Email, SMS, and device push are outside V1.

**R-38 Manual sharing.** The app can prepare relevant scheduling or start/end LOG messages and invoke the device share interface (for example to select Signal). The user selects the existing **LOG** group, reviews the draft, and sends manually. The app does not automatically message Signal, preselect a Signal group reliably, or track message delivery. Sharing is separate from hours confirmation.

## 9. Explicitly outside V1

- Ranger qualifications, experience tiers, seniority priorities, or separate scheduling roles.
- Dispatch, GPS, live Ranger location, granular deployment and incident management.
- Automated Signal/Discord bot messaging, email/SMS/device push, or message delivery tracking.
- Automatic shift swaps, transfers, replacements, or continuously changing a published schedule.
- Recurring event definitions or recurring meeting generation.
- Automatic export delivery, payroll processing, pay rates, or disbursement.
- A formal draft dispute or comment system, shift bidding marketplace, or self-selection of open shifts.
- Full clock-in/clock-out as an automatic source of paid hours.
- A complex policy builder, unless the simple scheduling engine proves it necessary.

## 10. Acceptance scenarios

1. **Low-staff phase:** Management configures limited coverage, generates a draft with gaps, reviews suggestions, and publishes with clearly identified management responsibility.
2. **24/7 phase:** The engine fills three configurable daily shifts using a phase-wide dedicated or shared-area model and displays shortfalls.
3. **Fairness:** A Ranger who worked successive Monday evenings is considered for a free Monday evening in later weeks, subject to weekly coverage and constraints.
4. **Unavailable Ranger:** New unavailability after draft release is acknowledged, conflicts are flagged, publication occurs on time, and management can select a suggested replacement.
5. **Shift change:** Rangers agree to a swap or one-way transfer; the assignment changes only after management approval.
6. **Event meeting:** Management creates a one-off Monday Meeting, assigns paid attendance, and its hours appear in that week's timesheets; a skipped week requires no cancellation of a recurrence.
7. **Hours:** A Ranger marks ready 22 minutes early, marks finished late, and confirms scheduled hours in a weekly timesheet. No extra paid minutes appear automatically. If actual extra work is reported, management reviews it.
8. **Management coverage:** Management records four hours of coverage for a partially unfilled eight-hour position and can distinguish the remaining four hours as unconfirmed or uncovered.
9. **Export:** Management finalizes timesheets, previews an export profile, downloads a CSV, or manually sends the same mapped dataset via authenticated HTTPS POST; the app records the delivery result.

## 11. Implementation details to validate before building

These points were **not fully decided** and should be settled during technical design rather than presented as approved V1 behavior:

- Exact ordering and weights among coverage, weekly fairness objectives, rest windows, personal preferences, and event attendance; handling mathematically infeasible combinations.
- The precise definition of management baseline shift desirability, including whether it can vary by date, day of week, or phase.
- Calendar portion resolved in RUSH-015 with owner approval (October 10, 2026): explicit IANA season time zone, configurable week-start weekday at local midnight, and actual elapsed intervals split at local week/phase boundaries. Nonexistent DST wall times are rejected; repeated wall times require an explicit UTC offset. See [ADR 0015](decisions/0015-season-calendar-policy.md). Applicable overtime/meeting pay rules remain unresolved for RUSH-017/037.
- Whether weekly preference overrides replace the whole profile or can leave individual default fields unchanged (the simpler whole-profile replacement is proposed above).
- What Rangers can see of a released **team-wide draft** versus only their own draft assignments.
- How shifts are counted toward special-event percentage staffing when working in a different coverage area.
- Whether the nine-hour finished-action window means elapsed time from shift start; ensure it cannot restrict real hours reporting.
- Authentication, offline behavior, hosting, retention, privacy controls, export field schemas, and integration credentials.
- Whether free-text scheduling intent is included in V1. The desired conversational scheduling policy is documented conceptually, but a structured, inspectable objective model is sufficient initially.

**V1 success criterion:** Management can generate and publish a realistic fair schedule for 5–15 Rangers, Rangers can manage unavailability and approved changes with minimal friction, weekly hours can be finalized, and management can identify uncovered work and export approved records without running a broader operations platform.
