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
     * Adds the execute payment content event listener
     * @return never
     */
    public function addExecutePaymentContentEventListener() {
        $this->eventDispatcher->listen(ExecutePayment::class, function (ExecutePayment $event) {
            if ($this->paymentHelper->isWalleePaymentMopId($event->getMop())) {
                
                $event['orderId'] = $this->orderRepository->findById($event->getOrderId());
                $event['mop'] = $this->paymentMethodService->findByPaymentMethodId($event->getMop());

                $this->getLogger(__METHOD__)->info('logExecutPaymentEvent', $event);

                $result = $this->paymentService->executePayment(
                    $event['orderId'],
                    $event['mop']
                );
                $event->setValue(isset($result['content']) ? $result['content'] : null);
                $event->setType(isset($result['type']) ? $result['type'] : '');
            }
        });
    }
}
