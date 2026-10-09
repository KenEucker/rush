// @vitest-environment jsdom
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import contract from '../../../../docs/contracts/sync.example.json';
import { parseOperation } from '../sync/protocol';
import { pullChanges, pushOperation } from './sync';

const fetchMock = vi.fn<typeof fetch>();
const organizationId = contract.accepted.profile.organization_id;
const operation = parseOperation(contract.operation);
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
beforeEach(() => {
  vi.stubGlobal('fetch', fetchMock);
  fetchMock.mockReset();
  document.cookie = 'XSRF-TOKEN=sync%3Dtoken';
});
afterEach(() => vi.unstubAllGlobals());

it('uses the maintained push contract with same-origin cookies and CSRF without extra fields', async () => {
  fetchMock.mockResolvedValueOnce(response(contract.accepted));
  expect(
    await pushOperation(organizationId, { ...operation, ...{ password: 'never send' } }),
  ).toEqual(contract.accepted);
  expect(fetchMock).toHaveBeenCalledWith(
    `/api/v1/organizations/${organizationId}/sync/push`,
    expect.objectContaining({
      method: 'POST',
      cache: 'no-store',
      credentials: 'same-origin',
      body: JSON.stringify(operation),
      headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'sync=token' }),
    }),
  );
});

it('passes checkpoints unchanged and validates the bounded pull response', async () => {
  const page = { changes: [], checkpoint: 'next-token', has_more: false };
  fetchMock.mockResolvedValueOnce(response(page));
  expect(await pullChanges(organizationId, 'previous-token', 10)).toEqual(page);
  expect(fetchMock.mock.calls[0]?.[1]?.body).toBe(
    JSON.stringify({ checkpoint: 'previous-token', limit: 10 }),
  );
  await expect(pullChanges(organizationId, null, 101)).rejects.toThrow();
  expect(fetchMock).toHaveBeenCalledTimes(1);
});

it.each([401, 403, 409, 419, 429, 503])(
  'propagates HTTP %s without retrying or losing the caller intent',
  async (status) => {
    fetchMock.mockResolvedValueOnce(
      response({ code: 'checkpoint_invalid', message: 'Recovery required', errors: {} }, status),
    );
    const copy = structuredClone(operation);
    await expect(pushOperation(organizationId, operation)).rejects.toMatchObject({
      status,
      code: 'checkpoint_invalid',
    });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(operation).toEqual(copy);
  },
);

it('leaves interrupted pushes available for replay with the same ID', async () => {
  fetchMock.mockRejectedValueOnce(new TypeError('network interrupted'));
  await expect(pushOperation(organizationId, operation)).rejects.toThrow('network interrupted');
  fetchMock.mockResolvedValueOnce(response(contract.accepted));
  await pushOperation(organizationId, operation);
  expect(fetchMock.mock.calls[0]?.[1]?.body).toEqual(fetchMock.mock.calls[1]?.[1]?.body);
});
