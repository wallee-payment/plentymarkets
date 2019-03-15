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
        $router->post('wallee/update-transaction', 'Wallee\Controllers\PaymentNotificationController@updateTransaction');
        $router->get('wallee/fail-transaction/{id}', 'Wallee\Controllers\PaymentProcessController@failTransaction')->where('id', '\d+');
        $router->get('wallee/download-invoice/{id}', 'Wallee\Controllers\PaymentTransactionController@downloadInvoice')->where('id', '\d+');
        $router->get('wallee/download-packing-slip/{id}', 'Wallee\Controllers\PaymentTransactionController@downloadPackingSlip')->where('id', '\d+');
    }
}