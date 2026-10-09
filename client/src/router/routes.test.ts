import { describe, expect, it } from 'vitest';

import routes from './routes';

describe('router foundation', () => {
  it('registers the application shell route', () => {
    expect(routes[0]?.path).toBe('/');
    expect(routes[0]?.children?.[0]?.path).toBe('');
  });

  it('keeps a catch-all not-found route last', () => {
    expect(routes.at(-1)?.path).toBe('/:catchAll(.*)*');
  });
});
