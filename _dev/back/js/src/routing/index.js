// @flow

import React from 'react';
import { equals, memoize } from 'ramda';

import type { SettingsPage } from './settings';
import { settingsRoute } from './settings';
export { settingsPage } from './settings';

import type { SessionsPage } from './sessions';
import { sessionsRoute } from './sessions';
export { sessionsPage } from './sessions';

export type RoutingState = (
  SettingsPage |
  SessionsPage
);

export type GoTo = (RoutingState) => void;

const routes = {
  settings: settingsRoute,
  sessions: sessionsRoute,
};

// API
export const transition = (from: ?RoutingState, to: RoutingState, store: any) => {
  if (!equals(from, to)) {
    if (from && from.type === to.type) {
      const update = routes[to.type].update;
      // $FlowFixMe
      update && update(from, to, store);
    } else {
      if (from) {
        // teardown
        const teardown = routes[from.type].teardown;
        // $FlowFixMe
        teardown && teardown(from, store);
      }
      // setup
      const setup = routes[to.type].setup;
      // $FlowFixMe
      setup && setup(to, store);
    }
    return true;
  }
  return false;
};

export const render = (state:RoutingState) => {
  const { type, ...rest } = state;
  const Component = routes[type].component;
  return (
    <Component {...rest} />
  );
};

export const toUrl = (state: RoutingState): string => {
  const handler = routes[state.type].toUrl;
  // $FlowFixMe
  return fixUrl(handler(state));
};

export const toState = memoize((url:string): ?RoutingState => {
  for (var k in routes) {
    if (routes.hasOwnProperty(k)) {
      const state = routes[k].toState(url);
      if (state) {
        return state;
      }
    }
  }
});

export const fixUrl = (url: string): string => {
  if (!url || url === '') {
    return '/';
  }
  if (url === '/') {
    return url;
  }
  const lastPos = url.length - 1;
  if (url[lastPos] === '/') {
    return url.substring(0, lastPos);
  }
  return url;
};
