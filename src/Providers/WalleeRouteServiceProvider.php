<?php

namespace Wallee\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\Router;

class WalleeRouteServiceProvider extends RouteServiceProvider {

	/**
	 *
	 * @param Router $router
	 */
	public function map(Router $router) {
		$router->post('wallee/update-transaction', 'Wallee\Controllers\PaymentNotificationController@updateTransaction');
	}

}