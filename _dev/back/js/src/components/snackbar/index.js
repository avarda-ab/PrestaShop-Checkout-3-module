// @flow
import type { ComponentType } from 'react';
import type { State } from 'src/reducer';
import type { Props } from './snackbar';
import Component from './snackbar';
import { setSnackbar } from 'src/actions/creators';
import { connect } from 'react-redux';

type OwnProps = {
  message: ?string,
}

type Actions = {
  setSnackbar: (?string)=>void
}

type PassedProps = {
}

const mapStateToProps = (state: State): OwnProps => ({
  message: state.snackbar.message
});

const actions = {
  setSnackbar
};

const merge = (props: OwnProps, actions: Actions, passed: PassedProps): Props => ({
  ...props,
  ...actions,
  ...passed
});

const connectRedux = connect(mapStateToProps, actions, merge);
const ConnectedComponent: ComponentType<PassedProps> = connectRedux(Component);

export default ConnectedComponent;
