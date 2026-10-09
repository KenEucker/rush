export interface SessionIdentity {
  user: { id: number; name: string; email: string };
  memberships: {
    id: string;
    role: 'ranger' | 'management';
    organization: { id: string; name: string };
    profile: { id: string; display_name: string; phone: string | null } | null;
  }[];
}

export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
  ) {
    super(message);
  }
}

export async function sessionRequest<T>(path: string, method = 'GET', body?: unknown): Promise<T> {
  const token = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='));
  const response = await fetch(path, {
    method,
    credentials: 'same-origin',
    cache: 'no-store',
    headers: {
      Accept: 'application/json',
      ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
      ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token.slice('XSRF-TOKEN='.length)) } : {}),
    },
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
  });
  if (!response.ok) {
    const messages: Record<number, string> = {
      401: 'Your session has expired. Please sign in again.',
      403: 'You no longer have access to this information.',
      409: 'Sign out before signing in to another account.',
      419: 'Your security token has expired. Please try again.',
      422: 'Check your details and try again.',
      429: 'Too many attempts. Please wait a minute and try again.',
    };
    throw new ApiError(
      response.status,
      messages[response.status] ?? 'The server could not complete your request.',
    );
  }
  return response.status === 204 ? (undefined as T) : ((await response.json()) as T);
}

export async function login(email: string, password: string) {
  await sessionRequest('/sanctum/csrf-cookie');
  return sessionRequest<SessionIdentity>('/login', 'POST', { email, password });
}

export async function logout() {
  await sessionRequest('/sanctum/csrf-cookie');
  await sessionRequest('/logout', 'POST');
}
