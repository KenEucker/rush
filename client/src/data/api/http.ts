export interface ApiFailure {
  code: string;
  message: string;
  errors: Record<string, string[]>;
}

export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public code = 'request_failed',
    public errors: Record<string, string[]> = {},
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

function isApiFailure(value: unknown): value is ApiFailure {
  if (typeof value !== 'object' || value === null) return false;
  const failure = value as Partial<ApiFailure>;
  return (
    typeof failure.code === 'string' &&
    typeof failure.message === 'string' &&
    typeof failure.errors === 'object' &&
    failure.errors !== null &&
    !Array.isArray(failure.errors) &&
    Object.values(failure.errors).every(
      (messages) =>
        Array.isArray(messages) && messages.every((message) => typeof message === 'string'),
    )
  );
}

export async function sessionRequest<T>(
  path: string,
  method = 'GET',
  body?: unknown,
  signal?: AbortSignal,
  accountId?: number,
  membershipId?: string,
): Promise<T> {
  const token = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='));
  const response = await fetch(path, {
    method,
    ...(signal ? { signal } : {}),
    credentials: 'same-origin',
    cache: 'no-store',
    headers: {
      Accept: 'application/json',
      ...(membershipId === undefined ? {} : { 'X-RUSH-Membership': membershipId }),
      ...(accountId === undefined ? {} : { 'X-RUSH-Account': String(accountId) }),
      ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
      ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token.slice('XSRF-TOKEN='.length)) } : {}),
    },
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
  });
  if (!response.ok) {
    const failure: unknown = await response.json().catch(() => null);
    const messages: Record<number, string> = {
      401: 'Your session has expired. Please sign in again.',
      403: 'You no longer have access to this information.',
      409: 'The request conflicts with the current state.',
      419: 'Your security token has expired. Please try again.',
      422: 'Check your details and try again.',
      429: 'Too many attempts. Please wait a minute and try again.',
    };
    if (isApiFailure(failure)) {
      throw new ApiError(response.status, failure.message, failure.code, failure.errors);
    }
    throw new ApiError(
      response.status,
      messages[response.status] ?? 'The server could not complete your request.',
    );
  }
  return response.status === 204 ? (undefined as T) : ((await response.json()) as T);
}
