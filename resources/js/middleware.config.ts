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
              params: { orderId: string }
            ) => {
              const url = `${process.env.API_ENDPOINT}/rest/storefront/wallee/restore-cart`;
              const { data } = await context.client.post(
                url, { orderId: params.orderId },
                {
                  headers: { cookie: context.req?.headers?.cookie || '' }
                }
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
