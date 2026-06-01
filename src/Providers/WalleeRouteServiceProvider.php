<?php
namespace Wallee\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\Router;

class WalleeRouteServiceProvider extends RouteServiceProvider
{

    /**
     *
     * @param Router $router
     */
    public function map(Router $router)
    {
        // Map Wallee webhook endpoints with the rest/v1 prefix.
        // We define both slash and non-slash patterns to prevent router mismatches 
        // caused by plentymarkets trailing slash configurations.
        $router->post(
            'rest/v1/wallee/update-transaction',
            'Wallee\Controllers\PaymentNotificationController@updateTransaction',
        );
        // $router->post(
        //     'rest/v1/wallee/update-transaction/',
        //     'Wallee\Controllers\PaymentNotificationController@updateTransaction',
        // );
        $router->get('wallee/fail-transaction/{id}', 'Wallee\Controllers\PaymentProcessController@failTransaction')->where('id', '\d+');
        $router->post('wallee/pay-order', 'Wallee\Controllers\PaymentProcessController@payOrder');
        $router->get('wallee/download-invoice/{id}', 'Wallee\Controllers\PaymentTransactionController@downloadInvoice')->where('id', '\d+');
        $router->get('wallee/download-packing-slip/{id}', 'Wallee\Controllers\PaymentTransactionController@downloadPackingSlip')->where('id', '\d+');
        $router->get('wallee/redirect-check', 'Wallee\Controllers\PaymentProcessController@redirectCheck');
        $router->get('wallee/return-failed/{id}', 'Wallee\Controllers\PaymentProcessController@returnFailed')->where('id', '\d+');
        $router->post('rest/storefront/wallee/register-return', 'Wallee\Controllers\PaymentProcessController@registerReturnContext');
        $router->post('rest/storefront/wallee/restore-cart', 'Wallee\Controllers\PaymentProcessController@restoreCart');
        $router->get('rest/storefront/wallee/order-checkout-data', 'Wallee\Controllers\PaymentProcessController@getOrderCheckoutData');
        $router->post('rest/storefront/wallee/pay-order', 'Wallee\Controllers\PaymentProcessController@payOrderRest');
    }
}