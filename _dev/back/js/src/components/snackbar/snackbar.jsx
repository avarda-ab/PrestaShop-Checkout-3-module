// @flow
import React from 'react';
import Button from '@material-ui/core/Button';
import Snackbar from '@material-ui/core/Snackbar';

export type Props = {
  message: ?string,
  setSnackbar: (?string) => void,
};

class AppSnackbar extends React.PureComponent<Props> {
  static displayName = 'AppSnackbar';

  render() {
    const { message } = this.props;
    const anchorOrigin = {
      vertical: 'bottom',
      horizontal: 'right'
    };
    return (
      <Snackbar
        anchorOrigin={anchorOrigin}
        open={!!message}
        autoHideDuration={3000}
        onClose={this.onClose}
        message={message || ' '}
        action={[
          <Button key="close" color="secondary" onClick={this.onClose}>
            {__('Close')}
          </Button>
        ]} />
    );
  }

  onClose = (e: Event, reason: ?string) => {
    if (reason != 'clickaway') {
      this.props.setSnackbar(null);
    }
  }
}

export default AppSnackbar;
