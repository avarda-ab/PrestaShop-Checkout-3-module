// @flow
import type { ComponentType } from 'react';
import type { State } from 'src/reducer';
import type { SettingsType, KeyValue, ModeType } from 'src/types';
import type { Props } from './settings';
import Component from './settings';
import { connect } from 'react-redux';
import { setSettings, testCredentials } from 'src/actions/creators';

type OwnProps = {
  settings: SettingsType,
  statuses: KeyValue<string>,
  originalSettings: SettingsType,
}

type Actions = {
  setSettings: (SettingsType, boolean) => void,
  testCredentials: (ModeType, string, string) => void,
}

type PassedProps = {
}

const mapStateToProps = (state: State): OwnProps => ({
  settings: state.settings.working,
  originalSettings: state.settings.saved,
  statuses: state.data.statuses,
});

const actions = {
  setSettings,
  testCredentials,
};

const merge = (props: OwnProps, actions: Actions, passed: PassedProps): Props => ({
  ...props,
  ...actions,
  ...passed
});

const connectRedux = connect(mapStateToProps, actions, merge);
const ConnectedComponent: ComponentType<PassedProps> = connectRedux(Component);

export default ConnectedComponent;
