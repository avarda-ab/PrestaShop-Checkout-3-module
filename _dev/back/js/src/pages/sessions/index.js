// @flow
import type { ComponentType } from 'react';
import type { State } from 'src/reducer';
import type { Props } from './sessions';
import type { SessionType, GoTo } from 'src/types';
import Component from './sessions';
import { goTo } from 'src/actions/creators';
import { connect } from 'react-redux';

type OwnProps = {
  loading: boolean,
  total: number,
  sessions: Array<SessionType>
}

type Actions = {
  goTo: GoTo,
}

type PassedProps = {
  page: number
}

const mapStateToProps = (state: State): OwnProps => ({
  ...state.sessions
});

const actions = {
  goTo,
};

const merge = (props: OwnProps, actions: Actions, passed: PassedProps): Props => ({
  ...props,
  ...actions,
  ...passed
});

const connectRedux = connect(mapStateToProps, actions, merge);
const ConnectedComponent: ComponentType<PassedProps> = connectRedux(Component);

export default ConnectedComponent;
