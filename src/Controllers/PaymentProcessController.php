<?php
namespace Wallee\Controllers;

use IO\Services\NotificationService;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\Log\Loggable;
use Wallee\Services\WalleeSdkService;
use Wallee\Helper\PaymentHelper;
use Plenty\Plugin\Templates\Twig;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Payment\Contracts\PaymentOrderRelationRepositoryContract;
use Plenty\Modules\Payment\Models\PaymentProperty;
use IO\Services\OrderService;
use Plenty\Modules\Authorization\Services\AuthHelper;
use IO\Constants\OrderPaymentStatus;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Frontend\PaymentMethod\Contracts\FrontendPaymentMethodRepositoryContract;
use Plenty\Modules\Order\Property\Models\OrderPropertyType;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Wallee\Services\PaymentService;
use Plenty\Modules\Payment\Events\Checkout\GetPaymentMethodContent;
use IO\Services\OrderTotalsService;
use IO\Models\LocalizedOrder;
use IO\Services\SessionStorageService;
use Wallee\Helper\OrderHelper;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Modules\Basket\Contracts\BasketItemRepositoryContract;

class PaymentProcessController extends Controller
{

    use Loggable;

    /**
     *
     * @var Response
     */
    private $response;

    /**
     *
     * @var WalleeSdkService
     */
    private $sdkService;

    /**
     *
     * @var NotificationService
     */
    private $notificationService;

    /**
     *
     * @var PaymentService
     */
    private $paymentService;

    /**
     *
     * @var PaymentHelper
     */
    private $paymentHelper;

    /**
     *
     * @var PaymentRepositoryContract
     */
    private $paymentRepository;

    /**
     *
     * @var OrderRepositoryContract
     */
    private $orderRepository;

    /**
     *
     * @var PaymentOrderRelationRepositoryContract
     */
    private $paymentOrderRelationRepository;

    /**
     *
     * @var OrderHelper
     */
    private $orderHelper;

    /**
     *
     * @var OrderService
     */
    private $orderService;

    /**
     *
     * @var FrontendPaymentMethodRepositoryContract
     */
    private $frontendPaymentMethodRepository;

    /**
     *
     * @var PaymentMethodRepositoryContract
     */
    private $paymentMethodService;

    /**
     *
     * @var SessionStorageService
     */
    private $sessionStorage;

    /**
     *
     * @var FrontendSessionStorageFactoryContract
     */
    private $frontendSession;
    
    /**
     *
     * @var ConfigRepository
     */
    private $config;

    /**
     *
     * @var BasketItemRepositoryContract
     */
    private $basketItemRepository;

    /**
     * Constructor.
     *
     * @param Response $response
     * @param WalleeSdkService $sdkService
     * @param NotificationService $notificationService
     * @param PaymentService $paymentService
     * @param PaymentHelper $paymentHelper
     * @param PaymentRepositoryContract $paymentRepository
     * @param OrderRepositoryContract $orderRepository
     * @param PaymentOrderRelationRepositoryContract $paymentOrderRelationRepository
     * @param OrderHelper $orderHelper
     * @param OrderService $orderService
     * @param FrontendPaymentMethodRepositoryContract $frontendPaymentMethodRepository
     * @param PaymentMethodRepositoryContract $paymentMethodService
     * @param SessionStorageService $sessionStorage
     * @param FrontendSessionStorageFactoryContract $frontendSession
     * @param ConfigRepository $config
     * @param BasketItemRepositoryContract $basketItemRepository
     */
    public function __construct(Response $response, WalleeSdkService $sdkService, NotificationService $notificationService, PaymentService $paymentService, PaymentHelper $paymentHelper, PaymentRepositoryContract $paymentRepository, OrderRepositoryContract $orderRepository, PaymentOrderRelationRepositoryContract $paymentOrderRelationRepository, OrderHelper $orderHelper, OrderService $orderService, FrontendPaymentMethodRepositoryContract $frontendPaymentMethodRepository, PaymentMethodRepositoryContract $paymentMethodService, SessionStorageService $sessionStorage, FrontendSessionStorageFactoryContract $frontendSession, ConfigRepository $config, BasketItemRepositoryContract $basketItemRepository)
    {
        parent::__construct();
        $this->response = $response;
        $this->sdkService = $sdkService;
        $this->notificationService = $notificationService;
        $this->paymentService = $paymentService;
        $this->paymentHelper = $paymentHelper;
        $this->paymentRepository = $paymentRepository;
        $this->orderRepository = $orderRepository;
        $this->paymentOrderRelationRepository = $paymentOrderRelationRepository;
        $this->orderHelper = $orderHelper;
        $this->orderService = $orderService;
        $this->frontendPaymentMethodRepository = $frontendPaymentMethodRepository;
        $this->paymentMethodService = $paymentMethodService;
        $this->sessionStorage = $sessionStorage;
        $this->frontendSession = $frontendSession;
        $this->config = $config;
        $this->basketItemRepository = $basketItemRepository;
    }

    /**
     *
     * @param int $id
     */
    public function failTransaction(Twig $twig, int $id)
    {
        $transaction = $this->sdkService->call('getTransaction', [
            'id' => $id
        ]);
        // Get the current language from session storage
        $lang = $this->sessionStorage->getLang();

        if (is_array($transaction) && isset($transaction['error'])) {
            $confirmUrl = sprintf('%s/confirmation', $lang);
            return $this->response->redirectTo($confirmUrl);
        }

        $payments = $this->paymentRepository->getPaymentsByPropertyTypeAndValue(PaymentProperty::TYPE_TRANSACTION_ID, $transaction['id']);
        $payment = !empty($payments) ? $payments[0] : null;

        $order = null;
        $paymentMethodId = null;

        // Try to find order via payment relation
        if ($payment) {
            $orderRelation = $this->paymentOrderRelationRepository->findOrderRelation($payment);
            if ($orderRelation) {
                $order = $this->orderRepository->findOrderById($orderRelation->orderId);
            }
        }

        // If no payment record, try to find order by transaction merchant reference
        if (!$order && isset($transaction['merchantReference'])) {
            try {
                $order = $this->orderRepository->findOrderById($transaction['merchantReference']);
                $this->getLogger(__METHOD__)->debug('Wallee::FoundOrderByMerchantReference', [
                    'orderId' => $transaction['merchantReference'],
                    'transactionId' => $transaction['id']
                ]);
            } catch (\Exception $e) {
                $this->getLogger(__METHOD__)->error('Wallee::OrderNotFoundByMerchantReference', [
                    'merchantReference' => $transaction['merchantReference'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        if ($order) {
            $paymentMethodId = $this->orderHelper->getOrderPropertyValue($order, OrderPropertyType::PAYMENT_METHOD);
        }

        $errorMessage = $this->frontendSession->getPlugin()->getValue('walleePayErrorMessage');
        if ($errorMessage) {
            $this->notificationService->error($errorMessage);
            $this->frontendSession->getPlugin()->unsetKey('walleePayErrorMessage');
        } elseif (isset($transaction['userFailureMessage']) && ! empty($transaction['userFailureMessage'])) {
            $this->notificationService->error($transaction['userFailureMessage']);
            $this->paymentHelper->updatePlentyPayment($transaction);
        }

        if (! is_null($order) && ! ($order instanceof LocalizedOrder)) {
            $order = LocalizedOrder::wrap($order, $this->sessionStorage->getLang());
        }

        // Prepare template data
        $templateData = [
            'transaction' => $transaction,
            'payment' => $payment,
            'bodyClasses' => ['page-confirmation'],
            'orderData' => $order,
            'currentPaymentMethodId' => $paymentMethodId,
            'payOrderFormUrl' => sprintf('/%s/wallee/pay-order/', $lang)
        ];

        // Only add order-dependent data if order exists
        if ($order) {
            $templateData['totals'] = pluginApp(OrderTotalsService::class)->getAllTotals($order->order);
            $templateData['allowSwitchPaymentMethod'] = $this->allowSwitchPaymentMethod($order->order->id);
            $templateData['paymentMethodListForSwitch'] = $this->getPaymentMethodListForSwitch($paymentMethodId, $order->order->id);
        } else {
            $templateData['totals'] = null;
            $templateData['allowSwitchPaymentMethod'] = false;
            $templateData['paymentMethodListForSwitch'] = [];
        }

        return $twig->render('wallee::Failure', $templateData);
    }


    public function returnFailed(int $transactionId)
    {

        $transaction = $this->sdkService->call('getTransaction', [
            'id' => $transactionId
        ]);

        if (is_array($transaction) && isset($transaction['error'])) {
            $this->getLogger(__METHOD__)->error('Wallee::ReturnFailedLookupFailed', [
                'transactionId' => $transactionId,
                'transaction' => $transaction,
            ]);
            return $this->redirectToCheckout(null, $transactionId);
        }

        $payments = $this->paymentRepository->getPaymentsByPropertyTypeAndValue(PaymentProperty::TYPE_TRANSACTION_ID, $transaction['id']);
        $payment = !empty($payments) ? $payments[0] : null;

        $order = null;
        $paymentMethodId = null;

        if ($payment) {
            $orderRelation = $this->paymentOrderRelationRepository->findOrderRelation($payment);
            if ($orderRelation) {
                $order = $this->orderRepository->findOrderById($orderRelation->orderId);
            }
        }

        // Keep the order in its current unpaid state so it can be reused for payment retry
        $this->paymentHelper->updatePlentyPayment($transaction);

        if (!empty($transaction['userFailureMessage'])) {
            $this->getLogger(__METHOD__)->error('Wallee::OrderCanceledSetFailReason', [
                'userFailureMessage' => $transaction['userFailureMessage'],
            ]);
            $this->frontendSession->getPlugin()->setValue(
                'walleePayErrorMessage',
                $transaction['userFailureMessage']
            );
        }
        return $this->redirectToCheckout($order, $transactionId);
    }

    /**
     * Redirect to checkout for payment retry
     * For PWA: redirects to payment-selection page with orderId when available
     * For Ceres: redirects to standard checkout page
     *
     * @param Order|null $order
     * @param int|null $transactionId
     */
    private function redirectToCheckout(?Order $order = null, ?int $transactionId = null)
    {
        $orderId = $order->id ?? null;
        $originUrl = $this->frontendSession->getPlugin()->getValue('walleeOriginUrl');

        // Resolve the end user language: order property first, then session value set by register-return
        $orderLang = $order ? $this->orderHelper->getOrderPropertyValue($order, OrderPropertyType::DOCUMENT_LANGUAGE) : null;
        $sessionLang = $this->frontendSession->getPlugin()->getValue('walleeOriginLang');
        $lang = $orderLang ?: $sessionLang;
        /** @var \Plenty\Modules\Helper\Services\WebstoreHelper $webstoreHelper */
        $webstoreHelper = pluginApp(\Plenty\Modules\Helper\Services\WebstoreHelper::class);
        $defaultLang = $webstoreHelper->getCurrentWebstoreConfiguration()->defaultLanguage;
        $langPrefix = ($lang && $lang !== $defaultLang) ? '/' . $lang : '';

        if ($originUrl) {
            if ($orderId && $transactionId) {
                // PWA: redirect to the new payment selection page (outside /checkout guard)
                $url = sprintf(
                    '%s%s/payment-selection/%d/%d',
                    rtrim($originUrl, '/'),
                    $langPrefix,
                    $orderId,
                    $transactionId,
                );
            } else {
                // PWA fallback: no order available, redirect to checkout with failure flag
                $url = sprintf('%s%s/checkout?wallee_failed=1', rtrim($originUrl, '/'), $langPrefix);
            }

            return $this->response->redirectTo($url);
        }
        $ceresLang = $lang ?: $this->sessionStorage->getLang();
        $domain = $webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl;
        $url = sprintf('%s/%s/checkout', $domain, $ceresLang);

        return $this->response->redirectTo($url);
    }

    /**
     * Auto-redirect page for PWA
     * Checks session for pending redirect and redirects immediately
     *
     * @param Twig $twig
     * @return Response
     */
    public function redirectCheck(Twig $twig)
    {
        $redirectUrl = $this->frontendSession->getPlugin()->getValue('walleePendingRedirectUrl');

        if ($redirectUrl) {
            $this->frontendSession->getPlugin()->unsetKey('walleePendingRedirectUrl');

            return $this->response->redirectTo($redirectUrl);
        }

        // No redirect needed
        return $this->response->make('No redirect pending', 200);
    }

    public function payOrder(Request $request)
    {
        $orderId = $request->get('orderId', '');
        $paymentMethodId = $request->get('paymentMethod', '');

        /** @var AuthHelper $authHelper */
        $authHelper = pluginApp(AuthHelper::class);
        $orderRepo = $this->orderRepository;
        $order = $authHelper->processUnguarded(function () use ($orderId, $orderRepo) {
            return $orderRepo->findOrderById($orderId);
        });

        $this->switchPaymentMethodForOrder($order, $paymentMethodId);
        $result = $this->paymentService->executePayment($order, $this->paymentMethodService->findByPaymentMethodId($paymentMethodId));
        // Get the current language from session storage
        $lang = $this->sessionStorage->getLang();

        if ($result['type'] == GetPaymentMethodContent::RETURN_TYPE_REDIRECT_URL) {
            return $this->response->redirectTo($result['content']);
        } elseif (isset($result['transactionId'])) {
            if (isset($result['content'])) {
                $this->frontendSession->getPlugin()->setValue('walleePayErrorMessage', $result['content']);
            }
            // Construct the URL with the language
            $failUrl = sprintf('%s/wallee/fail-transaction/%s', $lang, $result['transactionId']);
            return $this->response->redirectTo($failUrl);
        } else {
            $confirmUrl = sprintf('%s/confirmation', $lang);
            return $this->response->redirectTo($confirmUrl);
        }
    }

    private function switchPaymentMethodForOrder(Order $order, $paymentMethodId)
    {
        $orderId = $order->id;
        $orderRepo = $this->orderRepository;
        $currentPaymentMethodId = 0;
        $newOrderProperties = [];
        $orderProperties = $order->properties;

        if (count($orderProperties)) {
            foreach ($orderProperties as $key => $orderProperty) {
                $newOrderProperties[$key] = [
                    'typeId' => $orderProperty->typeId,
                    'value' => (string) $orderProperty->value
                ];
                if ($orderProperty->typeId == OrderPropertyType::PAYMENT_METHOD) {
                    $currentPaymentMethodId = (int) $orderProperty->value;
                    $newOrderProperties[$key]['value'] = (string) $paymentMethodId;
                }
            }
        }

        if ($paymentMethodId !== $currentPaymentMethodId) {
            /** @var AuthHelper $authHelper */
            $authHelper = pluginApp(AuthHelper::class);
            $order = $authHelper->processUnguarded(function () use ($orderId, $newOrderProperties, $orderRepo) {
                return $orderRepo->updateOrder([
                    'properties' => $newOrderProperties
                ], $orderId);
            });

            if (! is_null($order)) {
                return $order;
            }
        } else {
            return $order;
        }
    }

    private function getPaymentMethodListForSwitch($paymentMethodId, $orderId)
    {
        $lang = $this->sessionStorage->getLang();
        $paymentMethods = $this->frontendPaymentMethodRepository->getCurrentPaymentMethodsList();
        $paymentMethodsForSwitch = [];
        foreach ($paymentMethods as $paymentMethod) {
            if ($paymentMethod->pluginKey == 'wallee') {
                $paymentMethodsForSwitch[] = [
                    'id' => $paymentMethod->id,
                    'name' => $this->frontendPaymentMethodRepository->getPaymentMethodName($paymentMethod, $lang),
                    'icon' => $this->frontendPaymentMethodRepository->getPaymentMethodIcon($paymentMethod, $lang),
                    'description' => $this->frontendPaymentMethodRepository->getPaymentMethodDescription($paymentMethod, $lang)
                ];
            }
        }
        return $paymentMethodsForSwitch;
    }

    private function allowSwitchPaymentMethod($orderId)
    {
        /** @var AuthHelper $authHelper */
        $authHelper = pluginApp(AuthHelper::class);
        $orderRepo = $this->orderRepository;

        $order = $authHelper->processUnguarded(function () use ($orderId, $orderRepo) {
            return $orderRepo->findOrderById($orderId);
        });

        if ($order->paymentStatus !== OrderPaymentStatus::UNPAID) {
            // order was paid
            return false;
        }

        $statusId = $order->statusId;
        $orderCreatedDate = $order->createdAt;

        if ($this->checkOrderRetryStatus($statusId)
            || $statusId <= 3.4
            || ($statusId == 5 && $orderCreatedDate->toDateString() == date('Y-m-d'))) {
            return true;
        } else {
            return false;
        }
    }
    
    private function checkOrderRetryStatus($statusId) {
        $orderRetryStatusString = $this->config->get('wallee.order_retry_status');
        if (!empty($orderRetryStatusString)) {
            $orderRetryStatus = array_map('trim', explode(';', $orderRetryStatusString));
            return in_array($statusId, $orderRetryStatus);
        } else {
            return false;
        }
    }

    /**
     * Returns the failure message for a Wallee transaction — PWA equivalent of the CERES
     * NotificationService::error() alert. The PWA extracts the transactionId from the
     * payment-selection/{orderId}/{transactionId} URL and calls this endpoint on mount
     * to show the decline reason
     *
     * @param int $id Wallee transaction ID
     * @return Response
     */
    public function getTransactionFailure(int $id)
    {
        $transaction = $this->sdkService->call('getTransaction', ['id' => $id]);

        if (is_array($transaction) && isset($transaction['error'])) {
            return $this->response->make(
                json_encode(['message' => null]),
                200,
                ['Content-Type' => 'application/json'],
            );
        }

        $this->paymentHelper->updatePlentyPayment($transaction);

        $message = !empty($transaction['userFailureMessage']) ? $transaction['userFailureMessage'] : null;

        return $this->response->make(
            json_encode(['message' => $message]),
            200,
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * Register return url for PWA
     *
     * @param Request $request
     * @return Response
     */
    public function registerReturnContext(Request $request)
    {

        $originUrl = $request->input('originUrl');
        $requestLang = $request->input('lang');
        $lang = $requestLang;
        if (!$lang) {
            // Fall back to the webstore default language instead of hardcoding 'en'
            /** @var \Plenty\Modules\Helper\Services\WebstoreHelper $webstoreHelper */
            $webstoreHelper = pluginApp(\Plenty\Modules\Helper\Services\WebstoreHelper::class);
            $lang = $webstoreHelper->getCurrentWebstoreConfiguration()->defaultLanguage;
        }

        if ($originUrl) {
            $this->frontendSession->getPlugin()->setValue('walleeOriginUrl', $originUrl);
            $this->frontendSession->getPlugin()->setValue('walleeOriginLang', $lang);
            return $this->response->json(['ok' => true]);
        }
        return $this->response->json(['ok' => false], 400);
    }

    /**
     * Restore cart for PWA
     *
     * @param Request $request
     * @return Response
     */
    public function restoreCart(Request $request)
    {
        $orderId = $request->input('orderId');

        if (!$orderId) {
            return $this->response->json(['ok' => false, 'reason' => 'no orderId'], 400);
        }
        $order = $this->orderRepository->findOrderById($orderId);

        if (!$order) {
            return $this->response->json(['ok' => false, 'reason' => 'no order found'], 400);
        }

        $isWalleePayment = $this->paymentHelper->isWalleePaymentMopId($order->methodOfPaymentId);

        if (!$isWalleePayment) {
            return $this->response->json(['ok' => false, 'reason' => 'order does not belong to wallee payment method'], 403);
        }

        foreach ($order->orderItems as $item) {
            if ($item->typeId !== 1) continue;
            $this->basketItemRepository->addBasketItem([
                'variationId' => $item->itemVariationId,
                'quantity' => $item->quantity,
            ]);
        }

        return $this->response->json(['ok' => true]);
    }

    /**
     * Get order checkout data for PWA payment retry
     * Returns order info and available payment methods for retrying payment on an existing order
     *
     * @param Request $request
     * @return Response
     */
    public function getOrderCheckoutData(Request $request)
    {
        try {
            $orderId = $request->get('orderId', '');

            if (empty($orderId)) {
                return $this->response->make(
                    json_encode(['error' => 'orderId is required']),
                    400,
                    ['Content-Type' => 'application/json'],
                );
            }

            /** @var AuthHelper $authHelper */
            $authHelper = pluginApp(AuthHelper::class);
            $orderRepo = $this->orderRepository;
            $order = $authHelper->processUnguarded(function () use ($orderId, $orderRepo) {
                return $orderRepo->findOrderById($orderId);
            });

            if (!$order) {
                return $this->response->make(
                    json_encode(['error' => 'Order not found']),
                    404,
                    ['Content-Type' => 'application/json'],
                );
            }

            // Verify the order belongs to a Wallee payment method
            if (!$this->paymentHelper->isWalleePaymentMopId($order->methodOfPaymentId)) {
                return $this->response->make(
                    json_encode(['error' => 'Order does not use a Wallee payment method']),
                    403,
                    ['Content-Type' => 'application/json'],
                );
            }

            $allowRetry = $this->allowSwitchPaymentMethod($orderId);
            $currentPaymentMethodId = $this->orderHelper->getOrderPropertyValue(
                $order,
                OrderPropertyType::PAYMENT_METHOD,
            );
            $paymentMethods = $allowRetry
                ? $this->getPaymentMethodListForSwitch($currentPaymentMethodId, $orderId)
                : [];

            // Build order summary for the PWA UI
            $totals = pluginApp(OrderTotalsService::class)->getAllTotals($order);
            $orderData = [
                'orderId' => $order->id,
                'createdAt' => (string) $order->createdAt,
                'statusId' => $order->statusId,
                'amounts' => $order->amounts,
                'billingAddress' => $order->billingAddress,
                'deliveryAddress' => $order->deliveryAddress,
                'orderItems' => [],
                'totals' => $totals,
            ];

            // Extract only the fields the PWA needs from each order item
            foreach ($order->orderItems as $item) {
                $orderData['orderItems'][] = [
                    'orderItemName' => $item->orderItemName,
                    'quantity' => $item->quantity,
                    'amounts' => $item->amounts,
                    'typeId' => $item->typeId,
                    'itemVariationId' => $item->itemVariationId,
                ];
            }

            return $this->response->make(
                json_encode([
                    'allowRetry' => $allowRetry,
                    'currentPaymentMethodId' => $currentPaymentMethodId,
                    'paymentMethods' => $paymentMethods,
                    'orderData' => $orderData,
                ]),
                200,
                ['Content-Type' => 'application/json'],
            );
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Wallee::GetOrderCheckoutDataException', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->response->make(
                json_encode(['error' => 'Failed to load order checkout data']),
                500,
                ['Content-Type' => 'application/json'],
            );
        }
    }

    /**
     * REST endpoint for PWA to retry payment on an existing order
     * Switches payment method and creates a new Wallee transaction for the same order
     *
     * @param Request $request
     * @return Response
     */
    public function payOrderRest(Request $request)
    {
        try {
            $orderId = $request->get('orderId', '');
            $paymentMethodId = $request->get('paymentMethodId', '');

            if (empty($orderId) || empty($paymentMethodId)) {
                return $this->response->make(
                    json_encode(['error' => 'orderId and paymentMethodId are required']),
                    400,
                    ['Content-Type' => 'application/json'],
                );
            }

            /** @var AuthHelper $authHelper */
            $authHelper = pluginApp(AuthHelper::class);
            $orderRepo = $this->orderRepository;
            $order = $authHelper->processUnguarded(function () use ($orderId, $orderRepo) {
                return $orderRepo->findOrderById($orderId);
            });

            if (!$order) {
                return $this->response->make(
                    json_encode(['error' => 'Order not found']),
                    404,
                    ['Content-Type' => 'application/json'],
                );
            }

            // Validate the order is eligible for payment retry
            if (!$this->allowSwitchPaymentMethod($orderId)) {
                return $this->response->make(
                    json_encode(['error' => 'Payment retry is not allowed for this order']),
                    403,
                    ['Content-Type' => 'application/json'],
                );
            }

            // Switch payment method on the existing order
            $this->switchPaymentMethodForOrder($order, $paymentMethodId);

            // Re-load the order to get updated properties after payment method switch
            $order = $authHelper->processUnguarded(function () use ($orderId, $orderRepo) {
                return $orderRepo->findOrderById($orderId);
            });

            // Execute payment using the updated order, creating a new Wallee transaction
            $paymentMethod = $this->paymentMethodService->findByPaymentMethodId($paymentMethodId);
            $result = $this->paymentService->executePayment($order, $paymentMethod);

            $type = $result['type'] ?? '';
            $content = $result['content'] ?? $result['redirectUrl'] ?? null;

            // Return redirect URL as JSON so the PWA can handle navigation client-side
            if ($type === GetPaymentMethodContent::RETURN_TYPE_REDIRECT_URL) {
                return $this->response->make(
                    json_encode([
                        'redirectUrl' => $content,
                        'orderId' => $orderId,
                    ]),
                    200,
                    ['Content-Type' => 'application/json'],
                );
            }

            // Payment processing error from Wallee
            if ($type === GetPaymentMethodContent::RETURN_TYPE_ERROR) {
                return $this->response->make(
                    json_encode([
                        'error' => $content ?? 'Payment processing failed',
                        'transactionId' => $result['transactionId'] ?? null,
                    ]),
                    400,
                    ['Content-Type' => 'application/json'],
                );
            }

            // Fallback for continue type or unknown response
            return $this->response->make(
                json_encode([
                    'status' => 'continue',
                    'orderId' => $orderId,
                ]),
                200,
                ['Content-Type' => 'application/json'],
            );
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Wallee::PayOrderRestException', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->response->make(
                json_encode(['error' => 'Failed to process payment retry']),
                500,
                ['Content-Type' => 'application/json'],
            );
        }
    }
}