// @flow
import React from 'react';
import type { ComponentType } from 'react';
import type { GoTo, RoutingState } from 'src/types';
import Snackbar from 'src/components/snackbar';
import AppTheme from 'src/components/theme/theme';
import { render } from 'src/routing';
import Navigation from './navigation';
import { withStyles } from '@material-ui/core/styles';

export type Props = {
  routingState: RoutingState,
  goTo: GoTo,
};

const styles = theme => ({
  app: {
    paddingTop: 20,
  }
});

class BackApp extends React.PureComponent<Props & { classes: any }> {
  static displayName = 'BackApp';

  render() {
    const { routingState, classes } = this.props;
    const snackbarPosition = { vertical: 'bottom', horizontal: 'right' };
    return (
      <AppTheme>
        <div className={classes.app}>
          { this.renderNavigation(routingState) }
          { render(routingState) }
          <Snackbar anchorOrigin={snackbarPosition} />
        </div>
      </AppTheme>
    );
  }

  renderNavigation = (routingState: RoutingState) => {
    return (
      <Navigation
        routingState={routingState}
        goTo={this.props.goTo}
      />
    );
  }
}


const component:ComponentType<Props> = withStyles(styles)(BackApp);
export default component;
