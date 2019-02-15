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
        switch ($this->configRepo->get('wallee.resource_version')) {
            case 'V1':
                return \Wallee\Services\WalleeSdkService::GATEWAY_BASE_PATH . '/s/' . $this->configRepo->get('wallee.space_id') . '/resource/icon/payment/method/';
            case 'V2':
                return \Wallee\Services\WalleeSdkService::GATEWAY_BASE_PATH . '/s/' . $this->configRepo->get('wallee.space_id') . '/resource/web/image/payment/method/';
            default:
                return \Wallee\Services\WalleeSdkService::GATEWAY_BASE_PATH . '/resource/web/image/payment/method/';
        }
    }

    protected function getImagePath($fileName)
    {
        return $this->getBaseIconPath() . $fileName . '?' . time();
    }
}