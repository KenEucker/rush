import { ApiError, sessionRequest } from './http';

// Wire contracts mirror docs/contracts/member-profile.md; JSON is checked at ingress.
export interface MemberProfile {
  id: string;
  organization_id: string;
  display_name: string;
  phone: string | null;
  revision: number;
  updated_at: string;
}

export interface UpdateMemberProfile {
  expected_revision: number;
  display_name: string;
  phone?: string | null;
}

function parseProfile(value: unknown, organizationId: string, profileId: string): MemberProfile {
  if (typeof value === 'object' && value !== null) {
    const profile = value as Partial<MemberProfile>;
    if (
      profile.id === profileId &&
      profile.organization_id === organizationId &&
      typeof profile.display_name === 'string' &&
      (profile.phone === null || typeof profile.phone === 'string') &&
      typeof profile.revision === 'number' &&
      Number.isSafeInteger(profile.revision) &&
      profile.revision >= 1 &&
      typeof profile.updated_at === 'string' &&
      /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/.test(profile.updated_at) &&
      Number.isFinite(Date.parse(profile.updated_at))
    ) {
      return {
        id: profile.id,
        organization_id: profile.organization_id,
        display_name: profile.display_name,
        phone: profile.phone,
        revision: profile.revision,
        updated_at: profile.updated_at,
      };
    }
  }
  throw new ApiError(
    200,
    'The server returned an invalid profile. Please reload.',
    'invalid_response',
  );
}

function profilePath(organizationId: string, profileId: string): string {
  return `/api/v1/organizations/${encodeURIComponent(organizationId)}/profiles/${encodeURIComponent(profileId)}`;
}

export async function getMemberProfile(
  organizationId: string,
  profileId: string,
): Promise<MemberProfile> {
  return parseProfile(
    await sessionRequest<unknown>(profilePath(organizationId, profileId)),
    organizationId,
    profileId,
  );
}

export async function updateMemberProfile(
  organizationId: string,
  profileId: string,
  command: UpdateMemberProfile,
): Promise<MemberProfile> {
  const body: UpdateMemberProfile = {
    expected_revision: command.expected_revision,
    display_name: command.display_name,
    ...(command.phone === undefined ? {} : { phone: command.phone }),
  };
  return parseProfile(
    await sessionRequest<unknown>(profilePath(organizationId, profileId), 'PATCH', body),
    organizationId,
    profileId,
  );
}
