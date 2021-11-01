// @flow
import type { ComponentType } from 'react';
import type { State } from 'src/reducer';
import type { GoTo, RoutingState } from 'src/types';
import type { Props } from './app';
import Component from './app';
import { connect } from 'react-redux';
import { goTo } from 'src/actions/creators';

type OwnProps = {
  routingState: RoutingState,
}

type Actions = {
  goTo: GoTo
}

type PassedProps = {
}

const mapStateToProps = (state: State): OwnProps => ({
  routingState: state.routingState
});

const actions = {
  goTo
};

const merge = (props: OwnProps, actions: Actions, passed: PassedProps): Props => ({
  ...props,
  ...actions,
  ...passed
});


const connectRedux = connect(mapStateToProps, actions, merge);
const ConnectedComponent: ComponentType<PassedProps> = connectRedux(Component);

export default ConnectedComponent;
