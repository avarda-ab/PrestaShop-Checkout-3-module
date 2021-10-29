// @flow
import type { RoutingState } from 'src/routing';
import type { SettingsType, ModeType, SessionType } from 'src/types';

export type SetSnackbarAction = {
  type: 'SET_SNACKBAR',
  message: ?string
}

export type GoToAction = {
  type: 'GO_TO',
  routingState: RoutingState,
  updateHistory: boolean
};

export type SetSizeAction = {
  type: 'SET_SIZE',
  width: number,
  height: number
}

export type SetSettingsAction = {
  type: 'SET_SETTINGS',
  settings: SettingsType,
  save: boolean,
}

export type SetSettingsSuccessAction = {
  type: 'SET_SETTINGS_SUCCESS',
  settings: SettingsType
};

export type SetSettingsFailedAction = {
  type: 'SET_SETTINGS_FAILED'
}

export type TestCredentialsAction = {
  type: 'TEST_CREDENTIALS',
  mode: ModeType,
  code: string,
  password: string,
}

export type GetSessionsAction = {
  type: 'GET_SESSIONS',
  page: number
}

export type SetSessionsAction = {
  type: 'SET_SESSIONS',
  total: number,
  sessions: SessionType[]
}

export type Action = (
  TestCredentialsAction |
  SetSizeAction |
  SetSnackbarAction |
  GoToAction |
  SetSettingsAction |
  SetSettingsSuccessAction |
  SetSettingsFailedAction |
  SetSessionsAction |
  GetSessionsAction
);
