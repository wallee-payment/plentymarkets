<?php
namespace Wallee\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\Router;
use Wallee\Controllers\PaymentNotificationController;
use Wallee\Controllers\PaymentProcessController;
use Wallee\Controllers\PaymentTransactionController;

class WalleeRouteServiceProvider extends RouteServiceProvider
{

    /**
     *
     * @param Router $router
     */
    public function map(Router $router)
    {
        $router->post('wallee/update-transaction', 'PaymentNotificationController@updateTransaction');
        $router->get('wallee/fail-transaction/{id}', 'PaymentProcessController@failTransaction')->where('id', '\d+');
        $router->post('wallee/pay-order', 'PaymentProcessController@payOrder');
        $router->get('wallee/download-invoice/{id}', 'PaymentTransactionController@downloadInvoice')->where('id', '\d+');
        $router->get('wallee/download-packing-slip/{id}', 'PaymentTransactionController@downloadPackingSlip')->where('id', '\d+');
    }
}