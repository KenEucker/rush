import 'fake-indexeddb/auto';
import { Dexie } from 'dexie';
import { afterEach, expect, it, vi } from 'vitest';
import contract from '../../../../docs/contracts/sync.example.json';
import { databaseName } from '../database/database';
import { openAccountStorage, type AccountStorage } from '../repositories/accountStorage';
import {
  parseOperation,
  parsePullPage,
  parseResult,
  pendingOperation,
  stageProfileUpdate,
} from './protocol';

const scope = { accountId: 1, organizationId: contract.accepted.profile.organization_id };
const opened: AccountStorage[] = [];
async function open() {
  const storage = await openAccountStorage(scope);
  opened.push(storage);
  return storage;
}
afterEach(async () => {
  vi.restoreAllMocks();
  opened.splice(0).forEach((storage) => storage.close());
  await Dexie.delete(databaseName(scope));
});

it('stages durable intent once and reuses the operation ID and payload after reload', async () => {
  const storage = await open();
  const first = await stageProfileUpdate(storage, contract.operation.record_id, {
    expected_revision: 1,
    display_name: 'My offline intent',
    phone: null,
    ...{ password: 'never persist', role: 'management' },
  });
  storage.close();
  const reopened = await open();
  const [pending] = await reopened.pending.list();
  expect(pending).toBeDefined();
  expect(pendingOperation(pending!)).toEqual(first);
  expect(await reopened.pending.records()).toEqual([
    {
      operationId: first.operation_id,
      recordType: 'member_profile',
      recordId: first.record_id,
      value: first.payload,
    },
  ]);
  expect(JSON.stringify(pending)).not.toContain('never persist');
  expect(await reopened.profiles.list()).toEqual([]);
  const second = await stageProfileUpdate(reopened, first.record_id, {
    expected_revision: 1,
    display_name: 'Next edit',
  });
  expect(second.operation_id).not.toBe(first.operation_id);
});

it('does not report a staged command when the durable transaction fails', async () => {
  const storage = await open();
  storage.close();
  await expect(
    stageProfileUpdate(storage, contract.operation.record_id, {
      expected_revision: 1,
      display_name: 'Keep input',
    }),
  ).rejects.toThrow();
  expect(await (await open()).pending.list()).toEqual([]);
});

it('reads the shared contract and rejects an accepted response for another operation or organization', () => {
  const operation = parseOperation(contract.operation);
  expect(parseResult(contract.accepted, scope.organizationId, operation)).toEqual(
    contract.accepted,
  );
  expect(() =>
    parseResult(
      { ...contract.accepted, operation_id: crypto.randomUUID() },
      scope.organizationId,
      operation,
    ),
  ).toThrow();
  expect(() => parseResult(contract.accepted, crypto.randomUUID(), operation)).toThrow();
  const result = {
    operation_id: operation.operation_id,
    status: 'conflict',
    error: { code: 'revision_conflict', message: 'Review your intent', errors: {} },
  };
  expect(parseResult(result, scope.organizationId, operation)).toEqual(result);
  expect(() =>
    parseResult(
      { ...result, error: { ...result.error, errors: [] } },
      scope.organizationId,
      operation,
    ),
  ).toThrow();
});

it('preserves omitted phone semantics and rejects unsupported or malformed queued commands', () => {
  const operation = parseOperation({ ...contract.operation, payload: { display_name: 'Name' } });
  expect(operation.payload).not.toHaveProperty('phone');
  for (const override of [
    { type: 'schedule.publish' },
    { expected_revision: null },
    { expected_revision: 1.5 },
    { operation_id: 'bad' },
    { payload: { display_name: false } },
  ]) {
    expect(() => parseOperation({ ...contract.operation, ...override })).toThrow();
  }
});

it('decodes ordered changes and tombstones while rejecting malformed or foreign pages', () => {
  const page = {
    changes: [
      {
        sequence: 1,
        record_type: 'member_profile',
        record_id: contract.operation.record_id,
        value: contract.accepted.profile,
      },
      {
        sequence: 2,
        record_type: 'member_profile',
        record_id: contract.operation.record_id,
        value: null,
      },
    ],
    checkpoint: 'opaque-token',
    has_more: false,
  };
  expect(parsePullPage(page, scope.organizationId)).toEqual(page);
  expect(() => parsePullPage(page, crypto.randomUUID())).toThrow();
  expect(() =>
    parsePullPage({ ...page, changes: [...page.changes].reverse() }, scope.organizationId),
  ).toThrow();
  expect(() => parsePullPage({ ...page, checkpoint: '' }, scope.organizationId)).toThrow();
  expect(() =>
    parsePullPage({ ...page, changes: [], has_more: true }, scope.organizationId),
  ).toThrow();
  expect(() =>
    parsePullPage(
      { ...page, changes: [{ ...page.changes[0], value: undefined }] },
      scope.organizationId,
    ),
  ).toThrow();
});
