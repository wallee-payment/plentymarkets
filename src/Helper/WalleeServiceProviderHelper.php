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

            try {
                if (!is_object($order) || !isset($order->id)) {
                    return;
                }

                if (!$this->paymentHelper->isWalleePaymentMopId($order->methodOfPaymentId ?? 0)) {
                    return;
                }

                // CERES processes payment in ExecutePayment — nothing to do here
                if (!$this->isPwaContext()) {
                    return;
                }

                $transactionId = $this->session->getPlugin()->getValue('walleeTransactionId');

                // Link the basket-level transaction to the newly created order
                if ($transactionId) {
                    try {
                        /** @var \Wallee\Services\WalleeSdkService $sdkService */
                        $sdkService = pluginApp(\Wallee\Services\WalleeSdkService::class);
                        $sdkService->call('updateTransaction', [
                            'id' => $transactionId,
                            'merchantReference' => (string) $order->id,
                        ]);
                    } catch (\Exception $e) {
                        $this->getLogger(__METHOD__)->error('Wallee::TransactionLinkFailed', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $paymentMethod = $this->paymentHelper->getWalleePaymentMethodByMopId($order->methodOfPaymentId);
                if (!$paymentMethod) {
                    return;
                }

                $result = $this->paymentService->executePayment($order, $paymentMethod);

                $type = $result['type'] ?? '';
                if ($type === GetPaymentMethodContent::RETURN_TYPE_REDIRECT_URL || $type === 'redirectUrl') {
                    $type = 'redirect';
                } elseif ($type === GetPaymentMethodContent::RETURN_TYPE_ERROR || $type === 'error') {
                    $type = 'error';
                } else {
                    $type = 'continue';
                }

                // Store redirect URL in session so ExecutePayment listener can return it to PWA
                if ($type === 'redirect' && !empty($result['content'])) {
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
            try {
                if (!$this->paymentHelper->isWalleePaymentMopId($event->getMop())) {
                    return;
                }

                // CERES creates its transaction in ExecutePayment — skip here
                if (!$this->isPwaContext()) {
                    return;
                }

                $eventMop = $this->paymentHelper->getWalleePaymentMethodByMopId($event->getMop());
                if (!$eventMop) {
                    $event->setType('continue');
                    $event->setValue('');
                    return;
                }

                $result = $this->paymentService->executePaymentFromBasket($eventMop);

                $event->setValue($result['content'] ?? null);
                $event->setType($result['type'] ?? '');

                $this->session->getPlugin()->setValue('walleePaymentSelectedMethodId', $event->getMop());
                if (!empty($result['content'])) {
                    $this->session->getPlugin()->setValue('walleePendingRedirectUrl', $result['content']);
                    if (($result['type'] ?? '') !== GetPaymentMethodContent::RETURN_TYPE_REDIRECT_URL) {
                        // The content of a non-redirect result (e.g. an error message) was just
                        // stored as the pending redirect URL. If a later ExecutePayment serves
                        // this value, the storefront will "redirect" to a bogus relative URL.
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

            try {
                $isPwa = $this->isPwaContext();
                $orderId = $event->getOrderId();

                if ($isPwa) {
                    // Primary source: URL stored by addAfterOrderCreatedListener.
                    // Fallback: URL stored by addGetPaymentMethodContentEventListener.
                    $redirectUrl = $this->session->getPlugin()->getValue('walleePendingRedirectUrl');

                    // Second fallback: rebuild URL from the transaction ID still in session.
                    if (empty($redirectUrl)) {
                        $transactionId = $this->session->getPlugin()->getValue('walleeTransactionId');

                        if ($transactionId) {
                            /** @var \Wallee\Services\WalleeSdkService $sdkService */
                            $sdkService = pluginApp(\Wallee\Services\WalleeSdkService::class);
                            $paymentPageUrl = $sdkService->call('buildPaymentPageUrl', ['id' => $transactionId]);
                            if (!empty($paymentPageUrl) && !is_array($paymentPageUrl)) {
                                $redirectUrl = $paymentPageUrl;
                            }
                        }
                    }

                    if (!empty($redirectUrl)) {
                        $this->session->getPlugin()->unsetKey('walleePendingRedirectUrl');
                        $this->session->getPlugin()->unsetKey('walleeOrderId');

                        $event->setType('redirect');
                        $event->setValue($redirectUrl);
                    } else {
                        // No pending URL and no transaction id in session: the prepare-phase
                        // events (GetPaymentMethodContent / OrderCreated) did not run for this
                        // checkout. The order already exists at this point, so recover by
                        // creating the transaction directly from the order, like CERES does.

                        $recoveredUrl = $this->recoverRedirectUrlFromOrder($orderId, $event->getMop());
                        if (!empty($recoveredUrl)) {
                            $event->setType('redirect');
                            $event->setValue($recoveredUrl);
                            return;
                        }

                        // Recovery failed too: the storefront receives 'continue' and
                        // will NOT redirect to the payment page.
                        $event->setType('continue');
                        $event->setValue('');
                    }
                    return;
                }

                // CERES: validate MOP, then create Wallee transaction using the real order.
                $mopId = $event->getMop();
                if (!$this->paymentHelper->isWalleePaymentMopId($mopId)) {
                    return;
                }

                $eventMop = $this->paymentHelper->getWalleePaymentMethodByMopId($mopId);
                if (!$eventMop) {
                    return;
                }

                $eventOrderId = $this->orderRepository->findById($orderId);
                if (!$eventOrderId) {
                    return;
                }

                // Creates CONFIRMED transaction + plentyPayment (unaccountable=1) + assigns to order.
                $result = $this->paymentService->executePayment($eventOrderId, $eventMop);

                // Pass type directly — CERES expects 'redirectUrl' (not 'redirect') from ExecutePayment.
                $event->setType($result['type'] ?? '');
                $event->setValue($result['content'] ?? null);

            } catch (\Exception $e) {
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
                return null;
            }

            $paymentMethod = $this->paymentHelper->getWalleePaymentMethodByMopId($mopId);
            $order = $this->orderRepository->findById($orderId);
            if (!$paymentMethod || !$order) {
                return null;
            }

            $result = $this->paymentService->executePayment($order, $paymentMethod);

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
