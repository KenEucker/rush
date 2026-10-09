// @vitest-environment jsdom
import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { useSessionStore } from './session';
import { ApiError, type SessionIdentity } from '../data/api/session';

const identity: SessionIdentity = {
  user: { id: 1, name: 'Casey', email: 'casey@example.test' },
  memberships: [
    {
      id: 'membership-a',
      role: 'ranger',
      organization: { id: 'org-a', name: 'Rangers' },
      profile: null,
    },
  ],
};
const fetchMock = vi.fn<typeof fetch>();
const ok = (body: unknown) => new Response(JSON.stringify(body), { status: 200 });

beforeEach(() => {
  setActivePinia(createPinia());
  vi.stubGlobal('fetch', fetchMock);
  fetchMock.mockReset();
  document.cookie = 'XSRF-TOKEN=test%3Dtoken';
});
afterEach(() => vi.unstubAllGlobals());

describe('session lifecycle', () => {
  it('initializes csrf before login and uses same-origin cookies without storing credentials', async () => {
    fetchMock
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(ok(identity));
    const session = useSessionStore();
    await session.signIn('casey@example.test', 'secret');
    expect(fetchMock.mock.calls[0]?.[0]).toBe('/sanctum/csrf-cookie');
    expect(fetchMock.mock.calls[1]).toEqual([
      '/login',
      expect.objectContaining({
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'test=token' }),
        body: JSON.stringify({ email: 'casey@example.test', password: 'secret' }),
      }),
    ]);
    expect(session.identity).toEqual(identity);
    expect(JSON.stringify(session.$state)).not.toContain('secret');
    expect(localStorage.length).toBe(0);
  });

  it('clears account state after confirmed logout and loads only the next account', async () => {
    const session = useSessionStore();
    session.identity = identity;
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }));
    await session.signOut();
    expect(session.identity).toBeNull();
    const next = {
      ...identity,
      user: { ...identity.user, id: 2, name: 'Jordan' },
      memberships: [],
    };
    fetchMock
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(ok(next));
    await session.signIn('jordan@example.test', 'secret');
    expect(session.identity?.user.name).toBe('Jordan');
    expect(session.identity?.memberships).toEqual([]);
  });

  it('does not claim sign-out when the network fails', async () => {
    const session = useSessionStore();
    session.identity = identity;
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'));
    await expect(session.signOut()).rejects.toThrow();
    expect(session.identity).toEqual(identity);
  });

  it('clears an expired session and permits reauthentication', async () => {
    const session = useSessionStore();
    session.identity = identity;
    fetchMock.mockResolvedValueOnce(new Response(null, { status: 401 }));
    await session.refresh();
    expect(session.identity).toBeNull();
    expect(session.checked).toBe(true);
    fetchMock
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(ok(identity));
    await session.signIn(identity.user.email, 'secret');
    expect(session.identity).toEqual(identity);
  });

  it.each([403, 419, 422, 429])(
    'surfaces status %i without retaining a previous identity',
    async (status) => {
      const session = useSessionStore();
      session.identity = identity;
      fetchMock
        .mockResolvedValueOnce(new Response(null, { status: 204 }))
        .mockResolvedValueOnce(new Response(null, { status }));
      await expect(session.signIn('casey@example.test', 'wrong')).rejects.toBeInstanceOf(ApiError);
      expect(session.identity).toBeNull();
    },
  );
});
