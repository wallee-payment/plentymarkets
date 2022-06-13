# Release Notes for wallee

## v2.0.20 (2021-06-21)

### Fixed
- SDK update

## v2.0.19 (2021-06-21)

### Fixed
- revert sdk update

## v2.0.17 (2021-06-21)

### Fixed
- Additional filter for orders, no longer than 3 months was added, so that IDs do not get duplicated.

## v2.0.16 (2021-02-26)

### Fixed
- Update documentation.

## v2.0.15 (2021-02-25)

### Fixed
- Update plugin.json.

## v2.0.14 (2021-02-16)

### Added
- Add setting to configure order statuses that allow to switch the payment method.

## v2.0.13 (2020-12-02)

### Fixed
- Allow longer line item names.

## v2.0.12 (2020-05-20)

### Fixed
- Fix translation key on payment failure page.

## v2.0.11 (2020-05-05)

### Fixed
- Fix a bug preventing the customer selecting a different payment method if the payment failed.

## v2.0.10 (2020-03-18)

### Fixed
- Prevent having multiple payments for one order.

## v2.0.9 (2020-02-10)

### Fixed
- Update SDK to latest version.
- Fix incorrect translation key.

## v2.0.8 (2019-12-05)

### Fixed
- Update SDK to latest version.

## v2.0.7 (2019-11-07)

### Fixed
- Fix bug in webhook processing.

## v2.0.6 (2019-09-05)

### Fixed
- Use the amounts in the order currency.

## v2.0.5 (2019-07-31)

### Added
- Set order item property values on line items.

## v2.0.4 (2019-07-04)

### Fixed
- Fix bug in line item calculation.

## v2.0.3 (2019-05-14)

### Fixed
- Fix refund processing.
- Ignore webhooks with links to non-existing entities.

## v2.0.2 (2019-04-18)

### Fixed
- Fix a bug leading to an error during refund.

## v2.0.1 (2019-04-08)

### Fixed
- Improve mapping of transaction states to payment status in plentymarkets store.

## v2.0.0 (2019-03-21)

### Added
- Allow customers to change payment method if a payment fails.
- Allow customers to download invoice document and packing slip from order confirmation page.

### Fixed
- Create order before redirecting customer to payment page.

## v1.0.23 (2019-03-01)

### Fixed
- Fix shipping tax calculation.

## v1.0.22 (2019-02-15)

### Fixed
- Fix shipping tax calculation.

## v1.0.21 (2019-02-15)

### Fixed
- Allow to refund partial amounts.
- Ensure correct transaction total.

## v1.0.20 (2019-01-16)

### Fixed
- Update state of refund payments.

## v1.0.19 (2018-12-12)

### Fixed
- Respect URL settings regarding trailing slashes.

## v1.0.18 (2018-12-07)

### Fixed
- Update logging levels.

## v1.0.17 (2018-11-30)

### Fixed
- Fix calculation of net basket amounts.

## v1.0.16 (2018-11-22)

### Fixed
- Fix line item price calculation.

## v1.0.15 (2018-11-07)

### Fixed
- Add logging to transaction failure controller.

## v1.0.14 (2018-10-23)

### Fixed
- Show reason for transaction failure to customer.

## v1.0.12 (2018-10-16)

### Fixed
- Create payment in plentymarkets for refunds.

## v1.0.11 (2018-10-15)

### Fixed
- Process notification through a cron job.

## v1.0.10 (2018-06-19)

### Fixed
- Fix bugs.

## v1.0.9 (2018-04-17)

### Fixed
- Add filter for invalid birthday values on addresses.
- Mark products as shippable.
- Pass the gender from plentymarkets.

## v1.0.8 (2018-04-16)

### Fixed
- Fix bugs.

## v1.0.7 (2018-03-08)

### Fixed
- Add compatibility to Ceres 2.4.0

## v1.0.6 (2018-01-30)

### Fixed
- Fix path of payment method images.

## v1.0.3 (2017-12-14)

### Fixed
- Add compatibility to Ceres 2.0.2

## v1.0.2 (2017-09-05)

### Added
- Updated Descirptions and Screenshots
- Updated URL for processing
