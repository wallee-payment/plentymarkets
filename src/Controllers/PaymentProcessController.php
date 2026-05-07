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

        return $twig->render('Wallee::Failure', $templateData);
    }


    public function returnFailed(int $transactionId)
    {
        $this->getLogger(__METHOD__)->error('Wallee::ReturnFailedHit', [
            'transactionId' => $transactionId,
        ]);

        $transaction = $this->sdkService->call('getTransaction', [
            'id' => $transactionId
        ]);

        if (is_array($transaction) && isset($transaction['error'])) {
            $this->getLogger(__METHOD__)->error('Wallee::ReturnFailedLookupFailed', [
                'transactionId' => $transactionId,
                'transaction' => $transaction,
            ]);
            return $this->redirectToCheckout();
        }

        // $state = $transaction['state'] ?? null;
        // $this->getLogger(__METHOD__)->error('Wallee::ReturnFailedState', [
        //     'transactionId' => $transactionId,
        //     'state' => $state,
        // ]);

        $payments = $this->paymentRepository->getPaymentsByPropertyTypeAndValue(PaymentProperty::TYPE_TRANSACTION_ID, $transaction['id']);
        $payment = !empty($payments) ? $payments[0] : null;

        $this->getLogger(__METHOD__)->error('Wallee::ReturnFailedPayments', [
            'payments' => $payments,
            'payment' => $payment,
        ]);

        $order = null;
        $paymentMethodId = null;

        if ($payment) {
            $this->getLogger(__METHOD__)->error('Wallee::ReturnFailedPaymentIsPresent', []);
            $orderRelation = $this->paymentOrderRelationRepository->findOrderRelation($payment);
            $this->getLogger(__METHOD__)->error('Wallee::ReturnFailedPaymentOrderRelation', [
                'orderRelation' => $orderRelation
            ]);
            if ($orderRelation) {
                $order = $this->orderRepository->findOrderById($orderRelation->orderId);
            }
        }

        $this->getLogger(__METHOD__)->error('Wallee::ReturnFailedOrderAfterFirstLookup', [
            'order' => $order
        ]);

        // Keep the order in its current unpaid state so it can be reused for payment retry
        if ($order) {
            $this->getLogger(__METHOD__)->error('Wallee::OrderKeptForRetry', [
                'orderId' => $order->id,
                'statusId' => $order->statusId,
            ]);
        }

        $this->paymentHelper->updatePlentyPayment($transaction);

        $this->getLogger(__METHOD__)->error('Wallee::OrderCanceledAfterPlentyPaymentUpdate', [
           'transaction' => $transaction,
        ]);

        if (!empty($transaction['userFailureMessage'])) {
            $this->getLogger(__METHOD__)->error('Wallee::OrderCanceledSetFailReason', [
                'userFailureMessage' => $transaction['userFailureMessage'],
            ]);
            $this->frontendSession->getPlugin()->setValue(
                'walleePayErrorMessage',
                $transaction['userFailureMessage']
            );
        }
        return $this->redirectToCheckout($order ? $order->id : null);
    }

    /**
     * Redirect to checkout for payment retry
     * For PWA: redirects to payment-selection page with orderId when available
     * For Ceres: redirects to standard checkout page
     *
     * @param int|null $orderId
     */
    private function redirectToCheckout(?int $orderId = null)
    {
        $this->getLogger(__METHOD__)->error('Wallee::RedirectToCheckoutPWAHit', [
            'orderId' => $orderId,
        ]);
        $originUrl = $this->frontendSession->getPlugin()->getValue('walleeOriginUrl');
        $this->getLogger(__METHOD__)->error('Wallee::RedirectToCheckoutPWAOriginUrlCheck', [
            'originUrl' => $originUrl,
            'orderId' => $orderId,
        ]);
        if ($originUrl) {
            if ($orderId) {
                // PWA: redirect to payment selection page with orderId for retry
                $url = sprintf(
                    '%s/checkout/payment-selection?orderId=%d',
                    rtrim($originUrl, '/'),
                    $orderId,
                );
            } else {
                // PWA fallback: no order available, redirect to checkout with failure flag
                $url = sprintf('%s/checkout?wallee_failed=1', rtrim($originUrl, '/'));
            }
            $this->getLogger(__METHOD__)->error('Wallee::RedirectToCheckoutRedirectToPwa', [
                'url' => $url,
            ]);
            return $this->response->redirectTo($url);
        }
        $lang = $this->sessionStorage->getLang();
        $domain = $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl;
        $url = sprintf('%s/%s/checkout', $domain, $lang);
        $this->getLogger(__METHOD__)->error('Wallee::RedirectToCheckoutRedirectToOG', [
            'url' => $url,
        ]);
        return $this->response->redirectTo($url);
    }

    /**
     * Prepare payment for PWA (before order is created)
     *
     * @param Request $request
     * @return Response
     */
    public function preparePayment(Request $request)
    {
        $paymentMethodId = $request->get('paymentMethodId', '');
        
        $this->getLogger(__METHOD__)->error('Wallee::PreparePayment_CALLED', [
            'paymentMethodId' => $paymentMethodId,
            'requestData' => $request->all()
        ]);
        
        try {
            if (empty($paymentMethodId)) {
                return $this->response->json([
                    'type' => 'error',
                    'value' => 'Payment method ID is required'
                ]);
            }
            
            // Get the payment method
            $paymentMethod = $this->paymentMethodService->findByPaymentMethodId($paymentMethodId);
            
            if (!$paymentMethod) {
                return $this->response->json([
                    'type' => 'error',
                    'value' => 'Payment method not found'
                ]);
            }
            
            // Check if this is a Wallee method
            if (!$this->paymentHelper->isWalleePaymentMopId($paymentMethodId)) {
                return $this->response->json([
                    'type' => 'continue',
                    'value' => ''
                ]);
            }
            
            // Execute payment from basket (PWA flow)
            $result = $this->paymentService->executePaymentFromBasket($paymentMethod);
            
            $this->getLogger(__METHOD__)->error('Wallee::PreparePaymentResult', [
                'result' => $result
            ]);
            
            return $this->response->json([
                'type' => $result['type'] === GetPaymentMethodContent::RETURN_TYPE_REDIRECT_URL ? 'redirectUrl' : ($result['type'] === GetPaymentMethodContent::RETURN_TYPE_ERROR ? 'error' : 'continue'),
                'value' => $result['content'] ?? ''
            ]);
            
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Wallee::PreparePaymentException', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->response->json([
                'type' => 'error',
                'value' => 'An error occurred while preparing the payment'
            ]);
        }
    }

    /**
     * Check if there's a pending payment redirect for PWA
     * PWA ignores ExecutePayment redirects, so we store them and check later
     *
     * @return Response
     */
    public function checkPendingRedirect()
    {
        try {
            $redirectUrl = $this->frontendSession->getPlugin()->getValue('walleePendingRedirectUrl');
            $orderId = $this->frontendSession->getPlugin()->getValue('walleeOrderId');
            
            $this->getLogger(__METHOD__)->error('Wallee::CheckingPendingRedirect', [
                'redirectUrl' => $redirectUrl ?? 'null',
                'orderId' => $orderId ?? 'null'
            ]);
            
            if ($redirectUrl) {
                // Don't clear yet - PWA might retry
                // $this->frontendSession->getPlugin()->unsetKey('walleePendingRedirectUrl');
                
                $this->getLogger(__METHOD__)->error('Wallee::ReturningPendingRedirect', [
                    'redirectUrl' => $redirectUrl,
                    'orderId' => $orderId
                ]);
                
//                return $this->response->json([
//                    'redirectUrl' => $redirectUrl,
//                    'orderId' => $orderId
//                ]);
                return $this->response->make(
                    json_encode([
                        'redirectUrl' => $redirectUrl,
                        'orderId' => $orderId
                    ]),
                    200,
                    ['Content-Type' => 'application/json']
                );
            }

            return $this->response->make(
                json_encode([
                    'redirectUrl' => null
                ]),
                200,
                ['Content-Type' => 'application/json']
            );
//            return $this->response->json([
//                'redirectUrl' => null
//            ]);
            
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Wallee::CheckRedirectException', [
                'message' => $e->getMessage()
            ]);
            
//            return $this->response->json([
//                'redirect' => false,
//                'error' => $e->getMessage()
//            ]);
            return $this->response->make(
                json_encode([
                    'redirect' => false,
                    'error' => $e->getMessage()
                ]),
                500,
                ['Content-Type' => 'application/json']
            );
        }
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
            
            $this->getLogger(__METHOD__)->error('Wallee::AutoRedirecting', [
                'redirectUrl' => $redirectUrl
            ]);
            
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
     * Register return url for PWA
     *
     * @param Request $request
     * @return Response
     */
    public function registerReturnContext(Request $request)
    {
        $this->getLogger(__METHOD__)->error('Wallee::sessionSet', [
            'cookie' => $request->header('Cookie'),
            'sessionClass' => get_class($this->frontendSession),
        ]);
        $this->getLogger(__METHOD__)->error('Wallee::registerReturnContextHit', [
            'request' => $request
        ]);
        $originUrl = $request->input('originUrl');
        $lang = $request->input('lang') ?: 'en';

        $this->getLogger(__METHOD__)->error('Wallee::registerReturnContextVars', [
            'originUrl' => $originUrl,
            'lang' => $lang,
        ]);

        if ($originUrl) {
            $this->getLogger(__METHOD__)->error('Wallee::registerReturnContextSetToSession', []);
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
        $this->getLogger(__METHOD__)->error('Wallee::restoreCartOrderId', [
            'orderId' => $orderId,
        ]);
        if (!$orderId) {
            return $this->response->json(['ok' => false, 'reason' => 'no orderId'], 400);
        }
        $order = $this->orderRepository->findOrderById($orderId);
        $this->getLogger(__METHOD__)->error('Wallee::restoreCartOrder', [
            'order' => $order,
        ]);
        if (!$order) {
            return $this->response->json(['ok' => false, 'reason' => 'no order found'], 400);
        }
        // $paymentMethodId = $this->orderHelper->getOrderPropertyValue($order, OrderPropertyType::PAYMENT_METHOD);
        // if (!$paymentMethodId || !$this->paymentHelper->isWalleePaymentMopId($paymentMethodId)) {
        $isWalleePayment = $this->paymentHelper->isWalleePaymentMopId($order->methodOfPaymentId);
        $this->getLogger(__METHOD__)->error('Wallee::restoreCartIsWalleePayment', [
            'isWalleePayment' => $isWalleePayment,
        ]);
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

        $this->getLogger(__METHOD__)->error('Wallee::restoreCartFinish', []);

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

            $this->getLogger(__METHOD__)->error('Wallee::GetOrderCheckoutData', [
                'orderId' => $orderId,
                'allowRetry' => $allowRetry,
                'paymentMethodCount' => count($paymentMethods),
            ]);

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

            $this->getLogger(__METHOD__)->error('Wallee::PayOrderRestResult', [
                'orderId' => $orderId,
                'paymentMethodId' => $paymentMethodId,
                'resultType' => $result['type'] ?? 'unknown',
            ]);

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