// @flow
import type { Action } from 'src/actions';
import type { SessionType } from 'src/types';
import Types from 'src/actions/types';

export type State = {
  loading: boolean,
  total: number,
  sessions: Array<SessionType>
}

const defaultState: State = {
  loading: false,
  total: 0,
  sessions: []
};

export default (state?: State, action:Action): State => {
  state = state || defaultState;

  if (action.type === Types.getSessions) {
    return { ...state, loading: true };
  }

  if (action.type === Types.setSessions) {
    return {
      loading: false,
      total: action.total,
      sessions: action.sessions,
    };
  }

  return state;
};
