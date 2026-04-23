<?php
namespace Wallee\Helper;

use IO\Services\BasketService;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Modules\Payment\Events\Checkout\GetPaymentMethodContent;
use Plenty\Modules\Payment\Events\Checkout\ExecutePayment;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Plugin\Log\Loggable;
use Wallee\Helper\PaymentHelper;
use Wallee\Services\PaymentService;
use Plenty\Modules\Order\Events\OrderCreated;

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
     *
     * @var FrontendSessionStorageFactoryContract
     */
    private $session;

    /**
     * Construct the helper
     *
     * @param  Dispatcher $eventDispatcher
     * @param  PaymentHelper $paymentHelper
     * @param  OrderRepositoryContract $orderRepository
     * @param  PaymentService $paymentService
     * @param  PaymentMethodRepositoryContract $paymentMethodService
     * @param  FrontendSessionStorageFactoryContract $session
     */
    public function __construct(
        Dispatcher $eventDispatcher,
        PaymentHelper $paymentHelper,
        OrderRepositoryContract $orderRepository,
        PaymentService $paymentService,
        PaymentMethodRepositoryContract $paymentMethodService,
        FrontendSessionStorageFactoryContract $session
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->paymentHelper = $paymentHelper;
        $this->orderRepository = $orderRepository;
        $this->paymentService = $paymentService;
        $this->paymentMethodService = $paymentMethodService;
        $this->session = $session;
    }

    /**
     * Adds a listener to handle order creation and associate Wallee transaction
     * @return void
     */
    public function addAfterOrderCreatedListener(): void
    {
        // Listen to order creation events
//        $this->eventDispatcher->listen('OrderCreated', function ($order) {
        $this->eventDispatcher->listen(OrderCreated::class, function (OrderCreated $event) {
//            $this->getLogger(__METHOD__)->error('Wallee::OrderCreatedEventFired', []);
            $order = $event->getOrder();
            $this->getLogger(__METHOD__)->error('Wallee::OrderCreatedEventFired', [
                'orderId' => $order->id
            ]);

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
                $selectedMethodId = $session->getPlugin()->getValue('walleePaymentSelectedMethodId');

                $transactionId = $session->getPlugin()->getValue('walleeTransactionId');

                $this->getLogger(__METHOD__)->error('FLOW::TransactionFromSession', [
                    'transactionId' => $transactionId
                ]);

                // Link transaction and order
                if ($transactionId) {
                    try {
                        /** @var \Wallee\Services\WalleeSdkService $sdkService */
                        $sdkService = pluginApp(\Wallee\Services\WalleeSdkService::class);

                        $sdkService->call('updateTransaction', [
                            'id' => $transactionId,
                            'merchantReference' => (string)$order->id
                        ]);

                        $this->getLogger(__METHOD__)->error('Wallee::TransactionLinked', [
                            'transactionId' => $transactionId,
                            'orderId' => $order->id
                        ]);

                    } catch (\Exception $e) {
                        $this->getLogger(__METHOD__)->error('Wallee::TransactionLinkFailed', [
                            'error' => $e->getMessage()
                        ]);
                    }
                } else {
                    $this->getLogger(__METHOD__)->error('Wallee::NoTransactionInSession');
                }

                // Get Wallee Payment method object
                $paymentMethod = $this->paymentHelper->getWalleePaymentMethodByMopId($order->methodOfPaymentId);

                if (!$paymentMethod) {
                    $this->getLogger(__METHOD__)->error('Wallee::PaymentMethodNotFound', [
                        'methodOfPaymentId' => $order->methodOfPaymentId
                    ]);
                    return;
                }

                $this->getLogger(__METHOD__)->error('Wallee::beforeExecutePaymentFunction', []);
                // Execute payment using the existing order-based flow
                $result = $this->paymentService->executePayment($order, $paymentMethod);
                // $result = $this->paymentService->executePaymentFromBasket($paymentMethod);
                $this->getLogger(__METHOD__)->error('Wallee::afterExecutePaymentFunction', [
                    'result' => $result
                ]);
//                $eventMop = $this->paymentHelper->getWalleePaymentMethodByMopId($event->getMop());

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
     * @return void
     */
    public function addGetPaymentMethodContentEventListener(): void
    {
        $this->eventDispatcher->listen(GetPaymentMethodContent::class, function (GetPaymentMethodContent $event) {
            $this->getLogger(__METHOD__)->error('Wallee::GetPaymentMethodContentEventFired', []);

            try {
                // Check if this is a Wallee Payment method
                $isWallee = $this->paymentHelper->isWalleePaymentMopId($event->getMop());
                $selectedPaymentMethodId = $event->getMop() ?? '';
                $this->getLogger(__METHOD__)->error('Wallee::PaymentMethodNull', [
                    'isWallee' => $isWallee,
                    'selectedPaymentMethodId' => $selectedPaymentMethodId
                ]);
                if (!$isWallee) {
                    return;
                }

                // Get Wallee method object
                $eventMop = $this->paymentHelper->getWalleePaymentMethodByMopId($event->getMop());

//                if (!$eventMop) {
//                    return;
//                }
                //do not return nothing if !eventMop, restore if needed
                if (!$eventMop) {
                    $this->getLogger(__METHOD__)->error('Wallee::PaymentMethodNull');

                    $event->setType('continue');
                    $event->setValue('');
                    return;
                }

                $this->getLogger(__METHOD__)->error('Wallee::beforeExecutePaymentFromBasket', []);
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
     * @return void
     */
    public function addExecutePaymentContentEventListener(): void
    {
        // Listen with high priority (runs before other handlers)
        $this->eventDispatcher->listen(ExecutePayment::class, function (ExecutePayment $event) {
            $this->getLogger(__METHOD__)->error('Wallee::ExecutePaymentEventFired', []);

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
                $this->getLogger(__METHOD__)->error('Wallee::OrderExistExecutePaymentEvent', ['orderId' => $orderId]);

                if ($orderId == 0 || empty($orderId)) {
                    $this->getLogger(__METHOD__)->error('Wallee::OrderIdIsZero', []);
                    // Store the selected Wallee Payment method in session
                    $this->session->getPlugin()->setValue('walleePaymentSelectedMethodId', $selectedPaymentMethodId);
                    $this->getLogger(__METHOD__)->error('Wallee::OrderIdIsZero_AfterSessionSet', []);
                    $result = [
                        'type' => 'continue',
                        'content' => ''
                    ];
                    $this->getLogger(__METHOD__)->error('Wallee::OrderIdIsZero_AfterResultSet', []);
                } else {
                    // Order exists: either traditional flow or PWA post-order-creation call
                    $eventOrderId = $this->orderRepository->findById($orderId);
                    if (!$eventOrderId) {
                        $this->getLogger(__METHOD__)->error('Wallee::OrderNotFound', [
                            'orderId' => $orderId
                        ]);
                        return;
                    }

                    $this->getLogger(__METHOD__)->error('Wallee::BeforeExecutePayment');
                    $result = $this->paymentService->executePayment(
                        $eventOrderId,
                        $eventMop
                    );
                }

                // Map GetPaymentMethodContent types to ExecutePayment types for PWA compatibility
//                $type = isset($result['type']) ? $result['type'] : '';
//                if ($type === GetPaymentMethodContent::RETURN_TYPE_REDIRECT_URL || $type === 'redirectUrl') {
//                    $type = 'redirectUrl';
//                } elseif ($type === GetPaymentMethodContent::RETURN_TYPE_ERROR || $type === 'error') {
//                    $type = 'error';
//                } elseif ($type === GetPaymentMethodContent::RETURN_TYPE_CONTINUE || $type === 'continue') {
//                    $type = 'continue';
//                }

                $this->getLogger(__METHOD__)->error('Wallee::BeforeResultMap', [
                    'result' => $result
                ]);

                $type = $result['type'] ?? '';
                $content = $result['content'] ?? $result['redirectUrl'] ?? null;

                if ($type === GetPaymentMethodContent::RETURN_TYPE_REDIRECT_URL || $type === 'redirectUrl') {
                    $type = 'redirect';
                } elseif ($type === GetPaymentMethodContent::RETURN_TYPE_ERROR || $type === 'error') {
                    $type = 'error';
                } else {
                    $type = 'continue';
                }

                $this->getLogger(__METHOD__)->error('Wallee::ExecutePaymentEventFiredPWACheck', [
                    'type' => $type,
                    'content' => $content
                ]);

                $event->setType($type);
                $event->setValue($content);

                // TODO Safe setters commented for testing
                // Set event values
//                $event->setValue(isset($result['content']) ? $result['content'] : null);
//                $event->setType($type);

                // Store payment URL and transaction ID in session
//                if ($type === 'redirect' && !empty($result['content'])) {
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

                $event->setType('error');
                $event->setValue('Payment failed: ' . $e->getMessage());
            }
        });
    }
}
