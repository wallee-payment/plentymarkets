<?php

namespace Wallee\Controllers;

use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;

class CustomCheckoutController extends Controller
{
    protected $orderRepository;
    protected $basketRepository;
    
    public function __construct(OrderRepositoryContract $orderRepository, BasketRepositoryContract $basketRepository)
    {
        $this->orderRepository = $orderRepository;
        $this->basketRepository = $basketRepository;
    }
    
    public function customCheckoutAction(Request $request): Response
    {
        // Debugging message
//        echo "debugging";
//        exit;
        
        // Example: Proceed with creating an order from the basket
        try {
            $basket = $this->basketRepository->load(); // Load the current basket
            $order = $this->orderRepository->createOrder($basket->toArray());
            
            return response()->json([
              'message' => 'Order created successfully',
              'order' => $order
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
