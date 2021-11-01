// @flow
import type { InitDataType } from 'src/types';
import Types from 'src/actions/types';
import createApiService from 'src/services/api';
import setSettings  from './set-settings';
import testCredentials  from './test-credentials';
import getSessions  from './get-sessions';
import { fixUrl } from 'src/utils/url';

const commands = {
  [ Types.setSettings ]: setSettings,
  [ Types.testCredentials ]: testCredentials,
  [ Types.getSessions ]: getSessions,
};

export default (data: InitDataType) => {
  const apiService = createApiService(fixUrl(data.apiUrl), []);
  const getApi = (cmd: string) => {
    return apiService;
  };

  return (store: any) => (next: any) => (action: any) => {
    const res = next(action);
    const command = commands[action.type];
    if (command) {
      command(action, store, getApi(action.type));
    }
    return res;
  };
};
