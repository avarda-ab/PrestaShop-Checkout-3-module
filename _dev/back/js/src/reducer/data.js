// @flow
import type { InitDataType, KeyValue } from 'src/types';
import type { Action } from 'src/actions';

export type State = {
  statuses: KeyValue<string>
}

export default (data: InitDataType) => {
  return (state?: State, action:Action): State => {
    state = state || {
      statuses: data.statuses
    };
    return state;
  };
};
