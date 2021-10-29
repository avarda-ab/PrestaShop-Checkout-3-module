// @flow

import type { Api } from 'src/types';
import type { GetSessionsAction } from 'src/actions';
import { setSnackbar, setSessions } from 'src/actions/creators';

export default (action: GetSessionsAction, store: any, api: Api) => {
  api('getSessions', { page: action.page })
    .then(payload => {
      const sessions = payload.sessions;
      const total = payload.total;
      store.dispatch(setSessions(total, sessions));
    })
    .catch((e) => {
      console.error(e);
      store.dispatch(setSnackbar(__('Failed to load sessions')));
    });
};
