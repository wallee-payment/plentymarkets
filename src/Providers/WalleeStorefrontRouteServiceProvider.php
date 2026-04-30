<?php

namespace Wallee\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Modules\Webshop\REST\Routing\Router;

class WalleeStorefrontRouteServiceProvider extends RouteServiceProvider
{
    /**
     * @param Router $router
     * @return void
     */
    public function map(Router $router)
    {
        $router->post('rest/storefront/wallee/prepare', 'Wallee\Controllers\PaymentProcessController@preparePayment');
        $router->get('rest/storefront/wallee/check-redirect', 'Wallee\Controllers\PaymentProcessController@checkPendingRedirect');

        $router->post('rest/storefront/wallee/register-return', 'Wallee\Controllers\PaymentProcessController@registerReturnContext');
        $router->post('rest/storefront/wallee/restore-cart', 'Wallee\Controllers\PaymentProcessController@restoreCart');
        $router->get('rest/storefront/wallee/return-failed/{id}', 'Wallee\Controllers\PaymentProcessController@returnFailed')->where('id', '\d+');
    }
}