<?php
namespace Wallee\Helper;

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
     * Bump on every logging/flow change. Appears in the log payloads so we can
     * verify from the logs alone which code revision served a given request.
     */
    const LOG_REV = 'wal-2026-06-11-02';

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
    /**
     * Determines whether the current request originates from the PWA theme.
     * Logs the lookup variables to assist in debugging session state issues.
     *
     * @return bool
     */
    private function isPwaContext(): bool
    {
        $originUrl = $this->session->getPlugin()->getValue('walleeOriginUrl');
        $transactionId = $this->session->getPlugin()->getValue('walleeTransactionId');

        $this->getLogger(__METHOD__)->debug('Checking PWA context variables', [
            'originUrl' => $originUrl ?? 'null',
            'transactionId' => $transactionId ?? 'null',
        ]);

        return !empty($originUrl);
    }

    /**
     * Short fingerprint of the plenty session cookie so log lines from the
     * prepare / order-created / execute requests can be matched to the same
     * (or a different) frontend session without writing the raw cookie to
     * the logs. A changing fingerprint between two steps of one checkout
     * means the session rotated or the requests used different sessions.
     */
    private function sessionFingerprint(): string
    {
        $cookie = (string) $this->request->header('Cookie');
        if (preg_match('/plentyID=([^;]+)/', $cookie, $matches)) {
            return substr(md5($matches[1]), 0, 12);
        }
        return $cookie !== '' ? 'no-plentyid-' . substr(md5($cookie), 0, 8) : 'no-cookie';
    }

    /**
     * Snapshot of all wallee session keys, attached to flow logs so we can
     * see exactly which key was missing when a redirect fails to happen.
     */
    private function walleeSessionSnapshot(): array
    {
        return [
            'logRev' => self::LOG_REV,
            'sessionFingerprint' => $this->sessionFingerprint(),
            'walleeOriginUrl' => $this->session->getPlugin()->getValue('walleeOriginUrl') ?? 'null',
            'walleeTransactionId' => $this->session->getPlugin()->getValue('walleeTransactionId') ?? 'null',
            'walleePendingRedirectUrl' => $this->session->getPlugin()->getValue('walleePendingRedirectUrl') ?? 'null',
            'walleeOrderId' => $this->session->getPlugin()->getValue('walleeOrderId') ?? 'null',
        ];
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
                'sessionState' => $this->walleeSessionSnapshot(),
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
     * PWA only: creates a basket-level transaction before order creation and stores the redirect URL in session.
     * CERES is skipped — its transaction is created in ExecutePayment after order creation.
     * @return void
     */
    public function addGetPaymentMethodContentEventListener(): void
    {
        $this->eventDispatcher->listen(GetPaymentMethodContent::class, function (GetPaymentMethodContent $event) {
            $this->getLogger(__METHOD__)->error('Wallee::GetPaymentMethodContentEventFired', [
                'mop' => $event->getMop(),
                'sessionState' => $this->walleeSessionSnapshot(),
            ]);

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

                $this->getLogger(__METHOD__)->error('Wallee::GetPaymentMethodContentResult', [
                    'type' => $result['type'] ?? 'null',
                    'content' => $result['content'] ?? 'null',
                    'transactionId' => $result['transactionId'] ?? 'null',
                    'sessionFingerprint' => $this->sessionFingerprint(),
                ]);

                $event->setValue($result['content'] ?? null);
                $event->setType($result['type'] ?? '');

                $this->session->getPlugin()->setValue('walleePaymentSelectedMethodId', $event->getMop());
                if (!empty($result['content'])) {
                    $this->session->getPlugin()->setValue('walleePendingRedirectUrl', $result['content']);
                    if (($result['type'] ?? '') !== GetPaymentMethodContent::RETURN_TYPE_REDIRECT_URL) {
                        // The content of a non-redirect result (e.g. an error message) was just
                        // stored as the pending redirect URL. If a later ExecutePayment serves
                        // this value, the storefront will "redirect" to a bogus relative URL.
                        $this->getLogger(__METHOD__)->error('Wallee::PendingRedirectUrlSetWithNonRedirectType', [
                            'type' => $result['type'] ?? 'null',
                            'content' => $result['content'],
                            'sessionFingerprint' => $this->sessionFingerprint(),
                        ]);
                    }
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
     * CERES: creates Wallee transaction from the real order, returns 'redirectUrl' type for CERES redirect.
     * @return void
     */
    public function addExecutePaymentContentEventListener(): void
    {
        $this->eventDispatcher->listen(ExecutePayment::class, function (ExecutePayment $event) {
            // Log that the payment execution has started for debugging purposes
            $this->getLogger(__METHOD__)->error('Wallee::ExecutePaymentEventFired', [
                'logRev' => self::LOG_REV,
            ]);

            try {
                $isPwa = $this->isPwaContext();
                $orderId = $event->getOrderId();

                // Log the payment context parameters to verify PWA status and IDs
                $this->getLogger(__METHOD__)->error(
                    'Wallee::ExecutePaymentContext',
                    [
                        'eventMop' => $event->getMop(),
                        'orderId'  => $orderId,
                        'isPwa'    => $isPwa,
                        'sessionState' => $this->walleeSessionSnapshot(),
                    ],
                );

                if ($isPwa) {
                    // Primary source: URL stored by addAfterOrderCreatedListener.
                    // Fallback: URL stored by addGetPaymentMethodContentEventListener.
                    $redirectUrl = $this->session->getPlugin()->getValue('walleePendingRedirectUrl');
                    // Log the resolved redirect URL for the PWA storefront
                    $this->getLogger(__METHOD__)->error(
                        'Wallee::ExecutePaymentPwaRedirectUrl',
                        [
                            'redirectUrl' => $redirectUrl ?? 'null',
                        ],
                    );

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
                        // $this->session->getPlugin()->unsetKey('walleeOriginUrl');
                        // $this->session->getPlugin()->unsetKey('walleeTransactionId');
                        // $this->session->getPlugin()->unsetKey('walleePaymentSelectedMethodId');
                        $this->getLogger(__METHOD__)->error('Wallee::ExecutePaymentPwaReturningRedirect', [
                            'orderId' => $orderId,
                            'redirectUrl' => $redirectUrl,
                            'sessionFingerprint' => $this->sessionFingerprint(),
                        ]);
                        $event->setType('redirect');
                        $event->setValue($redirectUrl);
                    } else {
                        // No pending URL and no transaction id in session: the prepare-phase
                        // events (GetPaymentMethodContent / OrderCreated) did not run for this
                        // checkout. The order already exists at this point, so recover by
                        // creating the transaction directly from the order, like CERES does.
                        $this->getLogger(__METHOD__)->error('Wallee::ExecutePaymentPwaSessionEmptyRecovering', [
                            'orderId' => $orderId,
                            'eventMop' => $event->getMop(),
                            'sessionState' => $this->walleeSessionSnapshot(),
                        ]);

                        $recoveredUrl = $this->recoverRedirectUrlFromOrder($orderId, $event->getMop());
                        if (!empty($recoveredUrl)) {
                            $event->setType('redirect');
                            $event->setValue($recoveredUrl);
                            return;
                        }

                        // Recovery failed too: the storefront receives 'continue' and
                        // will NOT redirect to the payment page.
                        $this->getLogger(__METHOD__)->error('Wallee::ExecutePaymentPwaNoRedirectContinue', [
                            'orderId' => $orderId,
                            'sessionState' => $this->walleeSessionSnapshot(),
                        ]);
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

    /**
     * Last-resort recovery for the PWA flow: when ExecutePayment finds neither a
     * pending redirect URL nor a transaction id in the session (observed when the
     * prepare-phase events were never dispatched), create the Wallee transaction
     * directly from the already-created order and return the payment page URL.
     *
     * @param int|null $orderId
     * @param int|string|null $mopId
     * @return string|null
     */
    private function recoverRedirectUrlFromOrder($orderId, $mopId): ?string
    {
        try {
            if (empty($orderId) || !$this->paymentHelper->isWalleePaymentMopId($mopId)) {
                $this->getLogger(__METHOD__)->error('Wallee::PwaRecoveryNotApplicable', [
                    'orderId' => $orderId,
                    'mopId' => $mopId,
                ]);
                return null;
            }

            $paymentMethod = $this->paymentHelper->getWalleePaymentMethodByMopId($mopId);
            $order = $this->orderRepository->findById($orderId);
            if (!$paymentMethod || !$order) {
                $this->getLogger(__METHOD__)->error('Wallee::PwaRecoveryOrderOrMethodMissing', [
                    'orderId' => $orderId,
                    'mopId' => $mopId,
                    'orderFound' => !empty($order),
                    'methodFound' => !empty($paymentMethod),
                ]);
                return null;
            }

            $result = $this->paymentService->executePayment($order, $paymentMethod);
            $this->getLogger(__METHOD__)->error('Wallee::PwaRecoveryExecutePaymentResult', [
                'orderId' => $orderId,
                'type' => $result['type'] ?? 'null',
                'content' => $result['content'] ?? 'null',
            ]);

            if (($result['type'] ?? '') === GetPaymentMethodContent::RETURN_TYPE_REDIRECT_URL && !empty($result['content'])) {
                return (string) $result['content'];
            }
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Wallee::PwaRecoveryException', [
                'orderId' => $orderId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
        return null;
    }
}
