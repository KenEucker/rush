// @vitest-environment jsdom
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import contract from '../../../../docs/contracts/member-profile.example.json';
import { getMemberProfile, updateMemberProfile } from './profiles';
import { ApiError } from './http';

const fetchMock = vi.fn<typeof fetch>();
const { organization_id: organizationId, id: profileId } = contract.profile;
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });

beforeEach(() => {
  vi.stubGlobal('fetch', fetchMock);
  fetchMock.mockReset();
  document.cookie = 'XSRF-TOKEN=test%3Dtoken';
});
afterEach(() => vi.unstubAllGlobals());

it('reads the maintained profile contract without persisting account data', async () => {
  fetchMock.mockResolvedValueOnce(response(contract.profile));
  expect(await getMemberProfile(organizationId, profileId)).toEqual(contract.profile);
  expect(localStorage.length).toBe(0);
});

it('sends only typed fields and the expected revision with CSRF and private request options', async () => {
  const updated = { ...contract.profile, ...contract.update, revision: 2 };
  fetchMock.mockResolvedValueOnce(response(updated));
  const result = await updateMemberProfile(organizationId, profileId, {
    ...contract.update,
    ...{ role: 'management' },
  });
  expect(result.revision).toBe(2);
  expect(result).not.toHaveProperty('expected_revision');
  expect(fetchMock).toHaveBeenCalledWith(
    `/api/v1/organizations/${organizationId}/profiles/${profileId}`,
    expect.objectContaining({
      method: 'PATCH',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'test=token' }),
      body: JSON.stringify(contract.update),
    }),
  );
});

it.each([undefined, null])(
  'distinguishes omitted and explicitly cleared phone values (%s)',
  async (phone) => {
    fetchMock.mockResolvedValueOnce(response(contract.profile));
    const command = {
      expected_revision: 1,
      display_name: 'Casey',
      ...(phone === undefined ? {} : { phone }),
    };
    await updateMemberProfile(organizationId, profileId, command);
    expect(fetchMock.mock.calls[0]?.[1]?.body).toBe(JSON.stringify(command));
  },
);

it('surfaces conflict intent without retrying or mutating the caller command', async () => {
  fetchMock.mockResolvedValueOnce(response(contract.conflict, 409));
  const command = Object.freeze({ ...contract.update });
  await expect(updateMemberProfile(organizationId, profileId, command)).rejects.toMatchObject({
    status: 409,
    code: 'revision_conflict',
    message: contract.conflict.message,
    errors: {},
  });
  expect(fetchMock).toHaveBeenCalledTimes(1);
  expect(command).toEqual(contract.update);
});

it('preserves field errors for forms', async () => {
  fetchMock.mockResolvedValueOnce(
    response(
      {
        code: 'validation_failed',
        message: 'Check your details and try again.',
        errors: { display_name: ['The display name field is required.'] },
      },
      422,
    ),
  );
  await expect(
    updateMemberProfile(organizationId, profileId, contract.update),
  ).rejects.toMatchObject({
    status: 422,
    code: 'validation_failed',
    errors: { display_name: ['The display name field is required.'] },
  });
});

it.each([
  null,
  { ...contract.profile, revision: '1' },
  { ...contract.profile, revision: 0 },
  { ...contract.profile, revision: 1.5 },
  { ...contract.profile, updated_at: '2026-10-09' },
  { ...contract.profile, organization_id: 'another-account' },
  { ...contract.profile, id: 'another-profile' },
  { ...contract.profile, phone: undefined },
])('rejects malformed or incorrectly scoped success responses %#', async (body) => {
  fetchMock.mockResolvedValueOnce(response(body));
  await expect(getMemberProfile(organizationId, profileId)).rejects.toMatchObject({
    code: 'invalid_response',
  });
});

it('keeps network failure unconfirmed and handles non-JSON proxy errors safely', async () => {
  fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'));
  await expect(
    updateMemberProfile(organizationId, profileId, contract.update),
  ).rejects.toBeInstanceOf(TypeError);
  fetchMock.mockResolvedValueOnce(new Response('<html>upstream details</html>', { status: 502 }));
  await expect(getMemberProfile(organizationId, profileId)).rejects.toMatchObject({
    status: 502,
    code: 'request_failed',
  });
});

it('rejects malformed error envelopes without displaying their arbitrary details', async () => {
  fetchMock.mockResolvedValueOnce(
    response(
      { code: 'oops', message: 'private upstream detail', errors: { name: 'not an array' } },
      500,
    ),
  );
  await expect(getMemberProfile(organizationId, profileId)).rejects.toEqual(
    new ApiError(500, 'The server could not complete your request.'),
  );
});
