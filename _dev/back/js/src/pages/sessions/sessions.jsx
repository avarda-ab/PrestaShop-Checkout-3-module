// @flow
import type { ComponentType } from 'react';
import type { SessionType, GoTo } from 'src/types';
import classnames from 'classnames';
import React from 'react';
import Section from 'src/components/section/section';
import { withStyles } from '@material-ui/core/styles';
import Table from '@material-ui/core/Table';
import TableBody from '@material-ui/core/TableBody';
import TableCell from '@material-ui/core/TableCell';
import TableHead from '@material-ui/core/TableHead';
import TableRow from '@material-ui/core/TableRow';
import TablePagination from '@material-ui/core/TablePagination';
import moment from 'moment';
import { sessionsPage } from 'src/routing';

export type Props = {
  goTo: GoTo,
  page: number,
  loading: boolean,
  total: number,
  sessions: Array<SessionType>,
};

const styles = theme => ({
  root: {
  },
  message: {
    paddingTop: theme.spacing(4),
    paddingBottom: theme.spacing(8),
    textAlign: 'center',
    fontSize: 16,
    color: 'rgb(173, 173, 173)',
  },
  table: {
    marginBottom: theme.spacing(4),
    width: '100%'
  },
  row: {
    cursor: 'pointer'
  },
  small: {
    width: 140
  },
  session: {
    width: 250,
  },
  loading: {
    opacity: 0.5
  },
  link: {
    color: theme.palette.text.primary,
    borderBottom: '1px dashed ' + theme.palette.text.primary,
    textDecoration: 'none',
    '&:hover': {
      color: theme.palette.text.secondary,
      borderBottom: '1px dashed ' + theme.palette.text.secondary,
    }
  }
});

type State = {
  popup: ?SessionType,
  delete: ?true,
}

class SessionsPage extends React.PureComponent<Props & {classes:any}, State> {
  static displayName = 'SessionsPage';

  state = {
    popup: null,
    delete: null,
  }

  render() {
    const { classes, loading, total } = this.props;
    let content;
    if (loading && !total) {
      content = this.renderMessage(__('Loading data, please wait...'));
    } else if (!loading && !total) {
      content = this.renderMessage(__('No session sessions were found'));
    } else {
      content = this.renderTable();
    }
    const label = total ? __('Checkout sessions (%s)', total) : __('Checkout sessions');
    return (
      <div className={classes.root}>
        <Section id="sessions" label={label} indent={false}>
          { content }
        </Section>
      </div>
    );
  }

  renderTable = () => {
    const { loading, classes, page, total, sessions, goTo } = this.props;
    return (
      <div>
        <Table className={classnames(classes.table, { [classes.loading]: loading })}>
          <TableHead>
            <TableRow>
              <TableCell className={classes.small}>{__('Date')}</TableCell>
              <TableCell>{__('Session')}</TableCell>
              <TableCell>{__('Cart')}</TableCell>
              <TableCell>{__('Customer')}</TableCell>
              <TableCell>{__('Order')}</TableCell>
              <TableCell>{__('Status')}</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {sessions.map(session => (
              <TableRow className={classes.row} key={session.code}>
                <TableCell className={classes.small}>{moment(session.date).format('YYYY-MM-DD HH:mm:ss')}</TableCell>
                <TableCell className={classes.session}>{session.code}</TableCell>
                <TableCell>
                  <a className={classes.link} href={session.cartUrl}>#{session.cartId}</a>
                </TableCell>
                <TableCell>
                  <a className={classes.link} href={session.customerUrl}>{session.customerName}</a>
                </TableCell>
                <TableCell>
                  {session.orderId > 0 ? <a className={classes.link} href={session.orderUrl}>{session.orderReference}</a> : ''}
                </TableCell>
                <TableCell>
                  {session.status}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
        <TablePagination
          component="div"
          count={total}
          rowsPerPage={10}
          page={page-1}
          rowsPerPageOptions={[10]}
          backIconButtonProps={{
            'aria-label': __('Previous Page')
          }}
          nextIconButtonProps={{
            'aria-label': __('Next Page'),
          }}
          onChangePage={(e, page) => goTo(sessionsPage(page + 1))}
        />
      </div>
    );
  }

  renderMessage = (msg: string) => (
    <div className={this.props.classes.message}>
      {msg}
    </div>
  );

}

const component:ComponentType<Props> = withStyles(styles)(SessionsPage);
export default component;
