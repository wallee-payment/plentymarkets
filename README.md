

# wallee PlentyONE
This repository contains the PlentyONE extension that enables to process payments with [wallee](https://www.wallee.com/).

###### To use this extension, a [wallee](https://www.wallee.com/) account is required.

## Requirements

* [PlentyONE 7](https://www.plentyone.com/)
* [IO](https://marketplace.plentyone.com/plugins/channels/online-shops/io_4696)
* [Ceres](https://marketplace.plentyone.com/plugins/channels/online-shops/ceres_4697)

## Documentation

* [Documentation](https://plugin-documentation.wallee.com/wallee-payment/plentymarkets/2.2.0/docs/en/documentation.html)

## PWA Installation
For using the [PlentyONE PWA](https://github.com/plentymarkets/plentyshop-pwa), you'll need to add these files in the PWA project:

*   **Vue Page**: `resources/js/pages/payment-selection/` → `apps/web/app/pages/payment-selection/`
*   **Client Plugin**: `resources/js/wallee.client.ts` → `apps/web/app/plugins/wallee.client.ts`
*   **Middleware**: Update `apps/server/middleware.config.ts` using snippets from `resources/js/middleware.config.ts`.

## License

Please see the [license file](https://github.com/wallee-payment/plentymarkets/blob/2.2.0/LICENSE) for more information.