import { ApiError } from './http';
import { uuid } from './availability';

export interface PreferenceInput {
  membership_id: string;
  season_id: string | null;
  week_starts_on: string | null;
  preferred_shift_ids: string[] | null;
  hours_mode: 'none' | 'target' | 'maximum' | null;
  desired_weekly_minutes: number | null;
  days_off: 'none' | 'consecutive' | 'separated' | null;
}
export interface PreferenceProfile extends PreferenceInput {
  id: string;
  organization_id: string;
  revision: number;
}
export interface PreferenceCatalog {
  id: string;
  organization_id: string;
  name: string;
  starts_on: string;
  ends_on: string;
  timezone: string;
  week_starts_on: number;
  shifts: {
    id: string;
    name: string;
    phase_name: string;
    start_time: string;
    duration_minutes: number;
    starts_on: string;
    ends_on: string;
  }[];
}
function invalid(): never {
  throw new ApiError(
    200,
    'Invalid preference data. Your saved intent is preserved.',
    'invalid_response',
  );
}
function object(value: unknown): Record<string, unknown> {
  if (!value || typeof value !== 'object' || Array.isArray(value)) invalid();
  return value as Record<string, unknown>;
}
export function validDate(value: unknown): value is string {
  return (
    typeof value === 'string' &&
    /^\d{4}-\d{2}-\d{2}$/.test(value) &&
    Number.isFinite(Date.parse(value)) &&
    new Date(value).toISOString().slice(0, 10) === value
  );
}
export function parsePreferenceInput(raw: unknown): PreferenceInput {
  const v = object(raw);
  if (
    typeof v.membership_id !== 'string' ||
    !uuid.test(v.membership_id) ||
    (v.season_id !== null && (typeof v.season_id !== 'string' || !uuid.test(v.season_id))) ||
    (v.week_starts_on !== null && !validDate(v.week_starts_on)) ||
    (v.season_id === null) !== (v.week_starts_on === null) ||
    (v.preferred_shift_ids !== null &&
      (!Array.isArray(v.preferred_shift_ids) ||
        v.preferred_shift_ids.length > 1000 ||
        !v.preferred_shift_ids.every((id) => typeof id === 'string' && uuid.test(id)) ||
        new Set(v.preferred_shift_ids).size !== v.preferred_shift_ids.length)) ||
    ![null, 'none', 'target', 'maximum'].includes(v.hours_mode as string | null) ||
    ![null, 'none', 'consecutive', 'separated'].includes(v.days_off as string | null) ||
    (v.desired_weekly_minutes !== null &&
      (typeof v.desired_weekly_minutes !== 'number' ||
        !Number.isInteger(v.desired_weekly_minutes) ||
        v.desired_weekly_minutes < 0 ||
        v.desired_weekly_minutes > 10080)) ||
    (v.hours_mode === 'target') !== (v.desired_weekly_minutes !== null) ||
    (v.season_id === null &&
      (v.preferred_shift_ids === null || v.hours_mode === null || v.days_off === null))
  )
    invalid();
  return {
    membership_id: v.membership_id,
    season_id: v.season_id,
    week_starts_on: v.week_starts_on,
    preferred_shift_ids:
      v.preferred_shift_ids === null ? null : [...(v.preferred_shift_ids as string[])],
    hours_mode: v.hours_mode as PreferenceInput['hours_mode'],
    desired_weekly_minutes: v.desired_weekly_minutes,
    days_off: v.days_off as PreferenceInput['days_off'],
  };
}
export function parsePreference(raw: unknown, org: string, id: string): PreferenceProfile {
  const v = object(raw);
  if (
    v.id !== id ||
    !uuid.test(id) ||
    v.organization_id !== org ||
    typeof v.revision !== 'number' ||
    !Number.isInteger(v.revision) ||
    v.revision < 1
  )
    invalid();
  return { ...parsePreferenceInput(v), id, organization_id: org, revision: v.revision };
}
export function parsePreferenceCatalog(raw: unknown, org: string, id: string): PreferenceCatalog {
  const v = object(raw);
  if (
    v.id !== id ||
    !uuid.test(id) ||
    v.organization_id !== org ||
    typeof v.name !== 'string' ||
    !validDate(v.starts_on) ||
    !validDate(v.ends_on) ||
    v.ends_on < v.starts_on ||
    typeof v.timezone !== 'string' ||
    typeof v.week_starts_on !== 'number' ||
    !Number.isInteger(v.week_starts_on) ||
    v.week_starts_on < 1 ||
    v.week_starts_on > 7 ||
    !Array.isArray(v.shifts)
  )
    invalid();
  try {
    new Intl.DateTimeFormat('en', { timeZone: v.timezone });
  } catch {
    invalid();
  }
  const shifts = v.shifts.map((rawShift) => {
    const s = object(rawShift);
    if (
      typeof s.id !== 'string' ||
      !uuid.test(s.id) ||
      typeof s.name !== 'string' ||
      typeof s.phase_name !== 'string' ||
      typeof s.start_time !== 'string' ||
      !/^([01]\d|2[0-3]):[0-5]\d$/.test(s.start_time) ||
      typeof s.duration_minutes !== 'number' ||
      !Number.isInteger(s.duration_minutes) ||
      s.duration_minutes < 1 ||
      !validDate(s.starts_on) ||
      !validDate(s.ends_on)
    )
      invalid();
    return {
      id: s.id,
      name: s.name,
      phase_name: s.phase_name,
      start_time: s.start_time,
      duration_minutes: s.duration_minutes,
      starts_on: s.starts_on,
      ends_on: s.ends_on,
    };
  });
  return {
    id,
    organization_id: org,
    name: v.name,
    starts_on: v.starts_on,
    ends_on: v.ends_on,
    timezone: v.timezone,
    week_starts_on: v.week_starts_on,
    shifts,
  };
}
