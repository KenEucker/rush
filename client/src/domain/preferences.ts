import type { PreferenceInput, PreferenceCatalog } from '../data/api/preferences';
import { validDate } from '../data/api/preferences';

export function weekStart(date: string, weekday: number): string {
  if (!validDate(date)) throw new Error('Choose a valid calendar date.');
  const d = new Date(`${date}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() - (((d.getUTCDay() || 7) - weekday + 7) % 7));
  return d.toISOString().slice(0, 10);
}
export function addDays(date: string, count: number): string {
  const d = new Date(`${date}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + count);
  return d.toISOString().slice(0, 10);
}
export function applicableShifts(catalog: PreferenceCatalog, week: string) {
  return catalog.shifts.filter((s) => s.starts_on <= addDays(week, 6) && s.ends_on >= week);
}
export function effectivePreferences(base?: PreferenceInput, weekly?: PreferenceInput) {
  const hours = weekly?.hours_mode != null ? weekly : base;
  return {
    preferred_shift_ids: weekly?.preferred_shift_ids ?? base?.preferred_shift_ids ?? [],
    hours_mode: hours?.hours_mode ?? 'none',
    desired_weekly_minutes: hours?.desired_weekly_minutes ?? null,
    days_off: weekly?.days_off ?? base?.days_off ?? 'none',
  };
}
export function dateLabel(date: string): string {
  return new Intl.DateTimeFormat('en', {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    timeZone: 'UTC',
  }).format(new Date(`${date}T00:00:00Z`));
}
