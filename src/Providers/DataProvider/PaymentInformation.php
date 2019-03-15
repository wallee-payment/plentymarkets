<?php
namespace Wallee\Providers\DataProvider;

use Plenty\Plugin\Templates\Twig;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\Payment\Models\Payment;
use Plenty\Modules\Payment\Models\PaymentProperty;
use Wallee\Services\WalleeSdkService;
use Plenty\Plugin\Log\LoggerFactory;

class PaymentInformation
{

    public function call(Twig $twig, $arg): string
    {
        $logger = pluginApp(LoggerFactory::class)->getLogger('Wallee', __METHOD__);

        $order = $arg[0];
        $logger->error('wallee::payment information order', $order);

        $payments = pluginApp(PaymentRepositoryContract::class)->getPaymentsByOrderId($order['id']);
        foreach ($payments as $payment) {
            $logger->error('wallee::payment information payment', $payment);
            if ($payment->status != Payment::STATUS_CANCELED) {
                $transactionId = null;
                foreach ($payment->properties as $property) {
                    if ($property->typeId == PaymentProperty::TYPE_TRANSACTION_ID) {
                        $transactionId = $property->value;
                    }
                }
                $logger->error('wallee::payment information transaction id', $transactionId);
                if (! empty($transactionId)) {
                    $transaction = pluginApp(WalleeSdkService::class)->call('getTransaction', [
                        'id' => $transactionId
                    ]);
                    if (is_array($transaction) && isset($transaction['error'])) {
                        return "";
                    } else {
                        $logger->error('wallee::payment information transaction', $transaction);
                        return $twig->render('wallee::PaymentInformation', [
                            'order' => $order,
                            'transaction' => $transaction,
                            'payment' => $payment
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