// Calendar-only arithmetic, not authoritative instants or scheduling policy.
// UTC avoids shifting a displayed calendar date across device DST boundaries.
export function shiftDate(value: string, days: number): string {
  const date = new Date(value + 'T12:00:00Z');
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}
export function dateLabel(value: string): string {
  return new Intl.DateTimeFormat('en-US', {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
    year: 'numeric',
    timeZone: 'UTC',
  }).format(new Date(value + 'T12:00:00Z'));
}
export function timeLabel(value: string): string {
  const [hour = 0, minute = 0] = value.split(':').map(Number);
  return (
    String(hour % 12 || 12) + ':' + String(minute).padStart(2, '0') + (hour < 12 ? ' AM' : ' PM')
  );
}
export function rangeLabel(day: string, start: string, end: string, nextDay: boolean): string {
  return (
    dateLabel(day) +
    ', ' +
    timeLabel(start) +
    ' – ' +
    (nextDay ? dateLabel(shiftDate(day, 1)) + ', ' : '') +
    timeLabel(end) +
    (nextDay ? ' (overnight; next day)' : '')
  );
}
