import { sessionRequest } from './http';
export { ApiError, sessionRequest } from './http';

export interface SessionIdentity {
  user: { id: number; name: string; email: string };
  memberships: {
    id: string;
    role: 'ranger' | 'management';
    organization: { id: string; name: string };
    profile: { id: string; display_name: string; phone: string | null } | null;
  }[];
}

export async function login(email: string, password: string) {
  await sessionRequest('/sanctum/csrf-cookie');
  return sessionRequest<SessionIdentity>('/login', 'POST', { email, password });
}

export async function logout() {
  await sessionRequest('/sanctum/csrf-cookie');
  await sessionRequest('/logout', 'POST');
}
