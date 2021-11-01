// @flow
import type { InitDataType, SettingsType } from 'src/types';
import type { Action } from 'src/actions';
import Types from 'src/actions/types';

export type State = {
  working: SettingsType,
  saved: SettingsType,
}

export default (data: InitDataType) => {
  return (state?: State, action:Action): State => {
    state = state || {
      working: data.settings,
      saved: data.settings
    };

    if (action.type === Types.setSettings) {
      return { ...state, working: action.settings };
    }

    if (action.type === Types.setSettingsSuccess) {
      return {
        working: action.settings,
        saved: action.settings
      };
    }

    return state;
  };
};
