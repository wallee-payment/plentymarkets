<?php
namespace Wallee\Helper;

use IO\Services\BasketService;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Modules\Payment\Events\Checkout\GetPaymentMethodContent;
use Plenty\Modules\Payment\Events\Checkout\ExecutePayment;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Plugin\Http\Request;
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
     *
     * @var Request
     */
    private $request;

    /**
     * Construct the helper
     *
     * @param  Dispatcher $eventDispatcher
     * @param  PaymentHelper $paymentHelper
     * @param  OrderRepositoryContract $orderRepository
     * @param  PaymentService $paymentService
     * @param  PaymentMethodRepositoryContract $paymentMethodService
     * @param  FrontendSessionStorageFactoryContract $session
     * @param  Request $request
     */
    public function __construct(
        Dispatcher $eventDispatcher,
        PaymentHelper $paymentHelper,
        OrderRepositoryContract $orderRepository,
        PaymentService $paymentService,
        PaymentMethodRepositoryContract $paymentMethodService,
        FrontendSessionStorageFactoryContract $session,
        Request $request
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->paymentHelper = $paymentHelper;
        $this->orderRepository = $orderRepository;
        $this->paymentService = $paymentService;
        $this->paymentMethodService = $paymentMethodService;
        $this->session = $session;
        $this->request = $request;
    }

    /**
     * Returns true when the request originates from the PWA layer.
     * The PWA plugin calls registerReturnContext before dopreparepayment,
     * which stores walleeOriginUrl in the session. CERES never sets this value.
     */
    private function isPwaContext(): bool
    {
        return !empty($this->session->getPlugin()->getValue('walleeOriginUrl'));
    }

    /**
     * Adds a listener to handle order creation and associate Wallee transaction.
     * PWA only: CERES handles payment entirely via ExecutePayment.
     * @return void
     */
    public function addAfterOrderCreatedListener(): void
    {
        $this->eventDispatcher->listen(OrderCreated::class, function (OrderCreated $event) {
            $order = $event->getOrder();
            $this->getLogger(__METHOD__)->error('Wallee::OrderCreatedEventFired', [
                'orderId' => $order->id,
            ]);

            try {
                if (!is_object($order) || !isset($order->id)) {
                    return;
                }

                if (!$this->paymentHelper->isWalleePaymentMopId($order->methodOfPaymentId ?? 0)) {
                    return;
                }

                // CERES processes payment in ExecutePayment — nothing to do here
                if (!$this->isPwaContext()) {
                    $this->getLogger(__METHOD__)->error('Wallee::OrderCreatedSkippedForCeres');
                    return;
                }

                $transactionId = $this->session->getPlugin()->getValue('walleeTransactionId');

                $this->getLogger(__METHOD__)->error('FLOW::TransactionFromSession', [
                    'transactionId' => $transactionId,
                ]);

                // Link the basket-level transaction to the newly created order
                if ($transactionId) {
                    try {
                        /** @var \Wallee\Services\WalleeSdkService $sdkService */
                        $sdkService = pluginApp(\Wallee\Services\WalleeSdkService::class);
                        $sdkService->call('updateTransaction', [
                            'id' => $transactionId,
                            'merchantReference' => (string) $order->id,
                        ]);
                        $this->getLogger(__METHOD__)->error('Wallee::TransactionLinked', [
                            'transactionId' => $transactionId,
                            'orderId' => $order->id,
                        ]);
                    } catch (\Exception $e) {
                        $this->getLogger(__METHOD__)->error('Wallee::TransactionLinkFailed', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                } else {
                    $this->getLogger(__METHOD__)->error('Wallee::NoTransactionInSession');
                }

                $paymentMethod = $this->paymentHelper->getWalleePaymentMethodByMopId($order->methodOfPaymentId);
                if (!$paymentMethod) {
                    $this->getLogger(__METHOD__)->error('Wallee::PaymentMethodNotFound', [
                        'methodOfPaymentId' => $order->methodOfPaymentId,
                    ]);
                    return;
                }

                $this->getLogger(__METHOD__)->error('Wallee::beforeExecutePaymentFunction', []);
                $result = $this->paymentService->executePayment($order, $paymentMethod);
                $this->getLogger(__METHOD__)->error('Wallee::afterExecutePaymentFunction', [
                    'result' => $result,
                ]);

                $type = $result['type'] ?? '';
                if ($type === GetPaymentMethodContent::RETURN_TYPE_REDIRECT_URL || $type === 'redirectUrl') {
                    $type = 'redirect';
                } elseif ($type === GetPaymentMethodContent::RETURN_TYPE_ERROR || $type === 'error') {
                    $type = 'error';
                } else {
                    $type = 'continue';
                }


                $this->getLogger(__METHOD__)->error('Wallee::OrderCreatedEventTypeMatch', [
                    'type' => $type,
                    '$result[content]' => $result['content']
                ]);
                // Store redirect URL in session so ExecutePayment listener can return it to PWA
                if ($type === 'redirect' && !empty($result['content'])) {
                    $this->getLogger(__METHOD__)->error('Wallee::OrderCreatedEventSessionSet', [
                        'result[content]' => $result['content']
                    ]);

                    $this->session->getPlugin()->setValue('walleePendingRedirectUrl', $result['content']);
                    $this->session->getPlugin()->setValue('walleeOrderId', $order->id);
//                    $this->getLogger(__METHOD__)->error('Wallee::OrderCreatedRedirectStored', [
//                        'url' => $result['content'],
//                    ]);
                }

            } catch (\Exception $e) {
                $this->getLogger(__METHOD__)->error('Wallee::AfterOrderCreatedException', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        });
    }

    /**
     * Adds the get payment method content event listener.
     * Both PWA and CERES: creates a basket-level transaction and returns the redirect URL.
     * CERES: redirect happens here (plentymarkets redirectUrl from this event).
     * PWA: URL also stored in session ExecutePayment listener can return it.
     * @return void
     */
    public function addGetPaymentMethodContentEventListener(): void
    {
        $this->eventDispatcher->listen(GetPaymentMethodContent::class, function (GetPaymentMethodContent $event) {
            $this->getLogger(__METHOD__)->error('Wallee::GetPaymentMethodContentEventFired', []);

            try {
                if (!$this->paymentHelper->isWalleePaymentMopId($event->getMop())) {
                    return;
                }

                // CERES creates its transaction in ExecutePayment — skip here
                if (!$this->isPwaContext()) {
                    $this->getLogger(__METHOD__)->error('Wallee::GetPaymentMethodContentSkippedForCeres');
                    return;
                }

                $eventMop = $this->paymentHelper->getWalleePaymentMethodByMopId($event->getMop());
                if (!$eventMop) {
                    $this->getLogger(__METHOD__)->error('Wallee::PaymentMethodNull');
                    $event->setType('continue');
                    $event->setValue('');
                    return;
                }

                $this->getLogger(__METHOD__)->error('Wallee::beforeExecutePaymentFromBasket', []);
                $result = $this->paymentService->executePaymentFromBasket($eventMop);

                $event->setValue($result['content'] ?? null);
                $event->setType($result['type'] ?? '');

                $this->session->getPlugin()->setValue('walleePaymentSelectedMethodId', $event->getMop());
                if (!empty($result['content'])) {
                    $this->session->getPlugin()->setValue('walleePendingRedirectUrl', $result['content']);
                }

            } catch (\Exception $e) {
                $this->getLogger(__METHOD__)->error('Wallee::GetPaymentMethodContentException', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        });
    }

    /**
     * Adds the execute payment content event listener.
     * PWA: returns redirect URL stored in session by addAfterOrderCreatedListener.
     * CERES: redirect already handled by addGetPaymentMethodContentEventListener; returns 'continue'.
     * @return void
     */
    public function addExecutePaymentContentEventListener(): void
    {
        $this->eventDispatcher->listen(ExecutePayment::class, function (ExecutePayment $event) {
            $this->getLogger(__METHOD__)->error('Wallee::ExecutePaymentEventFired', []);

            try {
                $isPwa = $this->isPwaContext();
                $orderId = $event->getOrderId();

                $this->getLogger(__METHOD__)->error('Wallee::ExecutePaymentContext', [
                    'eventMop' => $event->getMop(),
                    'orderId'  => $orderId,
                    'isPwa'    => $isPwa,
                ]);

                if ($isPwa) {
                    // Primary source: URL stored by addAfterOrderCreatedListener.
                    // Fallback: URL stored by addGetPaymentMethodContentEventListener.
                    $redirectUrl = $this->session->getPlugin()->getValue('walleePendingRedirectUrl');
                    $this->getLogger(__METHOD__)->error('Wallee::ExecutePaymentPwaRedirectUrl', [
                        'redirectUrl' => $redirectUrl,
                    ]);

                    // Second fallback: rebuild URL from the transaction ID still in session.
                    if (empty($redirectUrl)) {
                        $transactionId = $this->session->getPlugin()->getValue('walleeTransactionId');
                        $this->getLogger(__METHOD__)->error('Wallee::ExecutePaymentPwaFallbackTransactionId', [
                            'transactionId' => $transactionId,
                        ]);
                        if ($transactionId) {
                            /** @var \Wallee\Services\WalleeSdkService $sdkService */
                            $sdkService = pluginApp(\Wallee\Services\WalleeSdkService::class);
                            $paymentPageUrl = $sdkService->call('buildPaymentPageUrl', ['id' => $transactionId]);
                            if (!empty($paymentPageUrl) && !is_array($paymentPageUrl)) {
                                $redirectUrl = $paymentPageUrl;
                                $this->getLogger(__METHOD__)->error('Wallee::ExecutePaymentPwaFallbackUrlBuilt', [
                                    'url' => $redirectUrl,
                                ]);
                            }
                        }
                    }

                    if (!empty($redirectUrl)) {
                        $this->session->getPlugin()->unsetKey('walleePendingRedirectUrl');
                        $this->session->getPlugin()->unsetKey('walleeOrderId');
                        $event->setType('redirect');
                        $event->setValue($redirectUrl);
                    } else {
                        $event->setType('continue');
                        $event->setValue('');
                    }
                    return;
                }

                // CERES: validate MOP, then create Wallee transaction using the real order.
                $mopId = $event->getMop();
                if (!$this->paymentHelper->isWalleePaymentMopId($mopId)) {
                    $this->getLogger(__METHOD__)->error('Wallee::ExecutePaymentNotWalleeMethod', [
                        'mop' => $mopId,
                    ]);
                    return;
                }

                $eventMop = $this->paymentHelper->getWalleePaymentMethodByMopId($mopId);
                if (!$eventMop) {
                    $this->getLogger(__METHOD__)->error('Wallee::ExecutePaymentMethodNull', ['mop' => $mopId]);
                    return;
                }

                $eventOrderId = $this->orderRepository->findById($orderId);
                if (!$eventOrderId) {
                    $this->getLogger(__METHOD__)->error('Wallee::ExecutePaymentOrderNotFound', [
                        'orderId' => $orderId,
                    ]);
                    return;
                }

                // Creates CONFIRMED transaction + plentyPayment (unaccountable=1) + assigns to order.
                $result = $this->paymentService->executePayment($eventOrderId, $eventMop);

                $this->getLogger(__METHOD__)->error('Wallee::ExecutePaymentCeresResult', ['result' => $result]);

                // Pass type directly — CERES expects 'redirectUrl' (not 'redirect') from ExecutePayment.
                $event->setType($result['type'] ?? '');
                $event->setValue($result['content'] ?? null);

            } catch (\Exception $e) {
                $this->getLogger(__METHOD__)->error('Wallee::ExecutePaymentException', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $event->setType('error');
                $event->setValue('Payment failed: ' . $e->getMessage());
            }
        });
    }
}
