// @flow
import type { RouteDefinition } from 'src/types';
import Settings from 'src/pages/settings';

export type SettingsPage = {
  type: 'settings'
}

const toUrl = (settings: SettingsPage) => {
  return '/settings';
};

const toState = (url: string): ?SettingsPage => {
  if (url === '/settings') {
    return settingsPage();
  }
};

export const settingsPage = ():SettingsPage => ({
  type: 'settings'
});

export const settingsRoute: RouteDefinition<SettingsPage> = {
  toUrl,
  toState,
  component: Settings
};
