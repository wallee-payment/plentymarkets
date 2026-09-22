/**
 * wallee Client Plugin
 * Extends to use custom API endpoints
 * 
 * This goes to "apps/server/middleware.config.ts"
 */

// ...
const config = {

  // ...

  integrations: {
    plentysystems: {
      // ...
      // Extend with wallee endpoints seen below
      // ...
      extensions: (extensions: any) => [
        ...extensions,
        {
          name: 'wallee',
          extendApiMethods: {
            // walleeRegisterReturnContext sends PWA url to main shop to save it in session
            walleeRegisterReturnContext: async (
              context: any,
              params: { originUrl: string; lang: string }
            ) => {
              const url = `${process.env.API_ENDPOINT}/rest/storefront/wallee/register-return`;
              const { data } = await context.client.post(
                url,
                { originUrl: params.originUrl, lang: params.lang },
                { 
                  headers: { cookie: context.req?.headers?.cookie || '' }
                }
              );
              return data;
            },
            // walleeRestoreCart restores cart in main shop
            walleeRestoreCart: async (
              context: any,
              params: { orderId: string },
            ) => {
              const url = `${process.env.API_ENDPOINT}/rest/storefront/wallee/restore-cart`;
              const { data } = await context.client.post(
                url, { orderId: params.orderId },
                {
                  headers: { cookie: context.req?.headers?.cookie || '' },
                },
              );
              return data;
            },
            // walleeGetOrderCheckoutData fetches order retry eligibility and available payment methods
            walleeGetOrderCheckoutData: async (
              context: any,
              params: { orderId: string; accessKey?: string },
            ) => {
              const query = new URLSearchParams({ orderId: params.orderId });
              // accessKey is required by the access-key-guarded retry endpoint on the shop
              if (params.accessKey) {
                query.set('accessKey', params.accessKey);
              }
              const url = `${process.env.API_ENDPOINT}/rest/storefront/wallee/order-checkout-data?${query.toString()}`;
              const { data } = await context.client.get(
                url,
                {
                  headers: { cookie: context.req?.headers?.cookie || '' },
                },
              );
              return data;
            },
            // walleePayOrderRest submits a payment retry for an existing unpaid order
            walleePayOrderRest: async (
              context: any,
              params: { orderId: string; paymentMethodId: string; accessKey?: string },
            ) => {
              const url = `${process.env.API_ENDPOINT}/rest/storefront/wallee/pay-order`;
              const { data } = await context.client.post(
                url,
                {
                  orderId: params.orderId,
                  paymentMethodId: params.paymentMethodId,
                  // accessKey is required by the access-key-guarded retry endpoint on the shop
                  accessKey: params.accessKey ?? '',
                },
                {
                  headers: { cookie: context.req?.headers?.cookie || '' },
                },
              );
              return data;
            },
            // walleeGetTransactionFailure fetches the user-facing decline message for a failed transaction
            walleeGetTransactionFailure: async (
              context: any,
              params: { transactionId: string },
            ) => {
              const url = `${process.env.API_ENDPOINT}/rest/storefront/wallee/transaction-failure/${params.transactionId}`;
              const { data } = await context.client.get(
                url,
                {
                  headers: { cookie: context.req?.headers?.cookie || '' },
                },
              );
              return data;
            },
          },
        },
      ],
    },
  },
};

// ...
