// @flow

import type { Api } from 'src/types';
import type { SetSettingsAction } from 'src/actions';
import { setSettingsSuccess, setSettingsFailed, setSnackbar } from 'src/actions/creators';

export default (action: SetSettingsAction, store: any, api: Api) => {
  if (action.save) {
    const settings = action.settings;
    api('saveSettings', { settings })
      .then(() => {
        store.dispatch(setSettingsSuccess(settings));
        store.dispatch(setSnackbar(__('Settings has been saved')));
      })
      .catch(() => store.dispatch(setSettingsFailed()));
  }
};
