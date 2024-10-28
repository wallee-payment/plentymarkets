<?php

namespace Wallee\Controllers;

use Plenty\Modules\Frontend\Services\CheckoutService;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;

class CustomCheckoutController extends Controller
{
    protected $checkoutService;
    
    public function __construct(CheckoutService $checkoutService)
    {
        $this->checkoutService = $checkoutService;
    }
    
    public function customCheckoutAction(Request $request): Response
    {
        // Add custom logic here, for example, debugging or redirecting
        //echo "debugging"; // For testing purposes
        //exit;
        
        // Or handle checkout as per your requirement
        return $this->checkoutService->proceedToCheckout();
    }
}
