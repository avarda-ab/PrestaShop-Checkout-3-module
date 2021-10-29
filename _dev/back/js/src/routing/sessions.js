// @flow
import type { RouteDefinition } from 'src/types';
import Sessions from 'src/pages/sessions';
import { getSessions } from 'src/actions/creators';

export type SessionsPage = {
  type: 'sessions',
  page: number,
}

const toUrl = (sessions: SessionsPage) => {
  const { page } = sessions;
  return '/sessions/' + page;
};

const toState = (url: string): ?SessionsPage => {
  const m = url.match(/^\/sessions\/([0-9]+)$/);
  if (m) {
    const page = parseInt(m[1]);
    return sessionsPage(page);
  }
};

export const sessionsPage = (page:number = 1):SessionsPage => ({
  type: 'sessions',
  page,
});

const setup = (page: SessionsPage, store: any) => {
  store.dispatch(getSessions(page.page));
};

const update = (from: SessionsPage, to: SessionsPage, store: any) => {
  store.dispatch(getSessions(to.page));
};

export const sessionsRoute: RouteDefinition<SessionsPage> = {
  toUrl,
  toState,
  component: Sessions,
  setup,
  update,
};
