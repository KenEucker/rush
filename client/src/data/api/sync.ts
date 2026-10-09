import { sessionRequest } from './http';
import { parseOperation, parsePullPage, parseResult, type SyncOperation } from '../sync/protocol';

function path(organizationId: string, action: 'push' | 'pull'): string {
  return `/api/v1/organizations/${encodeURIComponent(organizationId)}/sync/${action}`;
}

// Transport only: no retries, queue, cached API responses or background activity.
export async function pushOperation(organizationId: string, input: SyncOperation) {
  const operation = parseOperation(input);
  return parseResult(
    await sessionRequest<unknown>(path(organizationId, 'push'), 'POST', operation),
    organizationId,
    operation,
  );
}

export async function pullChanges(
  organizationId: string,
  checkpoint: string | null = null,
  limit = 100,
) {
  if (!Number.isInteger(limit) || limit < 1 || limit > 100)
    throw new TypeError('Pull limit must be between 1 and 100.');
  return parsePullPage(
    await sessionRequest<unknown>(path(organizationId, 'pull'), 'POST', { checkpoint, limit }),
    organizationId,
  );
}
