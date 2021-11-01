// @flow
import type { SettingsType, RoutingState, ModeType, SessionType } from 'src/types';

import type {
  GoToAction,
  SetSnackbarAction,
  SetSizeAction,
  SetSettingsAction,
  SetSettingsSuccessAction,
  SetSettingsFailedAction,
  TestCredentialsAction,
  GetSessionsAction,
  SetSessionsAction,
} from './index';
import Types from './types';

export const goTo = (routingState: RoutingState, updateHistory?:boolean = true): GoToAction => ({ type: Types.goTo, routingState, updateHistory });
export const setSnackbar = (message: ?string): SetSnackbarAction => ({ type: Types.setSnackbar, message });
export const setSize = (width: number, height: number): SetSizeAction => ({ type: Types.setSize, width, height });

export const setSettings = (settings: SettingsType, save: boolean): SetSettingsAction => ({ type: Types.setSettings, settings, save });
export const setSettingsSuccess = (settings: SettingsType): SetSettingsSuccessAction => ({ type: Types.setSettingsSuccess, settings });
export const setSettingsFailed = (): SetSettingsFailedAction => ({ type: Types.setSettingsFailed });

export const testCredentials = (mode: ModeType, code: string, password: string): TestCredentialsAction => ({ type: Types.testCredentials, mode, code, password });

export const getSessions = (page: number): GetSessionsAction => ({ type: Types.getSessions, page });
export const setSessions = (total: number, sessions: SessionType[]): SetSessionsAction => ({ type: Types.setSessions, total, sessions });
