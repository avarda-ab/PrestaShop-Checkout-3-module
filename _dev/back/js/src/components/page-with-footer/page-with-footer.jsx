// @flow
import React from 'react';
import classnames from 'classnames';
import { withStyles } from '@material-ui/core/styles';
import type { ComponentType } from 'react';

type Props = {
  content: any,
  footer: any,
  showFooter: boolean
};

class PageWithFooter extends React.PureComponent<Props & { classes: any }> {
  static displayName = 'PageWithFooter';

  render() {
    const { classes, content, footer, showFooter } = this.props;
    const clazz = classnames(classes.footer, {
      [ classes.open ]: showFooter
    });
    return (
      <div className={classes.root}>
        <div className={classes.content}>
          { content }
        </div>
        <div className={clazz}>
          <div className={classes.footerContent}>
            { footer }
          </div>
        </div>
      </div>
    );
  }
}

const styles = theme => ({
  root: {
    position: 'relative',
    paddingBottom: 60,
    width: '100%'
  },
  footer: {
    position: 'fixed',
    left: 0,
    width: '100%',
    lineHeight: 56,
    bottom: -120,
    transition: 'all 250ms linear',
    height: 56,
    boxShadow: 'rgba(0, 0, 0, 0.16) 0px -4px 8px',
    background: '#fff',
    opacity: 0,
  },
  footerContent: {
    paddingRight: 20,
  },
  open: {
    bottom: 0,
    opacity: 1,
    zIndex: 499,
  }
});

const component:ComponentType<Props> = withStyles(styles)(PageWithFooter);
export default component;
