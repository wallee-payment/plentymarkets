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
     */
    public function __construct(Response $response, WalleeSdkService $sdkService, NotificationService $notificationService, PaymentService $paymentService, PaymentHelper $paymentHelper, PaymentRepositoryContract $paymentRepository, OrderRepositoryContract $orderRepository, PaymentOrderRelationRepositoryContract $paymentOrderRelationRepository, OrderHelper $orderHelper, OrderService $orderService, FrontendPaymentMethodRepositoryContract $frontendPaymentMethodRepository, PaymentMethodRepositoryContract $paymentMethodService, SessionStorageService $sessionStorage, FrontendSessionStorageFactoryContract $frontendSession, ConfigRepository $config)
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
                'type' => $result['type'] === GetPaymentMethodContent::RETURN_TYPE_REDIRECT_URL ? 'redirect' : ($result['type'] === GetPaymentMethodContent::RETURN_TYPE_ERROR ? 'error' : 'continue'),
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
                
                return $this->response->json([
                    'redirectUrl' => $redirectUrl,
                    'orderId' => $orderId
                ]);
            }
            
            return $this->response->json([
                'redirectUrl' => null
            ]);
            
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Wallee::CheckRedirectException', [
                'message' => $e->getMessage()
            ]);
            
            return $this->response->json([
                'redirect' => false,
                'error' => $e->getMessage()
            ]);
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
}