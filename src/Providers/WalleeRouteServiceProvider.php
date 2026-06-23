<?php

declare(strict_types=1);

namespace Wallee\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\Router;

/**
 * Class WalleeRouteServiceProvider
 *
 * Registers the routing rules for the Wallee plugin, including webhook listeners
 * and storefront/checkout-related routes.
 */
class WalleeRouteServiceProvider extends RouteServiceProvider
{
    /**
     * Map the routes.
     *
     * @param Router $router The router instance.
     * @return void
     */
    public function map(Router $router): void
    {
        // Define local variables for repeated route prefixes, controller namespaces,
        // and webhook endpoints to simplify maintenance and avoid redundancy.
        $defaultPrefix = 'wallee/';
        $notificationController = 'Wallee\Controllers\PaymentNotificationController@';
        $processController = 'Wallee\Controllers\PaymentProcessController@';
        $storefrontPrefix = 'rest/storefront/wallee/';
        $transactionController = 'Wallee\Controllers\PaymentTransactionController@';
        $webhookEndpoints = [
            'update-transaction',
        ];
        $webhookPrefix = 'rest/v1/wallee/';

        // Register webhook endpoints dynamically to make it easier to add new listeners.
        // We define both slash and non-slash patterns to prevent router mismatches
        // caused by Plentymarkets trailing slash configurations.
        foreach ($webhookEndpoints as $endpoint) {
            $router->post(
                $webhookPrefix . $endpoint,
                $notificationController . 'updateTransaction',
            );
            $router->post(
                $webhookPrefix . $endpoint . '/',
                $notificationController . 'updateTransaction',
            );
        }

        $router->get($defaultPrefix . 'fail-transaction/{id}', $processController . 'failTransaction')->where('id', '\d+');
        $router->post($defaultPrefix . 'pay-order', $processController . 'payOrder');
        $router->get($defaultPrefix . 'download-invoice/{id}', $transactionController . 'downloadInvoice')->where('id', '\d+');
        $router->get($defaultPrefix . 'download-packing-slip/{id}', $transactionController . 'downloadPackingSlip')->where('id', '\d+');
        $router->get($defaultPrefix . 'redirect-check', $processController . 'redirectCheck');
        $router->get($defaultPrefix . 'return-failed/{id}', $processController . 'returnFailed')->where('id', '\d+');
        $router->post($storefrontPrefix . 'register-return', $processController . 'registerReturnContext');
        $router->post($storefrontPrefix . 'restore-cart', $processController . 'restoreCart');
        $router->get($storefrontPrefix . 'order-checkout-data', $processController . 'getOrderCheckoutData');
        $router->post($storefrontPrefix . 'pay-order', $processController . 'payOrderRest');
        $router->get($storefrontPrefix . 'transaction-failure/{id}', $processController . 'getTransactionFailure')->where('id', '\d+');
    }
}