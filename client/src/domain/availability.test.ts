import { expect, it } from 'vitest';
import { localInstant, localParts, formatInterval } from './availability';

it('round trips explicit AM/PM and rejects impossible calendar dates', () => {
  const start = localInstant('2026-10-09', '11:00 PM');
  const end = localInstant('2026-10-10', '07:00 AM');
  expect(localParts(start)).toEqual({ date: '2026-10-09', time: '11:00 PM' });
  expect(formatInterval(start, end)).toContain('overnight');
  expect(formatInterval(start, end)).toContain('Fri');
  expect(() => localInstant('2026-02-30', '09:00 AM')).toThrow();
  expect(() => localInstant('2026-10-09', '25:00')).toThrow();
});

it.skipIf(Intl.DateTimeFormat().resolvedOptions().timeZone !== 'America/Los_Angeles')(
  'rejects nonexistent and repeated local times at DST boundaries',
  () => {
    expect(() => localInstant('2026-03-08', '02:30 AM')).toThrow('does not exist');
    expect(() => localInstant('2026-11-01', '01:30 AM')).toThrow('occurs twice');
    expect(localInstant('2026-11-01', '02:30 AM')).toBe('2026-11-01T10:30:00Z');
  },
);
