import { ApiError, type ApiFailure } from '../api/http';
import { parseProfile, type MemberProfile, type UpdateMemberProfile } from '../api/profiles';
import type { PendingCommand } from '../database/types';
import type { AccountStorage } from '../repositories/accountStorage';

export interface ProfileOperation {
  operation_id: string;
  type: 'member_profile.update';
  record_id: string;
  expected_revision: number;
  payload: { display_name: string; phone?: string | null };
}

// Extend this discriminated union only when a domain's offline workflow is implemented.
export type SyncOperation = ProfileOperation;
export type OperationResult =
  | { operation_id: string; status: 'accepted'; profile: MemberProfile }
  | { operation_id: string; status: 'rejected' | 'conflict'; error: ApiFailure };
export interface PullPage {
  changes: {
    sequence: number;
    record_type: 'member_profile';
    record_id: string;
    value: MemberProfile | null;
  }[];
  checkpoint: string;
  has_more: boolean;
}

const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
function object(value: unknown): Record<string, unknown> {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) invalid();
  return value as Record<string, unknown>;
}
function invalid(): never {
  throw new ApiError(
    200,
    'Invalid sync data. Preserve pending changes and try again.',
    'invalid_response',
  );
}

export function parseOperation(value: unknown): SyncOperation {
  const operation = object(value);
  const payload = object(operation.payload);
  if (
    typeof operation.operation_id !== 'string' ||
    !uuid.test(operation.operation_id) ||
    operation.type !== 'member_profile.update' ||
    typeof operation.record_id !== 'string' ||
    !uuid.test(operation.record_id) ||
    typeof operation.expected_revision !== 'number' ||
    !Number.isInteger(operation.expected_revision) ||
    operation.expected_revision < 1 ||
    operation.expected_revision > 2147483646 ||
    typeof payload.display_name !== 'string' ||
    (payload.phone !== undefined && payload.phone !== null && typeof payload.phone !== 'string')
  )
    invalid();
  return {
    operation_id: operation.operation_id.toLowerCase(),
    type: 'member_profile.update',
    record_id: operation.record_id.toLowerCase(),
    expected_revision: operation.expected_revision,
    payload: {
      display_name: payload.display_name,
      ...(payload.phone === undefined ? {} : { phone: payload.phone }),
    },
  };
}

export async function stageProfileUpdate(
  storage: AccountStorage,
  profileId: string,
  update: UpdateMemberProfile,
): Promise<SyncOperation> {
  const operation = parseOperation({
    operation_id: crypto.randomUUID(),
    type: 'member_profile.update',
    record_id: profileId,
    expected_revision: update.expected_revision,
    payload: {
      display_name: update.display_name,
      ...(update.phone === undefined ? {} : { phone: update.phone }),
    },
  });
  await storage.pending.stage({
    operationId: operation.operation_id,
    recordType: 'member_profile',
    recordId: operation.record_id,
    expectedRevision: operation.expected_revision,
    command: { type: operation.type, payload: { ...operation.payload } },
    localValue: { ...operation.payload },
  });
  return operation;
}

export function pendingOperation(pending: PendingCommand): SyncOperation {
  if (pending.recordType !== 'member_profile') invalid();
  return parseOperation({
    operation_id: pending.operationId,
    record_id: pending.recordId,
    expected_revision: pending.expectedRevision,
    type: pending.command.type,
    payload: pending.command.payload,
  });
}

export function parseResult(
  value: unknown,
  organizationId: string,
  operation: SyncOperation,
): OperationResult {
  const result = object(value);
  if (result.operation_id !== operation.operation_id) invalid();
  if (result.status === 'accepted')
    return {
      operation_id: operation.operation_id,
      status: 'accepted',
      profile: parseProfile(result.profile, organizationId, operation.record_id),
    };
  if (result.status !== 'rejected' && result.status !== 'conflict') invalid();
  const error = object(result.error);
  const errors = object(error.errors);
  if (
    typeof error.code !== 'string' ||
    !error.code ||
    typeof error.message !== 'string' ||
    !Object.values(errors).every(
      (messages) =>
        Array.isArray(messages) && messages.every((message) => typeof message === 'string'),
    )
  )
    invalid();
  return {
    operation_id: operation.operation_id,
    status: result.status,
    error: { code: error.code, message: error.message, errors: errors as Record<string, string[]> },
  };
}

export function parsePullPage(value: unknown, organizationId: string): PullPage {
  const page = object(value);
  if (
    !Array.isArray(page.changes) ||
    page.changes.length > 100 ||
    typeof page.checkpoint !== 'string' ||
    !page.checkpoint ||
    page.checkpoint.length > 4096 ||
    typeof page.has_more !== 'boolean' ||
    (page.has_more && page.changes.length === 0)
  )
    invalid();
  let previous = 0;
  const changes = page.changes.map((raw: unknown) => {
    const change = object(raw);
    if (
      typeof change.sequence !== 'number' ||
      !Number.isSafeInteger(change.sequence) ||
      change.sequence <= previous ||
      change.record_type !== 'member_profile' ||
      typeof change.record_id !== 'string' ||
      !uuid.test(change.record_id)
    )
      invalid();
    previous = change.sequence;
    return {
      sequence: change.sequence,
      record_type: 'member_profile' as const,
      record_id: change.record_id,
      value:
        change.value === null ? null : parseProfile(change.value, organizationId, change.record_id),
    };
  });
  return { changes, checkpoint: page.checkpoint, has_more: page.has_more };
}
