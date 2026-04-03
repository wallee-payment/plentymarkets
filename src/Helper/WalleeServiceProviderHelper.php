<?php
namespace Wallee\Helper;

use IO\Services\BasketService;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Modules\Payment\Events\Checkout\GetPaymentMethodContent;
use Plenty\Modules\Payment\Events\Checkout\ExecutePayment;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Plugin\Log\Loggable;
use Wallee\Helper\PaymentHelper;
use Wallee\Services\PaymentService;

class WalleeServiceProviderHelper
{
    use Loggable;
    
    /**
     * @var $eventDispatcher
     */
    private $eventDispatcher;
    
    /**
     * @var $paymentHelper
     */
    private $paymentHelper;
    
    /**
     * @var $orderRepository
     */
    private $orderRepository;
    
    /**
     * @var $paymentService
     */
    private $paymentService;
    
    /**
     * @var $paymentMethodService
     */
    private $paymentMethodService;
    

    /**
     * Construct the helper
     *
     * @param  Dispatcher $eventDispatcher
     * @param  PaymentHelper $paymentHelper
     * @param  OrderRepositoryContract $orderRepository
     * @param  PaymentService $paymentService
     * @param  PaymentMethodRepositoryContract $paymentMethodService
     */
    public function __construct(
        Dispatcher $eventDispatcher,
        PaymentHelper $paymentHelper,
        OrderRepositoryContract $orderRepository,
        PaymentService $paymentService,
        PaymentMethodRepositoryContract $paymentMethodService
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->paymentHelper = $paymentHelper;
        $this->orderRepository = $orderRepository;
        $this->paymentService = $paymentService;
        $this->paymentMethodService = $paymentMethodService;
    }

    /**
     * Adds a listener to handle order creation and associate Wallee transaction
     * @return never
     */
    public function addAfterOrderCreatedListener() {
        // Listen to order creation events
        $this->eventDispatcher->listen('IO.Order.Created', function ($order) {
            try {
                if (!is_object($order) || !isset($order->id)) {
                    return;
                }
                
                // Check if this is a Wallee Payment order
                if (!$this->paymentHelper->isWalleePaymentMopId($order->methodOfPaymentId ?? 0)) {
                    return;
                }
                
                /** @var \Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract $session */
                $session = pluginApp(\Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract::class);
                $selectedMethodId = $session->getPlugin()->getValue('walleeSelectedMethodId');
                
                // Get Wallee Payment method object
                $paymentMethod = $this->paymentHelper->getWalleePaymentMethodByMopId($order->methodOfPaymentId);
                
                if (!$paymentMethod) {
                    $this->getLogger(__METHOD__)->error('Wallee::PaymentMethodNotFound', [
                        'methodOfPaymentId' => $order->methodOfPaymentId
                    ]);
                    return;
                }
                
                // Execute payment using the existing order-based flow
                $result = $this->paymentService->executePayment($order, $paymentMethod);
                
                // Store redirect URL in session for PWA plugin to pick up
                if (isset($result['content']) && !empty($result['content'])) {
                    $session->getPlugin()->setValue('walleePendingRedirectUrl', $result['content']);
                    $session->getPlugin()->setValue('walleeOrderId', $order->id);
                }
                
            } catch (\Exception $e) {
                $this->getLogger(__METHOD__)->error('Wallee::AfterOrderCreatedException', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        });
    }

    /**
     * Adds the get payment method content event listener for PWA
     * @return never
     */
    public function addGetPaymentMethodContentEventListener() {
        $this->eventDispatcher->listen(GetPaymentMethodContent::class, function (GetPaymentMethodContent $event) {
            
            try {
                // Check if this is a Wallee Payment method
                $isWallee = $this->paymentHelper->isWalleePaymentMopId($event->getMop());
                
                if (!$isWallee) {
                    return;
                }
                
                // Get Wallee method object
                $eventMop = $this->paymentHelper->getWalleePaymentMethodByMopId($event->getMop());
                
                if (!$eventMop) {
                    return;
                }
                
                // Handle PWA basket-based payment
                $result = $this->paymentService->executePaymentFromBasket($eventMop);
                
                $event->setValue($result['content'] ?? null);
                $event->setType($result['type'] ?? '');
                
            } catch (\Exception $e) {
                $this->getLogger(__METHOD__)->error('Wallee::GetPaymentMethodContentException', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        });
    }

    /**
     * Adds the execute payment content event listener
     * @return never
     */
    public function addExecutePaymentContentEventListener() {
        // Listen with high priority (runs before other handlers)
        $this->eventDispatcher->listen(ExecutePayment::class, function (ExecutePayment $event) {
            
            try {
                // Get the basket to check what payment method the user actually selected
                /** @var \IO\Services\BasketService $basketService */
                $basketService = pluginApp(\IO\Services\BasketService::class);
                $basket = $basketService->getBasket();
                
                $selectedPaymentMethodId = $basket->methodOfPaymentId ?? $event->getMop();
                
                // Check if the selected payment method (from basket) is a Wallee Payment method
                $isWallee = $this->paymentHelper->isWalleePaymentMopId($selectedPaymentMethodId);
                
                if (!$isWallee) {
                    $this->getLogger(__METHOD__)->error('Wallee::NotWalleeMethod', [
                        'selectedPaymentMethodId' => $selectedPaymentMethodId
                    ]);
                    return;
                }
                
                // Get Wallee Payment method object
                $eventMop = $this->paymentHelper->getWalleePaymentMethodByMopId($selectedPaymentMethodId);
                
                if (!$eventMop) {
                    $this->getLogger(__METHOD__)->error('Wallee::PaymentMethodNull', [
                        'mop' => $event->getMop()
                    ]);
                    return;
                }
                
                // Check if order exists
                $orderId = $event->getOrderId();
                
                if ($orderId == 0 || empty($orderId)) {
                    
                    // Store the selected Wallee Payment method in session
                    $this->session->getPlugin()->setValue('walleePaymentSelectedMethodId', $selectedPaymentMethodId);
                    
                    $result = [
                        'type' => 'continue',
                        'content' => ''
                    ];
                    
                } else {
                    // Order exists: either traditional flow or PWA post-order-creation call
                    $eventOrderId = $this->orderRepository->findById($orderId);
                    if (!$eventOrderId) {
                        $this->getLogger(__METHOD__)->error('Wallee::OrderNotFound', [
                            'orderId' => $orderId
                        ]);
                        return;
                    }

                    $result = $this->paymentService->executePayment(
                        $eventOrderId,
                        $eventMop
                    );
                }
                
                // Map GetPaymentMethodContent types to ExecutePayment types for PWA compatibility
                $type = isset($result['type']) ? $result['type'] : '';
                if ($type === GetPaymentMethodContent::RETURN_TYPE_REDIRECT_URL || $type === 'redirectUrl') {
                    $type = 'redirect';
                } elseif ($type === GetPaymentMethodContent::RETURN_TYPE_ERROR || $type === 'error') {
                    $type = 'error';
                } elseif ($type === GetPaymentMethodContent::RETURN_TYPE_CONTINUE || $type === 'continue') {
                    $type = 'continue';
                }

                // Set event values
                $event->setValue(isset($result['content']) ? $result['content'] : null);
                $event->setType($type);
                
                // Store payment URL and transaction ID in session
                if ($type === 'redirect' && !empty($result['content'])) {
                    /** @var \Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract $session */
                    $session = pluginApp(\Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract::class);
                    $session->getPlugin()->setValue('walleePendingRedirectUrl', $result['content']);
                    
                    // Store additional data that might help
                    if (isset($result['transactionId'])) {
                        $session->getPlugin()->setValue('walleeTransactionId', $result['transactionId']);
                    }

                }
            } catch (\Exception $e) {
                $this->getLogger(__METHOD__)->error('Wallee::ExecutePaymentException', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        });
    }
}
