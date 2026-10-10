import { ApiError } from '../api/http';
import { pullChanges, pushOperation } from '../api/sync';
import type { UpdateMemberProfile } from '../api/profiles';
import { databaseName } from '../database/database';
import { StorageError } from '../database/errors';

import type { AccountStorage } from '../repositories/accountStorage';
import { SYNC_STREAM } from '../repositories/syncRepository';
import { pendingOperation, stageProfileUpdate } from './protocol';
import { stageAvailability } from './protocol';
import type { AvailabilityInput } from '../api/availability';

export interface SyncEnvironment {
  online(): boolean;
  now(): number;
  random(): number;
  subscribe(wake: () => void): () => void;
  exclusive(name: string, signal: AbortSignal, work: () => Promise<void>): Promise<void>;
}

export function browserSyncEnvironment(): SyncEnvironment {
  return {
    online: () => navigator.onLine,
    now: () => Date.now(),
    random: () => Math.random(),
    subscribe(wake) {
      const visible = () => {
        if (document.visibilityState === 'visible') wake();
      };
      window.addEventListener('online', wake);
      document.addEventListener('visibilitychange', visible);
      return () => {
        window.removeEventListener('online', wake);
        document.removeEventListener('visibilitychange', visible);
      };
    },
    async exclusive(name, signal, work) {
      if (!navigator.locks) {
        throw new Error(
          'Safe synchronization requires browser Web Locks. Use a supported browser; pending changes are preserved.',
        );
      }
      await navigator.locks.request(name, { signal }, work);
    },
  };
}

export interface SyncTransport {
  push: typeof pushOperation;
  pull: typeof pullChanges;
}
const transport: SyncTransport = { push: pushOperation, pull: pullChanges };

// One account-scoped coordinator, owned by the session lifecycle, not components.
// Construction does not start network activity. Call stop before closing/switching
// storage, and await it before opening the next verified account session.
export class SyncCoordinator {
  private flight: Promise<void> | undefined;
  private abort: AbortController | undefined;
  private timer: ReturnType<typeof setTimeout> | undefined;
  private unsubscribe: (() => void) | undefined;
  private started = false;
  private stopped = false;
  private wakeAgain = false;
  private nextWakeDelay = 30_000;
  // Storage/capability errors cannot necessarily be persisted. Expose them to callers.
  lastError: unknown = null;

  constructor(
    private readonly storage: AccountStorage,
    private readonly environment: SyncEnvironment = browserSyncEnvironment(),
    private readonly api: SyncTransport = transport,
    private readonly authorizationFailed?: (error: ApiError) => Promise<void>,
  ) {}

  async queueProfileUpdate(id: string, update: UpdateMemberProfile) {
    if (this.stopped) throw new Error('Reopen this account before saving changes.');
    const operation = await stageProfileUpdate(this.storage, id, update);
    // Local success means both durable inserts committed, never Server acceptance.
    if (this.started) this.wake();
    return operation;
  }

  async queueAvailability(input: AvailabilityInput, id?: string, revision: number | null = null) {
    if (this.stopped) throw new Error('Reopen this account before saving changes.');
    const operation = await stageAvailability(this.storage, input, id, revision);
    if (this.started) this.wake();
    return operation;
  }

  start(): Promise<void> {
    if (!this.started) {
      this.started = true;
      this.stopped = false;
      this.unsubscribe = this.environment.subscribe(() => this.wake());
    }
    return this.syncOnce();
  }

  async stop(): Promise<void> {
    this.stopped = true;
    this.started = false;
    this.unsubscribe?.();
    this.unsubscribe = undefined;
    if (this.timer !== undefined) clearTimeout(this.timer);
    this.abort?.abort();
    await this.flight;
  }

  // Only invoke after the session owner has revalidated this exact account/org.
  async resumeAfterAuthentication(): Promise<void> {
    await this.stop();
    const abort = new AbortController();
    await this.environment.exclusive(databaseName(this.storage.scope), abort.signal, async () => {
      const state = await this.storage.sync.state();
      await this.storage.sync.saveState({
        ...state,
        paused: false,
        problem: null,
        nextAttemptAt: null,
        failures: 0,
      });
    });
    await this.start();
  }

  syncOnce(): Promise<void> {
    if (this.flight) return this.flight;
    if (this.stopped) return Promise.resolve();
    const abort = new AbortController();
    this.abort = abort;
    this.flight = this.environment
      .exclusive(databaseName(this.storage.scope), abort.signal, async () => {
        if (!abort.signal.aborted) await this.run(abort.signal);
      })
      .catch((error: unknown) => {
        if (!abort.signal.aborted) {
          this.lastError = error;
          throw error;
        }
      })
      .finally(() => {
        this.flight = undefined;
        this.abort = undefined;
        if (this.started) this.arm(this.wakeAgain ? 0 : this.nextWakeDelay);
        this.wakeAgain = false;
      });
    return this.flight;
  }

  private wake() {
    if (!this.started) return;
    if (this.flight) {
      this.wakeAgain = true;
      return;
    }
    void this.syncOnce().catch(() => {
      /* lastError exposes storage/capability failures. */
    });
  }

  private arm(delay: number) {
    if (this.timer !== undefined) clearTimeout(this.timer);
    this.timer = setTimeout(() => this.wake(), delay);
  }

  private async request<T>(
    signal: AbortSignal,
    work: (signal: AbortSignal) => Promise<T>,
  ): Promise<T> {
    const request = new AbortController();
    const stop = () => request.abort();
    signal.addEventListener('abort', stop, { once: true });
    const timeout = setTimeout(stop, 30_000);
    try {
      if (signal.aborted) request.abort();
      return await work(request.signal);
    } finally {
      clearTimeout(timeout);
      signal.removeEventListener('abort', stop);
    }
  }

  private async run(signal: AbortSignal): Promise<void> {
    this.nextWakeDelay = 30_000;
    if (!this.environment.online()) return; // A hint only; actual requests prove reachability.
    let state = await this.storage.sync.state();
    if (state.paused) return;
    if (state.nextAttemptAt !== null && state.nextAttemptAt > this.environment.now()) {
      this.nextWakeDelay = state.nextAttemptAt - this.environment.now();
      return;
    }
    this.lastError = null;
    let operationId: string | undefined;
    try {
      const pending = await this.storage.pending.list();
      if (
        pending.filter((item) => ['pending', 'syncing', 'failed'].includes(item.state)).length > 100
      )
        this.wakeAgain = true;
      for (const command of pending
        .filter((item) => ['pending', 'syncing', 'failed'].includes(item.state))
        .slice(0, 100)) {
        if (signal.aborted || !this.environment.online()) return;
        operationId = command.operationId;
        // Invalid/unsupported saved intent must remain visible, never disappear or retry forever.
        let operation;
        try {
          operation = pendingOperation(command);
        } catch {
          await this.storage.sync.mark(operationId, 'rejected', {
            code: 'invalid_command',
            message: 'This saved change cannot be sent. Review it before submitting a new change.',
          });
          continue;
        }
        await this.storage.sync.mark(operationId, 'syncing');
        try {
          const result = await this.request(signal, (requestSignal) =>
            this.api.push(this.storage.scope.organizationId, operation, requestSignal),
          );
          if (signal.aborted) return;
          await this.storage.sync.settle(result);
        } catch (error) {
          if (signal.aborted) return;
          // Protocol/domain failures are terminal for this ID. Other queued work can continue.
          if (error instanceof ApiError && [404, 409, 422].includes(error.status)) {
            await this.storage.sync.mark(
              operationId,
              error.status === 409 ? 'conflict' : 'rejected',
              {
                code: error.code,
                message: error.message,
              },
            );
          } else throw error;
        }
      }
      operationId = undefined;
      let reset = false;
      for (let pageNumber = 0; pageNumber < 100; pageNumber++) {
        if (signal.aborted || !this.environment.online()) return;
        const checkpoint = (await this.storage.checkpoints.get(SYNC_STREAM))?.token ?? null;
        try {
          const page = await this.request(signal, (requestSignal) =>
            this.api.pull(this.storage.scope.organizationId, checkpoint, 100, requestSignal),
          );
          if (signal.aborted) return;
          const now = new Date(this.environment.now()).toISOString();
          if (!(await this.storage.sync.applyPage(checkpoint, page, now))) return;
          if (!page.has_more) {
            state = await this.storage.sync.state();
            await this.storage.sync.saveState({
              ...state,
              failures: 0,
              nextAttemptAt: null,
              problem: null,
              lastSuccessfulSyncAt: now,
            });
            return;
          }
        } catch (error) {
          if (signal.aborted) return;
          if (
            error instanceof ApiError &&
            error.code === 'checkpoint_invalid' &&
            error.status === 409 &&
            !reset
          ) {
            if (!(await this.storage.sync.reset(checkpoint))) return;
            reset = true;
            continue;
          }
          throw error;
        }
      }
      // Yield a very large backlog to other tabs; the persisted checkpoint resumes it.
      this.wakeAgain = true;
    } catch (error) {
      if (signal.aborted) return;
      if (error instanceof StorageError) throw error;
      const problem =
        error instanceof ApiError
          ? { code: error.code, message: error.message }
          : {
              code: 'connection_failed',
              message:
                'Cannot reach the Server. Your changes are saved; synchronization will retry.',
            };
      if (operationId)
        await this.storage.sync.mark(
          operationId,
          error instanceof ApiError && error.status === 403 ? 'rejected' : 'failed',
          problem,
        );
      state = await this.storage.sync.state();
      const failures = state.failures + 1;
      const paused =
        error instanceof ApiError &&
        ([401, 403, 419].includes(error.status) ||
          (error.status >= 400 && error.status < 500 && ![408, 429].includes(error.status)));
      const delay =
        Math.min(300_000, 1000 * 2 ** Math.min(failures - 1, 9)) *
        (0.75 + this.environment.random() * 0.25);
      this.nextWakeDelay = paused ? 30_000 : Math.ceil(delay);
      await this.storage.sync.saveState({
        ...state,
        failures,
        problem,
        paused,
        nextAttemptAt: paused ? null : this.environment.now() + Math.ceil(delay),
      });
      if (error instanceof ApiError && [401, 403, 419].includes(error.status))
        await this.authorizationFailed?.(error);
    }
  }
}
