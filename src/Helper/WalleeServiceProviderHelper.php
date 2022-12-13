<?php
namespace Wallee\Helper;

use IO\Services\BasketService;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Modules\Payment\Events\Checkout\GetPaymentMethodContent;
use Plenty\Modules\Payment\Events\Checkout\ExecutePayment;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Events\Dispatcher;
use Wallee\Helper\PaymentHelper;
use Wallee\Services\PaymentService;
use Wallee\Services\WalleeSdkService;

class WalleeServiceProviderHelper
{
    /**
     * @var $eventDispatcher
     */
    private $eventDispatcher;
    
    /**
     * @var $paymentHelper
     */
    private $paymentHelper;
    
    /**
     * @var $basketRepository
     */
    private $basketRepository;
    
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
     * @var $sdkService
     */
    private $sdkService;

    /**
     *
     * @var ConfigRepository
     */
    private $config;

    /**
     * Construct the helper
     *
     * @param  Dispatcher $eventDispatcher
     * @param  PaymentHelper $paymentHelper
     * @param  BasketRepositoryContract $basketRepository
     * @param  OrderRepositoryContract $orderRepository
     * @param  PaymentService $paymentService
     * @param  PaymentMethodRepositoryContract $paymentMethodService
     * @param  WalleeSdkService $sdkService
     * @param  ConfigRepository $config
     */
    public function __construct(
        Dispatcher $eventDispatcher,
        PaymentHelper $paymentHelper,
        BasketRepositoryContract $basketRepository,
        OrderRepositoryContract $orderRepository,
        PaymentService $paymentService,
        PaymentMethodRepositoryContract $paymentMethodService,
        WalleeSdkService $sdkService, 
        ConfigRepository $config
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->paymentHelper = $paymentHelper;
        $this->basketRepository = $basketRepository;
        $this->orderRepository = $orderRepository;
        $this->paymentService = $paymentService;
        $this->paymentMethodService = $paymentMethodService;
        $this->sdkService = $sdkService;
        $this->config = $config;
    }

    /**
     * Adds the payment content event listener
     * @return never
     */
    public function addPaymentMethodContentEventListener() {
        $this->eventDispatcher->listen(GetPaymentMethodContent::class, function (GetPaymentMethodContent $event) {
            if ($this->paymentHelper->isWalleePaymentMopId($event->getMop())) {
                $result = $this->paymentService->getPaymentContent(
                    $this->basketRepository->load(),
                    pluginApp(BasketService::class)->getBasketForTemplate(),
                    $this->paymentMethodService->findByPaymentMethodId($event->getMop())
                );
                $event->setValue(isset($result['content']) ? $result['content'] : null);
                $event->setType(isset($result['type']) ? $result['type'] : '');
            }
        });
    }

    /**
     * Adds the execute payment content event listener
     * @return never
     */
    public function addExecutePaymentContentEventListener() {
        $this->eventDispatcher->listen(ExecutePayment::class, function (ExecutePayment $event) {
            if ($this->paymentHelper->isWalleePaymentMopId($event->getMop())) {
                $result = $this->paymentService->executePayment(
                    $this->orderRepository->findById($event->getOrderId()), 
                    $this->paymentMethodService->findByPaymentMethodId($event->getMop())
                );
                $event->setValue(isset($result['content']) ? $result['content'] : null);
                $event->setType(isset($result['type']) ? $result['type'] : '');
            }
        });
    }

    /**
     * Creates the webhook
     * @return never
     */
    public function createWebhook()
    {
        /** @var \Plenty\Modules\Helper\Services\WebstoreHelper $webstoreHelper */
        $webstoreHelper = pluginApp(\Plenty\Modules\Helper\Services\WebstoreHelper::class);
        /** @var \Plenty\Modules\System\Models\WebstoreConfiguration $webstoreConfig */
        $webstoreConfig = $webstoreHelper->getCurrentWebstoreConfiguration();
        $this->sdkService->call('createWebhook', [
            'storeId' => $webstoreConfig->webstoreId,
            'notificationUrl' => $webstoreConfig->domainSsl . '/wallee/update-transaction' . ($this->config->get('plenty.system.info.urlTrailingSlash', 0) == 2 ? '/' : '')
        ]);
    }
}
