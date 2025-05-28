<?php
namespace Wallee\Procedures;

use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Payment\Models\PaymentProperty;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Wallee\Services\PaymentService;
use Wallee\Helper\PaymentHelper;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;

class RefundEventProcedure
{
    use Loggable;

    public function run(EventProceduresTriggered $eventTriggered, PaymentService $paymentService, PaymentRepositoryContract $paymentContract, PaymentHelper $paymentHelper, OrderRepositoryContract $orderRepository)
    {
        /** @var Order $order */
        $refund = $eventTriggered->getOrder();

        $this->getLogger(__METHOD__)->error('wallee::RefundData', [
                'refundId' => $refund->id,
                'refundKeys' => array_keys($refund)
            ]);

        // only sales orders and credit notes are allowed order types to refund
        switch ($refund->typeId) {
            case 1: // sales order
                $orderId = $refund->id;
                break;
            case 4: // credit note
                $originOrders = $refund->originOrders;
                if (! $originOrders->isEmpty() && $originOrders->count() > 0) {
                    $originOrder = $originOrders->first();

                    if ($originOrder instanceof Order) {
                        if ($originOrder->typeId == 1) {
                            $orderId = $originOrder->id;
                        } else {
                            $originOriginOrders = $originOrder->originOrders;
                            if (is_array($originOriginOrders) && count($originOriginOrders) > 0) {
                                $originOriginOrder = $originOriginOrders->first();
                                if ($originOriginOrder instanceof Order) {
                                    $orderId = $originOriginOrder->id;
                                }
                            }
                        }
                    }
                }
                break;
        }

        if (empty($orderId)) {
            throw new \Exception('Refund wallee payment failed! The given order is invalid!');
        }

        /** @var Payment[] $payment */
        $payments = $paymentContract->getPaymentsByOrderId($orderId);

        /** @var Payment $payment */
        foreach ($payments as $payment) {
            if ($paymentHelper->isWalleePaymentMopId($payment->mopId)) {
                $transactionId = (int) $paymentHelper->getPaymentPropertyValue($payment, PaymentProperty::TYPE_TRANSACTION_ID);
                if ($transactionId > 0) {
                    // refund the payment
                    $order = $orderRepository->findOrderById($orderId);
                    $paymentService->refund($transactionId, $refund, $order);
                }
            }
        }
    }
}