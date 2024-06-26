<?php
namespace Wallee\Migrations;

use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Wallee\Helper\PaymentHelper;

class CreatePaymentMethods
{

    /**
     *
     * @var PaymentMethodRepositoryContract
     */
    private $paymentMethodRepositoryContract;

    /**
     *
     * @var PaymentHelper
     */
    private $paymentHelper;

    /**
     * Constructor.
     *
     * @param PaymentMethodRepositoryContract $paymentMethodRepositoryContract
     * @param PaymentHelper $paymentHelper
     */
    public function __construct(PaymentMethodRepositoryContract $paymentMethodRepositoryContract, PaymentHelper $paymentHelper)
    {
        $this->paymentMethodRepositoryContract = $paymentMethodRepositoryContract;
        $this->paymentHelper = $paymentHelper;
    }

    /**
     * Creates the payment methods for the wallee plugin.
     */
    public function run()
    {
        $this->createPaymentMethod(1689233132073, 'Postfinance Pay');
    }

    private function createPaymentMethod($id, $name)
    {
        if ($this->paymentHelper->getPaymentMopId($id) == 'no_paymentmethod_found') {
            $this->paymentMethodRepositoryContract->createPaymentMethod([
                'pluginKey' => 'wallee',
                'paymentKey' => (string) $id,
                'name' => $name
            ]);
        }
    }
}