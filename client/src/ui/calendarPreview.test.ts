import { describe, expect, it } from 'vitest';
import { dateLabel, rangeLabel, shiftDate, timeLabel } from './calendarPreview';
describe('calendar presentation baseline', () => {
  it.each([
    ['2026-12-31', 1, '2027-01-01'],
    ['2028-02-28', 1, '2028-02-29'],
    ['2026-03-08', -1, '2026-03-07'],
    ['2026-11-01', 1, '2026-11-02'],
  ])('moves %s by %i calendar days', (day, amount, expected) => {
    expect(shiftDate(day, amount)).toBe(expected);
  });
  it('labels weekdays, midnight, noon and minutes explicitly', () => {
    expect(dateLabel('2026-10-09')).toBe('Friday, October 9, 2026');
    expect(timeLabel('00:05')).toBe('12:05 AM');
    expect(timeLabel('12:00')).toBe('12:00 PM');
    expect(timeLabel('23:45')).toBe('11:45 PM');
  });
  it('shows both dates for an overnight range crossing a week and year', () => {
    expect(rangeLabel('2028-12-31', '22:00', '06:00', true)).toBe(
      'Sunday, December 31, 2028, 10:00 PM – Monday, January 1, 2029, 6:00 AM (overnight; next day)',
    );
  });
});
