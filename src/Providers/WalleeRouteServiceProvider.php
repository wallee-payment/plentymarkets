<?php
namespace Wallee\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\ApiRouter;
use Plenty\Plugin\Routing\Router;

class WalleeRouteServiceProvider extends RouteServiceProvider
{

    /**
     *
     * @param ApiRouter $apiRouter
     */
    public function map(ApiRouter $apiRouter )
    {
        $apiRouter->version(
            ['v1'],
            ['namespace' => 'Wallee\Controllers'],
            function ($apiRouter) {

                //Frontend routes
                $apiRouter->post('wallee/update-transaction', 'PaymentNotificationController@updateTransaction');
                $apiRouter->get('wallee/fail-transaction/{id}', 'PaymentProcessController@failTransaction')->where('id', '\d+');
                $apiRouter->post('wallee/pay-order', 'PaymentProcessController@payOrder');
                $apiRouter->get('wallee/download-invoice/{id}', 'PaymentTransactionController@downloadInvoice')->where('id', '\d+');
                $apiRouter->get('wallee/download-packing-slip/{id}', 'PaymentTransactionController@downloadPackingSlip')->where('id', '\d+');
            }
        );
    }
}