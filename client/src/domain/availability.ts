// The proof editor explicitly uses the device time zone. Season calendar policy
// remains RUSH-015; the wire and database always carry unambiguous UTC instants.
export function localInstant(date: string, time: string): string {
  const match = /^(0?[1-9]|1[0-2]):([0-5][0-9]) (AM|PM)$/i.exec(time);
  if (!match) throw new Error('Enter a time with AM or PM, for example 09:00 AM.');
  const hour = (Number(match[1]) % 12) + (match[3]!.toUpperCase() === 'PM' ? 12 : 0);
  const raw = date + 'T' + String(hour).padStart(2, '0') + ':' + match[2] + ':00';
  const value = new Date(raw);
  const pad = (part: number) => String(part).padStart(2, '0');
  const roundTrip = Number.isFinite(value.getTime())
    ? value.getFullYear() +
      '-' +
      pad(value.getMonth() + 1) +
      '-' +
      pad(value.getDate()) +
      'T' +
      pad(value.getHours()) +
      ':' +
      pad(value.getMinutes()) +
      ':00'
    : '';
  if (roundTrip !== raw) throw new Error('This local time does not exist. Choose another time.');
  // A repeated local time is ambiguous. Require a different time rather than silently choosing an offset.
  const later = new Date(value.getTime() + 60 * 60 * 1000);
  if (later.getHours() === value.getHours() && later.getMinutes() === value.getMinutes()) {
    throw new Error('This time occurs twice during a clock change. Choose an unambiguous time.');
  }
  return value.toISOString().replace('.000Z', 'Z');
}
export function localParts(instant: string) {
  const value = new Date(instant);
  const pad = (part: number) => String(part).padStart(2, '0');
  return {
    date: value.getFullYear() + '-' + pad(value.getMonth() + 1) + '-' + pad(value.getDate()),
    time:
      pad(value.getHours() % 12 || 12) +
      ':' +
      pad(value.getMinutes()) +
      (value.getHours() < 12 ? ' AM' : ' PM'),
  };
}
export function formatInterval(start: string, end: string): string {
  const format = new Intl.DateTimeFormat('en-US', {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
  });
  const overnight = localParts(start).date !== localParts(end).date;
  return (
    format.format(new Date(start)) +
    ' – ' +
    format.format(new Date(end)) +
    (overnight ? ' (overnight / multiple days)' : '')
  );
}
