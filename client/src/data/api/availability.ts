import { ApiError } from './http';

export interface Unavailability {
  id: string;
  organization_id: string;
  membership_id: string;
  starts_at: string;
  ends_at: string;
  revision: number;
}
export interface AvailabilityInput {
  membership_id: string;
  starts_at: string;
  ends_at: string;
}
export const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
export function validInstant(value: unknown): value is string {
  return (
    typeof value === 'string' &&
    /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(value) &&
    Number.isFinite(Date.parse(value)) &&
    new Date(value).toISOString().replace('.000Z', 'Z') === value
  );
}
export function validateInterval(input: AvailabilityInput): void {
  if (
    !uuid.test(input.membership_id) ||
    !validInstant(input.starts_at) ||
    !validInstant(input.ends_at) ||
    input.ends_at <= input.starts_at
  ) {
    throw new Error('Choose valid start and end times. The end must be after the start.');
  }
}
export function parseUnavailability(
  raw: unknown,
  organizationId: string,
  id: string,
): Unavailability {
  const value = raw as Partial<Unavailability> | null;
  if (
    !value ||
    value.id !== id ||
    !uuid.test(id) ||
    value.organization_id !== organizationId ||
    typeof value.membership_id !== 'string' ||
    !uuid.test(value.membership_id) ||
    !validInstant(value.starts_at) ||
    !validInstant(value.ends_at) ||
    value.ends_at <= value.starts_at ||
    !Number.isInteger(value.revision) ||
    (value.revision ?? 0) < 1
  ) {
    throw new ApiError(
      200,
      'Invalid unavailability received. Your pending changes are preserved.',
      'invalid_response',
    );
  }
  return {
    id,
    organization_id: organizationId,
    membership_id: value.membership_id,
    starts_at: value.starts_at,
    ends_at: value.ends_at,
    revision: value.revision!,
  };
}
