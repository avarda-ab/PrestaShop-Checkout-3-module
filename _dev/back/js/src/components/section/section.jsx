// @flow
import type { ComponentType } from 'react';
import React from 'react';
import Paper from '@material-ui/core/Paper';
import { withStyles } from '@material-ui/core/styles';

type Props = {
  id: string,
  label: string,
  subheader?: ?string,
  children: any,
  indent?: boolean
};


const styles = theme => ({
  section: {
    padding: '0px 24px 30px 24px',
    marginBottom: '3rem',
  },
  sectionLabel: {
    margin: 0,
    fontWeight:500,
    minHeight: 64,
    color: '#666',
    fontSize: '1.5rem',
    display: 'flex',
    alignItems: 'center',
  },
  sectionContent: {
    marginTop: theme.spacing(1),
    paddingTop: theme.spacing(2),
    paddingBottom: theme.spacing(2),
    width: '100%',
    overflow: 'visible',
  },
  subheader: {
    fontSize: '120%',
    marginTop: theme.spacing(-0.5),
    lineHeight: theme.spacing(1.5),
    color: '#999',
  }
});

type AllProps = Props & { classes: any };

class SettingsSection extends React.PureComponent<AllProps> {

  static defaultProps = {
    indent: true
  }

  render() {
    const { classes, id, subheader, label, children, indent } = this.props;
    return (
      <Paper id={id} className={classes.section}>
        <h2 className={classes.sectionLabel}>{ label }</h2>
        {subheader && (
          <div className={classes.subheader}>
            {subheader}
          </div>
        )}
        <div className={indent ? classes.sectionContent : null}>
          { children }
        </div>
      </Paper>
    );
  }
}

const component:ComponentType<Props> = withStyles(styles)(SettingsSection);
export default component;
