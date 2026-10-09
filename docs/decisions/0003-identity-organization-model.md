# 0003 Identity and Organization Model

Date: 2026-10-09

## Status

Accepted for RUSH-003.

## Context

RUSH V1 has two roles, Ranger and Management. The application must keep identity data scoped to an organization while avoiding scheduling concepts that are explicitly outside V1, including qualifications, seniority, and role-based shift eligibility.

## Decision

- Keep Laravel and Orchid's existing `users` table as the account record.
- Add UUID-backed `organizations`, `organization_memberships`, and `member_profiles` tables for RUSH domain identity.
- Store the V1 role on `organization_memberships` with the only allowed values `ranger` and `management`.
- Scope a member profile to exactly one organization membership. Profiles contain scheduling-neutral contact/display data only.
- Seed one deterministic `RUSH Demo Rangers` organization with ten members: eight Rangers and two Management users.

## Consequences

Later authorization work can enforce role and record access through organization memberships without replacing Orchid's user model. Later scheduling work must add explicit domain records for availability, preferences, coverage, and assignments instead of placing eligibility, seniority, or qualification state on identity records.
