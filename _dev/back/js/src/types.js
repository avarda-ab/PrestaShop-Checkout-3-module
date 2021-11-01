// @flow

// route definition
export type RouteDefinition<T> = {
  toUrl: (T) => string,
  toState: (string) => ?T,
  component: any,
  setup?: (T, any)=>void,
  update?: (T, T, any)=>void,
  teardown?: (T, any)=>void
};

export type KeyValue<T> = {
  [ string ]: T
}

export type Success = {
  success: true,
  result: any
}

export type Failure = {
  success: false,
  error: string
}

export type ResponseType = Failure | Success;

export type Api = (cmd: string, payload:{}) => Promise<any>;

export type { RoutingState, GoTo } from 'src/routing';

export type InitDataType = {
  apiUrl: string,
  settings: SettingsType,
  translations: KeyValue<string>,
  statuses: KeyValue<string>,
}

export type ModeType = 'test' | 'production';

export type SettingsType = {
  mode: ModeType,
  credentials: {
    test: CredentialsType,
    production: CredentialsType,
  },
  showCart: boolean,
  completedStatus: number,
  deliveryStatus: number,
}

export type CredentialsType = {
  code: string,
  password: string
}

export type SessionsStatusType = 'new' | 'processing' | 'error' | 'completed';

export type SessionType = {
  date: string,
  code: string,
  mode: ModeType,
  status: SessionsStatusType,
  orderId: number,
  orderReference: string,
  orderUrl: string,
  cartId: number,
  cartUrl: string,
  customerId: number,
  customerName: string,
  customerUrl: string,
}
