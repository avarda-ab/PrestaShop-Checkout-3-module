// @flow
import type { InitDataType } from 'src/types';
import React from 'react';
import { render } from 'react-dom';
import { equals } from 'ramda';
import { createStore, applyMiddleware } from 'redux';
import { Provider } from 'react-redux';
import logger from 'redux-logger';
import createReducer from 'src/reducer';
import createCommands from 'src/commands';
import { setSize, goTo } from 'src/actions/creators';
import { transition, toState, fixUrl, toUrl, settingsPage } from 'src/routing';
import { setTranslation } from 'translations';
import { createHashHistory } from 'history';
import elementResizeDetectorMaker from 'element-resize-detector';
import Types from 'src/actions/types';
import App from 'src/pages/app';

const syncHistory = history => store => next => action => {
  let currentState = null;
  if (action.type === Types.goTo) {
    currentState = store.getState().routingState;
  }
  const result = next(action);
  if (action.type === Types.goTo) {
    transition(currentState, action.routingState, store);

    if (action.updateHistory) {
      const newUrl = toUrl(action.routingState);
      if (newUrl != fixUrl(history.location.pathname)) {
        history.push(newUrl);
      }
    }
  }
  return result;
};

const watchElementSize = (node, store) => {
  let lastWidth = null;
  let lastHeight = null;
  const updateSize = (element) => {
    const width = Math.round(element.offsetWidth);
    const height = Math.round(element.offsetHeight);
    if (width != lastWidth || height != lastHeight) {
      lastWidth = width;
      lastHeight = height;
      store.dispatch(setSize(width, height));
    }
  };

  updateSize(node);
  const resizeDetector = elementResizeDetectorMaker({
    strategy: "scroll"
  });
  resizeDetector.listenTo(node, updateSize);
};

const runApp = (init: InitDataType, node: HTMLElement, dev: boolean) => {
  setTranslation(init.translations);

  const history = createHashHistory({
    queryKey: false
  });

  const commandsMiddleware = createCommands(init);
  const middlewares = [
    commandsMiddleware,
    syncHistory(history)
  ];
  if (dev) {
    console.info("Init data:", init);
    middlewares.push(logger);
  }

  let routingState = toState(fixUrl(history.location.pathname));
  if (! routingState) {
    routingState = settingsPage();
    history.replace(toUrl(routingState));
  }

  const reducer = createReducer(init, routingState);
  const store = createStore(reducer, applyMiddleware(...middlewares));
  watchElementSize(node, store);

  transition(null, routingState, store);
  history.listen((location, action) => {
    const newState = toState(fixUrl(history.location.pathname));
    if (! newState) {
      store.dispatch(goTo(settingsPage()));
    } else {
      const currentState = store.getState().routingState;
      if (! equals(newState, currentState)) {
        store.dispatch(goTo(newState));
      }
    }
  });

  render((
    <Provider store={store}>
      <App />
    </Provider>
  ), node);
};

const initFailed = (input: any, e: Error, node: HTMLElement) => {
  render((
    <div className="bootstrap">
      <h2>Failed to parse input parameters</h2>
      <div>
        <div className="alert alert-danger">
          {e.message}
        </div>
        <pre>
          {JSON.stringify(input, null, 2)}
        </pre>
      </div>
    </div>
  ), node);
};

window.startAvarda = (init: any) => {
  const content = document.getElementById('content');
  if (content) {
    content.className = 'app-content';
  }
  const dev = process.env.NODE_ENV !== 'production';
  const node = document.getElementById('avarda-app');
  if (! node) {
    throw new Error('Element avarda-app not found');
  }

  try {
    runApp(init, node, dev);
  } catch (e) {
    initFailed(init, e, node);
  }
};
