import type { MemberProfile } from '../api/profiles';

export interface AccountScope {
  accountId: number;
  organizationId: string;
}

export type JsonValue = string | number | boolean | null | JsonValue[] | JsonObject;
export interface JsonObject {
  [key: string]: JsonValue;
}

export interface CachedProfile {
  id: string;
  value: MemberProfile;
  cachedAt: string;
}

// Storage envelopes; data/sync/protocol.ts owns validated wire commands.
export type PendingState = 'pending' | 'syncing' | 'failed' | 'rejected' | 'conflict';
export interface PendingCommand {
  operationId: string;
  recordType: string;
  recordId: string;
  expectedRevision: number | null;
  command: JsonObject;
  createdAt: string;
  state: PendingState;
  attempts: number;
  acceptedRevision?: number;
  problem: { code: string; message: string } | null;
}

export interface PendingRecord {
  operationId: string;
  recordType: string;
  recordId: string;
  value: JsonObject | null;
}

export interface Checkpoint {
  stream: string;
  token: string;
  lastSuccessfulSyncAt: string | null;
}

export interface CacheMetadata {
  key: 'cache';
  accountId: number;
  organizationId: string;
  schemaVersion: number;
  createdAt: string;
  lastCachedAt: string | null;
}

export interface SyncState {
  key: 'coordinator';
  failures: number;
  nextAttemptAt: number | null;
  paused: boolean;
  problem: { code: string; message: string } | null;
  lastSuccessfulSyncAt: string | null;
}
