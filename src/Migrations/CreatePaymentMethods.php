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
        $this->createPaymentMethod(1457546097615, 'Alipay');
        $this->createPaymentMethod(1457546097602, 'Bank Transfer');
        $this->createPaymentMethod(1477573906453, 'CASHU');
        $this->createPaymentMethod(1457546097597, 'Credit / Debit Card');
        $this->createPaymentMethod(1477574926155, 'DaoPay');
        $this->createPaymentMethod(1457546097601, 'Direct Debit (SEPA)');
        $this->createPaymentMethod(1464254757862, 'Direct Debit (UK)');
        $this->createPaymentMethod(1457546097609, 'EPS');
        $this->createPaymentMethod(1457546097610, 'Giropay');
        $this->createPaymentMethod(1461674005576, 'iDeal');
        $this->createPaymentMethod(1457546097598, 'Invoice');
        $this->createPaymentMethod(1457546097621, 'MasterPass');
        $this->createPaymentMethod(1460954915005, 'Online Banking');
        $this->createPaymentMethod(1484231986107, 'paybox');
        $this->createPaymentMethod(1457546097640, 'Paydirekt');
        $this->createPaymentMethod(1476259715349, 'Paylib');
        $this->createPaymentMethod(1457546097613, 'PayPal');
        $this->createPaymentMethod(1457546097612, 'paysafecard');
        $this->createPaymentMethod(1457546097618, 'POLi');
        $this->createPaymentMethod(1689233132073, 'Postfinance Pay');
        $this->createPaymentMethod(1457546097617, 'Przelewy24');
        $this->createPaymentMethod(1457546097616, 'QIWI');
        $this->createPaymentMethod(1457546097614, 'Skrill');
        $this->createPaymentMethod(1457546097603, 'SOFORT Banking');
        $this->createPaymentMethod(1477574502344, 'Tenpay');
        $this->createPaymentMethod(1457546097619, 'Trustly');
        $this->createPaymentMethod(1457546097639, 'TWINT');
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