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
        $router->post('storefront/wallee/prepare', 'Wallee\Controllers\PaymentProcessController@preparePayment');
        $router->get('storefront/wallee/check-redirect', 'Wallee\Controllers\PaymentProcessController@checkPendingRedirect');
    }
}