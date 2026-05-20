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

                // Link the basket-level transaction to the newly created order.
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
     * PWA only: creates a basket-level transaction before order is placed.
     * CERES skips this — it creates its transaction inside ExecutePayment.
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

                // CERES creates its transaction in ExecutePayment — skip here to avoid double transactions
                if (!$this->isPwaContext()) {
                    $this->getLogger(__METHOD__)->error('Wallee::GetPaymentMethodContentSkippedForCeres');
                    return;
                }

                $eventMop = $this->paymentHelper->getWalleePaymentMethodByMopId($event->getMop());
                if (!$eventMop) {
                    $this->getLogger(__METHOD__)->error('Wallee::GetPaymentMethodContentMethodNull');
                    $event->setType('continue');
                    $event->setValue('');
                    return;
                }

                $this->getLogger(__METHOD__)->error('Wallee::GetPaymentMethodContentExecutingFromBasket');
                $result = $this->paymentService->executePaymentFromBasket($eventMop);

                $event->setValue($result['content'] ?? null);
                $event->setType($result['type'] ?? '');

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
     * CERES: creates Wallee transaction and returns redirect URL directly.
     * PWA: returns redirect URL stored in session by addAfterOrderCreatedListener.
     * @return void
     */
    public function addExecutePaymentContentEventListener(): void
    {
        $this->eventDispatcher->listen(ExecutePayment::class, function (ExecutePayment $event) {
            $this->getLogger(__METHOD__)->error('Wallee::ExecutePaymentEventFired', []);

            try {
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

                $orderId = $event->getOrderId();
                $isPwa = $this->isPwaContext();

                $this->getLogger(__METHOD__)->error('Wallee::ExecutePaymentContext', [
                    'orderId' => $orderId,
                    'isPwa' => $isPwa,
                ]);

                $eventOrderId = $this->orderRepository->findById($orderId);
                if (!$eventOrderId) {
                    $this->getLogger(__METHOD__)->error('Wallee::ExecutePaymentOrderNotFound', [
                        'orderId' => $orderId,
                    ]);
                    return;
                }

                if ($isPwa) {
                    $result = $this->paymentService->executePayment($eventOrderId, $eventMop);
                    $this->getLogger(__METHOD__)->error('Wallee::ExecutePaymentPwaResult', ['result' => $result]);
                    $event->setValue($result['content'] ?? null);
                    $event->setType($result['type'] ?? '');
                    return;
                }

                // CERES: no basket transaction exists, create fresh
                $result = $this->paymentService->executePayment($eventOrderId, $eventMop);

                $this->getLogger(__METHOD__)->error('Wallee::ExecutePaymentCeresResult', ['result' => $result]);

                $event->setValue($result['content'] ?? null);
                $event->setType($result['type'] ?? '');

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
