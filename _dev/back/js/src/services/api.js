// @flow
import { contains } from 'ramda';
import type { ResponseType } from 'src/types';

const abortable = {};

const getUrl = (url: string) => {
  const rand = new Date().getTime();
  if (url.indexOf('?') >= 0) {
    return url + '&rand=' + rand;
  }
  return url + '?rand='+rand;
};

const getData = (avardaToken:?string, cmd: string, payload: any) => {
  if (avardaToken) {
    return {
      ...payload,
      action: 'command',
      cmd,
      avardaToken
    };
  } else {
    return {
      action: 'command',
      cmd,
      payload: JSON.stringify(payload).replace(/\\n/g, "\\\\n"),
      ajax: true,
    };
  }
};

export default (url: string, canAbort: Array<string>, avardaToken?: string) => (cmd: string, payload: any) => new Promise((resolve, reject) => {
  const failure = (error: string) => {
    if (abortable[cmd]) {
      abortable[cmd] = null;
    }
    if (error !== 'abort') {
      console.error("API call error: "+cmd+": "+error);
    }
    reject(new Error(error));
  };

  const success = (data: ResponseType) => {
    if (data.success) {
      if (abortable[cmd]) {
        abortable[cmd] = null;
      }
      resolve(data.result);
    } else {
      failure(data.error);
    }
  };

  const error = (xhr, error) => failure(error);

  if (abortable[cmd]) {
    abortable[cmd].abort();
    abortable[cmd] = null;
  }

  const request = window.$.ajax({
    url: getUrl(url),
    type: 'POST',
    dataType: 'json',
    headers: {'cache-control': 'no-cache'},
    data: getData(avardaToken, cmd, payload),
    success,
    error
  });

  if (contains(cmd, canAbort)) {
    abortable[cmd] = request;
  }
});
