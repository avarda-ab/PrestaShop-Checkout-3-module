// @flow

import type { Api } from 'src/types';
import type { TestCredentialsAction } from 'src/actions';
import { setSnackbar } from 'src/actions/creators';

export default (action: TestCredentialsAction, store: any, api: Api) => {
  const { mode, code, password } = action;
  api('testCredentials', { mode, code, password })
    .then(status => store.dispatch(setSnackbar(
      status
        ? __('Success - credentials are valid')
        : __('Error - invalid credentials')
    )));
};
