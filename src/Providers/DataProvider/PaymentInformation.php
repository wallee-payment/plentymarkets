<?php
namespace Wallee\Providers\DataProvider;

use Plenty\Plugin\Templates\Twig;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\Payment\Models\Payment;
use Plenty\Modules\Payment\Models\PaymentProperty;
use Wallee\Helper\OrderAccessHelper;
use Wallee\Services\WalleeSdkService;
use Plenty\Plugin\ConfigRepository;

class PaymentInformation
{

    public function call(Twig $twig, $arg): string
    {
        $order = $arg[0];
        $payments = pluginApp(PaymentRepositoryContract::class)->getPaymentsByOrderId($order['id']);
        foreach (array_reverse($payments) as $payment) {
            if ($payment->status != Payment::STATUS_CANCELED) {
                $transactionId = null;
                foreach ($payment->properties as $property) {
                    if ($property->typeId == PaymentProperty::TYPE_TRANSACTION_ID) {
                        $transactionId = $property->value;
                    }
                }
                if (! empty($transactionId)) {
                    $transaction = pluginApp(WalleeSdkService::class)->call('getTransaction', [
                        'id' => $transactionId
                    ]);
                    if (is_array($transaction) && isset($transaction['error'])) {
                        return "";
                    } else {
                        return $twig->render('wallee::PaymentInformation', [
                            'order' => $order,
                            'transaction' => $transaction,
                            'payment' => $payment,
                            // The download links carry the order access key, it is the token the
                            // document controller validates before handing out a document.
                            'accessKey' => pluginApp(OrderAccessHelper::class)->getOrderAccessKey((int) $order['id']),
                            'downloadInvoice' => pluginApp(ConfigRepository::class)->get('wallee.confirmation_invoice') == "true",
                            'downloadPackingSlip' => pluginApp(ConfigRepository::class)->get('wallee.confirmation_packing_slip') == "true"
                        ]);
                    }
                } else {
                    return "";
                }
            }
        }

        return "";
    }
}
