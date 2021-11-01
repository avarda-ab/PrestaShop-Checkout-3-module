// @flow
import { combineReducers } from 'redux';
import type { InitDataType } from 'src/types';
import type { RoutingState } from 'src/routing';
import type { State as StateSettings } from './settings';
import type { State as StateRouting } from './routing-state';
import type { State as StateSnackbar } from './snackbar';
import type { State as StateData } from './data';
import type { State as StateSessions } from './sessions';

import createRoutingState from './routing-state';
import createSettings from './settings';
import createData from './data';
import snackbar from './snackbar';
import sessions from './sessions';

export type State = {
  data: StateData,
  routingState: StateRouting,
  settings: StateSettings,
  snackbar: StateSnackbar,
  sessions: StateSessions,
}

export default (init: InitDataType, route: RoutingState) => combineReducers({
  routingState: createRoutingState(route),
  settings: createSettings(init),
  data: createData(init),
  sessions,
  snackbar,
});
