// @flow
import React from 'react';
import type { ComponentType } from 'react';
import type { GoTo, RoutingState } from 'src/types';
import Tabs from '@material-ui/core/Tabs';
import Tab from '@material-ui/core/Tab';
import { settingsPage, sessionsPage } from 'src/routing';
import { withStyles } from '@material-ui/core/styles';

type Props = {
  routingState: RoutingState,
  goTo: GoTo,
};

const styles = theme => ({
  root: {
    display: 'flex',
    marginBottom: 30
  },
  tab: {
    position: 'relative',
  },
  left: {
    flexGrow: 1
  },
  overflow: {
    overflow: 'visible'
  },
});

class Navigation extends React.PureComponent<Props & { classes: any }> {
  static displayName = 'Navigation';

  render() {
    const { classes, routingState } = this.props;
    const selected = routingState.type;
    return (
      <div className={classes.root}>
        <div className={classes.left}>
          <Tabs value={selected} onChange={this.onChangeTab}>
            <Tab value='settings' label={__("Settings")} />
            <Tab value='sessions' label={__("Sessions")} />
          </Tabs>
        </div>
      </div>
    );
  }

  onChangeTab = (e: any, value: string) => {
    const { goTo } = this.props;
    switch (value) {
      case 'settings':
        goTo(settingsPage());
        break;
      case 'sessions':
        goTo(sessionsPage());
        break;
    }
  }
}

const component:ComponentType<Props> = withStyles(styles)(Navigation);
export default component;
