// @flow
import type { ComponentType } from 'react';
import type { SettingsType, CredentialsType, KeyValue, ModeType } from 'src/types';
import { map, toPairs, assocPath, equals } from 'ramda';
import React from 'react';
import Section from 'src/components/section/section';
import PageWithFooter from 'src/components/page-with-footer/page-with-footer';
import InputLabel from '@material-ui/core/InputLabel';
import MenuItem from '@material-ui/core/MenuItem';
import FormControl from '@material-ui/core/FormControl';
import Select from '@material-ui/core/Select';
import IconButton from '@material-ui/core/IconButton';
import Input from '@material-ui/core/Input';
import InputAdornment from '@material-ui/core/InputAdornment';
import TextField from '@material-ui/core/TextField';
import Visibility from '@material-ui/icons/Visibility';
import VisibilityOff from '@material-ui/icons/VisibilityOff';
import Button from '@material-ui/core/Button';
import { withStyles } from '@material-ui/core/styles';

export type Props = {
  settings: SettingsType,
  originalSettings: SettingsType,
  statuses: KeyValue<string>,
  setSettings: (SettingsType, boolean) => void,
  testCredentials: (ModeType, string, string) => void,
};

export type State = {
  showPassword: boolean
}

const styles = theme => ({
  root: {
  },
  buttons: {
    marginTop: theme.spacing(4)
  },
  mode: {
    width: 300
  },
  textField: {
    marginTop: theme.spacing(2),
    width: 450
  },
  note: {
    marginTop: theme.spacing(2),
    marginBottom: theme.spacing(2),
    fontSize: '90%',
    color: '#999'
  },
  status: {
    width: 300,
  },
  statusNote: {
    color: '#999',
    fontSize: '90%',
    marginTop: theme.spacing(1),
    marginBottom: theme.spacing(4),
  },
  footerContent: {
    float: 'right'
  },
  footerButton: {
    marginLeft: theme.spacing(2),
    float: 'left',
    marginTop: 10
  }
});

class SettingsPage extends React.PureComponent<Props & {classes:any}, State> {
  static displayName = 'SettingsPage';

  state = {
    showPassword: false
  }

  render() {
    const { originalSettings, settings } = this.props;
    const modified = !equals(originalSettings, settings);
    return (
      <PageWithFooter
        content={this.renderContent()}
        footer={this.renderFooter()}
        showFooter={modified} />
    );
  }

  renderContent = () => {
    const { classes, settings, statuses } = this.props;
    return (
      <div className={classes.root}>
        <Section id="update" label={__('API credentials')} indent={false}>
          <FormControl className={classes.mode}>
            <InputLabel>{__('Mode')}</InputLabel>
            <Select value={settings.mode} onChange={this.changeMode}>
              <MenuItem value={'test'}>{__('Test mode')}</MenuItem>
              <MenuItem value={'production'}>{__('Production mode')}</MenuItem>
            </Select>
          </FormControl>
          <div className={classes.note}>
            {settings.mode === 'test' && __('Test mode allows you to verify avarda integration against staging environment')}
            {settings.mode === 'production' && __('Be aware, you are in production mode')}
          </div>
          {this.renderCredentials(settings.mode, settings.credentials[settings.mode])}
        </Section>

        <Section id="status" label={__('Order statuses')} indent={false}>
          <FormControl className={classes.status}>
            <InputLabel>{__('Completed status')}</InputLabel>
            <Select value={settings.completedStatus} onChange={this.changeCompletedStatus}>
              { map(this.renderStatus, toPairs(statuses)) }
            </Select>
          </FormControl>
          <div className={classes.statusNote}>
            {__('Order status when payment has been successfully completed')}
          </div>

          <FormControl className={classes.status}>
            <InputLabel>{__('Delivery status')}</InputLabel>
            <Select value={settings.deliveryStatus} onChange={this.changeDeliveryStatus}>
              { map(this.renderStatus, toPairs(statuses)) }
            </Select>
          </FormControl>
          <div className={classes.statusNote}>
            {__('When order transition to this status, avarda Purchase Order will be created')}
          </div>
        </Section>
      </div>
    );
  }

  renderStatus = (pair: [number, string]) => (
    <MenuItem key={pair[0]} value={pair[0]}>{pair[1]}</MenuItem>
  );

  renderCredentials = (mode: ModeType, credentials: CredentialsType) => {
    const { classes, testCredentials } = this.props;
    const { showPassword } = this.state;
    const valid = credentials.code && credentials.password;
    return (
      <div>
        <div>
          <TextField
            value={credentials.code}
            id="credentials-code"
            onChange={e => this.changeCredentials(mode, 'code', e.target.value)}
            label={__('Username')}
            placeholder={__('Please enter your username')}
            className={classes.textField}
            fullWidth
          />
        </div>
        <div>
          <FormControl className={classes.textField}>
            <InputLabel htmlFor="adornment-password">Password</InputLabel>
            <Input
              id="adornment-password"
              autoComplete="new-password"
              type={showPassword ? 'text' : 'password'}
              value={credentials.password}
              onChange={e => this.changeCredentials(mode, 'password', e.target.value)}
              endAdornment={
                <InputAdornment position="end">
                  <IconButton aria-label="Toggle password visibility" onClick={e => this.setState({ showPassword: !showPassword})}>
                    { showPassword ? <Visibility /> : <VisibilityOff /> }
                  </IconButton>
                </InputAdornment>
              }
            />
          </FormControl>
        </div>
        <div className={classes.buttons}>
          <Button disabled={! valid} color='primary' onClick={() => testCredentials(mode, credentials.code, credentials.password)}>
            {__('Test credentials')}
          </Button>
        </div>
      </div>
    );
  };

  renderFooter = () => {
    const { setSettings, classes, settings, originalSettings } = this.props;
    const same = equals(originalSettings, settings);
    const credentials = settings.credentials[settings.mode];
    const valid = credentials.code && credentials.password;
    return (
      <div className={classes.footerContent}>
        <Button className={classes.footerButton} onClick={() => setSettings(originalSettings, false)}>
          {__('Cancel')}
        </Button>
        <Button disabled={!valid || same} className={classes.footerButton} variant="contained" color="secondary" onClick={() => setSettings(settings, true)}>
          {__('Save changes')}
        </Button>
      </div>
    );
  }

  changeMode = (e) => {
    const { settings, setSettings } = this.props;
    setSettings({...settings, mode: e.target.value}, false);
  }

  changeCompletedStatus = (e) => {
    const { settings, setSettings } = this.props;
    setSettings({...settings, completedStatus: parseInt(e.target.value, 10)}, false);
  }

  changeDeliveryStatus = (e) => {
    const { settings, setSettings } = this.props;
    setSettings({...settings, deliveryStatus: parseInt(e.target.value, 10)}, false);
  }

  changeCredentials = (mode, type, value) => {
    const { settings, setSettings } = this.props;
    const credentials = assocPath([mode, type], value, settings.credentials);
    setSettings({...settings, credentials}, false);
  }
}

const component:ComponentType<Props> = withStyles(styles)(SettingsPage);
export default component;
