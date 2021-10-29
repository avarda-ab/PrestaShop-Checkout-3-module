// @flow

import type { Action } from 'src/actions';
import type { RoutingState } from 'src/routing';
import Types from 'src/actions/types';

export type State = RoutingState;

export default (initialState: RoutingState) => (state: ?RoutingState, action: Action): State => {
  const curState = state || initialState;
  if (action.type === Types.goTo) {
    return action.routingState;
  }
  return curState;
};
