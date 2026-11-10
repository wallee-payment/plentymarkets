<?php
namespace Wallee\Migrations;

use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Plugin\Log\Loggable;
use Wallee\Helper\PaymentHelper;

class CreatePaymentMethods
{

    use Loggable;

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
        $result = [];

        $result['Alipay'] = $this->createPaymentMethod(1457546097615, 'Alipay');
        $result['Bank Transfer'] = $this->createPaymentMethod(1457546097602, 'Bank Transfer');
        $result['CASHU'] = $this->createPaymentMethod(1477573906453, 'CASHU');
        $result['Credit / Debit Card'] = $this->createPaymentMethod(1457546097597, 'Credit / Debit Card');
        $result['DaoPay'] = $this->createPaymentMethod(1477574926155, 'DaoPay');
        $result['Direct Debit (SEPA)'] = $this->createPaymentMethod(1457546097601, 'Direct Debit (SEPA)');
        $result['Direct Debit (UK)'] = $this->createPaymentMethod(1464254757862, 'Direct Debit (UK)');
        $result['EPS'] = $this->createPaymentMethod(1457546097609, 'EPS');
        $result['Giropay'] = $this->createPaymentMethod(1457546097610, 'Giropay');
        $result['iDeal'] = $this->createPaymentMethod(1461674005576, 'iDeal');
        $result['Invoice'] = $this->createPaymentMethod(1457546097598, 'Invoice');
        $result['MasterPass'] = $this->createPaymentMethod(1457546097621, 'MasterPass');
        $result['Online Banking'] = $this->createPaymentMethod(1460954915005, 'Online Banking');
        $result['paybox'] = $this->createPaymentMethod(1484231986107, 'paybox');
        $result['Paydirekt'] = $this->createPaymentMethod(1457546097640, 'Paydirekt');
        $result['Paylib'] = $this->createPaymentMethod(1476259715349, 'Paylib');
        $result['PayPal'] = $this->createPaymentMethod(1457546097613, 'PayPal');
        $result['paysafecard'] = $this->createPaymentMethod(1457546097612, 'paysafecard');
        $result['POLi'] = $this->createPaymentMethod(1457546097618, 'POLi');
        $result['Post Finance Pay'] = $this->createPaymentMethod(1689233132073, 'Post Finance Pay');
        $result['Przelewy24'] = $this->createPaymentMethod(1457546097617, 'Przelewy24');
        $result['QIWI'] = $this->createPaymentMethod(1457546097616, 'QIWI');
        $result['Skrill'] = $this->createPaymentMethod(1457546097614, 'Skrill');
        $result['SOFORT Banking'] = $this->createPaymentMethod(1457546097603, 'SOFORT Banking');
        $result['Tenpay'] = $this->createPaymentMethod(1477574502344, 'Tenpay');
        $result['Trustly'] = $this->createPaymentMethod(1457546097619, 'Trustly');
        $result['TWINT'] = $this->createPaymentMethod(1457546097639, 'TWINT');
        $result['Klarna Pay Now'] = $this->createPaymentMethod(1864086284039, 'Klarna Pay Now');
        $result['Klarna Pay Later'] = $this->createPaymentMethod(1864086284619, 'Klarna Pay Later');
        $result['Klarna Slice It'] = $this->createPaymentMethod(1864086284016, 'Klarna Slice It');

        // The deployment itself reports nothing about the payment methods, so this single summary
        // is what tells us afterwards whether they were registered, skipped or rejected.
        $this->getLogger(__METHOD__)->info('Wallee::PaymentMethodMigrationFinished', $result);
    }

    /**
     * Registers a single payment method and reports what happened with it.
     *
     * A store that cannot take another payment method makes createPaymentMethod() throw. Letting
     * that through would skip every payment method after it, so each one is registered on its own.
     *
     * @param number $id
     * @param string $name
     * @return string
     */
    private function createPaymentMethod($id, $name): string
    {
        try {
            if ($this->paymentHelper->getPaymentMopId($id) != 'no_paymentmethod_found') {
                return 'already exists';
            }

            $this->paymentMethodRepositoryContract->createPaymentMethod([
                'pluginKey' => 'wallee',
                'paymentKey' => (string) $id,
                'name' => $name
            ]);

            return 'created';
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Wallee::PaymentMethodCreationFailed', [
                'paymentKey' => $id,
                'name' => $name,
                'message' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }
}
