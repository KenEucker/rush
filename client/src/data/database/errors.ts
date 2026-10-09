export type StorageErrorCode =
  | 'unavailable'
  | 'quota'
  | 'schema'
  | 'blocked'
  | 'closed'
  | 'pending_work'
  | 'read_failed'
  | 'write_failed';

const messages: Record<StorageErrorCode, string> = {
  unavailable: 'Offline storage is unavailable. Allow browser storage and retry before saving.',
  quota: 'Offline storage is full. Free device space and retry. This change was not saved.',
  schema: 'Offline storage could not be upgraded. Reload the latest app; do not clear unsent work.',
  blocked: 'Close other RUSH tabs and retry opening offline storage.',
  closed: 'Offline storage was closed. Reopen this account or reload the app before continuing.',
  pending_work:
    'Unsent or unresolved changes remain. Resolve or explicitly discard them before clearing this cache.',
  read_failed: 'Offline data could not be read. Retry before making changes.',
  write_failed: 'This change was not saved to offline storage. Keep your input and retry.',
};

export class StorageError extends Error {
  constructor(
    public readonly code: StorageErrorCode,
    cause?: unknown,
  ) {
    super(messages[code], { cause });
    this.name = 'StorageError';
  }
}

export function storageError(error: unknown, fallback: StorageErrorCode): StorageError {
  if (error instanceof StorageError) return error;
  const name = error instanceof Error ? error.name : '';
  if (name === 'QuotaExceededError') return new StorageError('quota', error);
  if (['MissingAPIError', 'SecurityError', 'InvalidStateError'].includes(name)) {
    return new StorageError('unavailable', error);
  }
  if (['VersionError', 'SchemaError', 'UpgradeError'].includes(name)) {
    return new StorageError('schema', error);
  }
  if (name === 'DatabaseClosedError') return new StorageError('closed', error);
  return new StorageError(fallback, error);
}
