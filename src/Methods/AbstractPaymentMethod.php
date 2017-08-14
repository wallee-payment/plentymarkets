<?php
namespace Wallee\Methods;

use Plenty\Modules\Payment\Method\Contracts\PaymentMethodService;
use Plenty\Plugin\ConfigRepository;
use Wallee\Services\PaymentService;

abstract class AbstractPaymentMethod extends PaymentMethodService
{

    /**
     *
     * @var ConfigRepository
     */
    protected $configRepo;

    /**
     *
     * @var PaymentService
     */
    protected $paymentService;

    /**
     * Constructor.
     *
     * @param ConfigRepository $configRepo
     * @param PaymentService $paymentService
     */
    public function __construct(ConfigRepository $configRepo, PaymentService $paymentService)
    {
        $this->configRepo = $configRepo;
        $this->paymentService = $paymentService;
    }

    protected function getBaseIconPath()
    {
        return \Wallee\Services\WalleeSdkService::GATEWAY_BASE_PATH . '/s/' . $this->configRepo->get('wallee.space_id') . '/resource/icon/payment/method/';
    }
}