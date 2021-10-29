//@flow

import React from 'react';
import { MuiThemeProvider, createMuiTheme } from '@material-ui/core/styles';
import { getFontSize } from 'src/utils/browser';

type Props = {
  htmlFontSize: number,
  zIndexBase: number,
  children: any,
  fontFamily?: string
};

class AppTheme extends React.PureComponent<Props> {
  static displayName = 'AppTheme';

  static defaultProps = {
    htmlFontSize: getFontSize(),
    zIndexBase: 17000000,
  }

  render() {
    const { zIndexBase, children, htmlFontSize, fontFamily } = this.props;
    const theme = createMuiTheme({
      typography: {
        htmlFontSize: htmlFontSize,
        useNextVariants: true,
        fontFamily
      },
      zIndex: {
        mobileStepper: zIndexBase + 1000,
        appBar: zIndexBase + 1100,
        drawer: zIndexBase + 1200,
        modal: zIndexBase + 1300,
        snackbar: zIndexBase + 1400,
        tooltip: zIndexBase + 1500,
      }
    });
    return (
      <MuiThemeProvider theme={theme}>
        <div className="avarda-reset">
          { children }
        </div>
      </MuiThemeProvider>
    );
  }
}

export default AppTheme;
