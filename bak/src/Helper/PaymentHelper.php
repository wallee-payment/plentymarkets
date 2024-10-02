<?php
namespace Wallee\Helper;

use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Payment\Contracts\PaymentOrderRelationRepositoryContract;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Modules\Payment\Models\Payment;
use Plenty\Modules\Payment\Models\PaymentProperty;
use Plenty\Plugin\Log\Loggable;

class PaymentHelper
{

    use Loggable;

    /**
     *
     * @var PaymentMethodRepositoryContract
     */
    private $paymentMethodRepository;

    /**
     *
     * @var PaymentRepositoryContract
     */
    private $paymentRepository;

    /**
     *
     * @var OrderRepositoryContract
     */
    private $orderRepository;

    /**
     *
     * @var PaymentOrderRelationRepositoryContract
     */
    private $paymentOrderRelationRepository;

    /**
     * Constructor.
     *
     * @param PaymentMethodRepositoryContract $paymentMethodRepository
     * @param PaymentRepositoryContract $paymentRepository
     * @param OrderRepositoryContract $orderRepository
     * @param PaymentOrderRelationRepositoryContract $paymentOrderRelationRepository
     */
    public function __construct(PaymentMethodRepositoryContract $paymentMethodRepository, PaymentRepositoryContract $paymentRepository, OrderRepositoryContract $orderRepository, PaymentOrderRelationRepositoryContract $paymentOrderRelationRepository)
    {
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->paymentRepository = $paymentRepository;
        $this->orderRepository = $orderRepository;
        $this->paymentOrderRelationRepository = $paymentOrderRelationRepository;
    }

    /**
     * Returns the wallee payment method's id.
     *
     * @param number $paymentMethodId
     * @return string
     */
    public function getPaymentMopId($paymentMethodId): string
    {
        $paymentMethods = $this->paymentMethodRepository->allForPlugin('wallee');
        if (! is_null($paymentMethods)) {
            foreach ($paymentMethods as $paymentMethod) {
                if ($paymentMethod->paymentKey == $paymentMethodId) {
                    return $paymentMethod->id;
                }
            }
        }
        return 'no_paymentmethod_found';
    }

    /**
     * Returns the wallee payment method's id.
     *
     * @param number $paymentMethodId
     * @return string
     */
    public function isWalleePaymentMopId($mopId): bool
    {
        $paymentMethods = $this->paymentMethodRepository->allForPlugin('wallee');
        if (! is_null($paymentMethods)) {
            foreach ($paymentMethods as $paymentMethod) {
                if ($paymentMethod->id == $mopId) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Create a payment in plentymarkets.
     *
     * @param array $transaction
     * @return Payment
     */
    public function createPlentyPayment($transaction)
    {
        /** @var Payment $payment */
        $payment = pluginApp(\Plenty\Modules\Payment\Models\Payment::class);

        $payment->mopId = (int) $transaction['metaData']['plentyPaymentMethodId'];
        $payment->transactionType = Payment::TRANSACTION_TYPE_BOOKED_POSTING;
        $payment->status = $this->mapTransactionState($transaction['state']);
        $payment->currency = $transaction['currency'];
        $payment->amount = $transaction['authorizationAmount'];
        $payment->receivedAt = $transaction['authorizedOn'];
        $payment->unaccountable = ($payment->status != Payment::STATUS_CAPTURED);

        $paymentProperty = [];
        $paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_BOOKING_TEXT, 'TransactionID: ' . (string) $transaction['id']);
        $paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_TRANSACTION_ID, $transaction['id']);
        $paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_ORIGIN, Payment::ORIGIN_PLUGIN);
        $payment->properties = $paymentProperty;

        $payment = $this->paymentRepository->createPayment($payment);

        return $payment;
    }

    /**
     * Create a payment in plentymarkets for a refund.
     *
     * @param array $refund
     * @return Payment
     */
    public function createRefundPlentyPayment($refund)
    {
        /** @var Payment $payment */
        $payment = pluginApp(\Plenty\Modules\Payment\Models\Payment::class);

        $payment->mopId = (int) $refund['transaction']['metaData']['plentyPaymentMethodId'];
        $payment->transactionType = Payment::TRANSACTION_TYPE_BOOKED_POSTING;
        $payment->status = $this->mapRefundState($refund['state']);
        $payment->currency = $refund['transaction']['currency'];
        $payment->amount = $refund['amount'] < 0 ? - $refund['amount'] : $refund['amount'];
        $payment->type = Payment::PAYMENT_TYPE_DEBIT;
        $payment->receivedAt = $refund['createdOn'];

        $paymentProperty = [];
        $paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_BOOKING_TEXT, 'TransactionID: ' . ((string) $refund['transaction']['id']) . ', RefundID: ' . ((string) $refund['id']));
        $paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_TRANSACTION_ID, $refund['transaction']['id']);
        $paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_REFUND_ID, $refund['id']);
        $paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_ORIGIN, Payment::ORIGIN_PLUGIN);
        $payment->properties = $paymentProperty;

        $payment = $this->paymentRepository->createPayment($payment);

        return $payment;
    }

    public function updatePlentyPayment($transaction)
    {
        $payments = $this->paymentRepository->getPaymentsByPropertyTypeAndValue(PaymentProperty::TYPE_TRANSACTION_ID, $transaction['id']);

        $state = $this->mapTransactionState($transaction['state']);

        $this->markPaymentsCaptured($state, $payments);
        
        return true;
    }

    public function updateInvoice($transactionInvoice)
    {
        if ($transactionInvoice['state'] == 'NOT_APPLICABLE') {
            $transactionState = $transactionInvoice['completion']['lineItemVersion']['transaction']['state'];
            if ($transactionState == 'FULFILL') {
                $state = Payment::STATUS_CAPTURED;
            } elseif ($transactionState == 'DECLINE' || $transactionState == 'VOIDED' || $transactionState == 'FAILED') {
                return true;
            } else {
                return false;
            }
        } elseif ($transactionInvoice['state'] == 'PAID') {
            $state = Payment::STATUS_CAPTURED;
        } else {
            $state = Payment::STATUS_REFUSED;
        }

        $payments = $this->paymentRepository->getPaymentsByPropertyTypeAndValue(PaymentProperty::TYPE_TRANSACTION_ID, $transactionInvoice['completion']['lineItemVersion']['transaction']['id']);
        $this->markPaymentsCaptured($state, $payments);
        
        return true;
    }

    /**
     * Mark payments as captured if they are moving forwards in state.
     *
     * @param string $state
     * @param array $payments
     */
    private function markPaymentsCaptured($state, $payments) 
    {
        foreach ($payments as $payment) {
            /* @var Payment $payment */
            if ($payment->status != $state) {
                // ensure payment state can only move forward
                if ($payment->status < $state) {
                    $payment->status = $state;
                } else {
                    return;
                }
                // checking unaccountable to ensure that the payment hasn't been assigned twice
                // unaccountable = An unassigned payment. Unassigned payments have the value 1.
                if ($state == Payment::STATUS_CAPTURED && $payment->unaccountable == 1) {
                    $payment->unaccountable = 0;
                    $payment->updateOrderPaymentStatus = true;
                }
                $this->paymentRepository->updatePayment($payment);
            }
        }
    }

    public function updateRefund($refund)
    {
        $state = $this->mapRefundState($refund['state']);

        $payments = $this->paymentRepository->getPaymentsByPropertyTypeAndValue(PaymentProperty::TYPE_REFUND_ID, $refund['id']);
        foreach ($payments as $payment) {
            /* @var Payment $payment */
            if ($payment->status != $state) {
                $payment->status = $state;
                $this->paymentRepository->updatePayment($payment);
            }
        }
        
        return true;
    }

    /**
     * Assign the payment to an order in plentymarkets.
     *
     * @param Payment $payment
     * @param int $orderId
     */
    public function assignPlentyPaymentToPlentyOrder(Payment $payment, int $orderId)
    {
        $order = $this->orderRepository->findOrderById($orderId);

        if (! is_null($order) && $order instanceof Order) {
            $this->paymentOrderRelationRepository->createOrderRelation($payment, $order);
        }
    }

    /**
     * Returns the plentymarkets payment status matching the given transaction state.
     *
     * @param string $state
     * @return number
     */
    public function mapTransactionState(string $state)
    {
        switch ($state) {
            case 'PENDING':
            case 'CONFIRMED':
            case 'PROCESSING':
                return Payment::STATUS_AWAITING_APPROVAL;
            case 'FAILED':
                return Payment::STATUS_CANCELED;
            case 'AUTHORIZED':
                return Payment::STATUS_APPROVED;
            case 'VOIDED':
                return Payment::STATUS_REFUSED;
            case 'COMPLETED':
                return Payment::STATUS_APPROVED;
            case 'FULFILL':
                return Payment::STATUS_CAPTURED;
            case 'DECLINE':
                return Payment::STATUS_REFUSED;
        }
    }

    /**
     * Returns the plentymarkets payment status matching the given refund state.
     *
     * @param string $state
     * @return number
     */
    public function mapRefundState(string $state)
    {
        switch ($state) {
            case 'PENDING':
            case 'MANUAL_CHECK':
                return Payment::STATUS_AWAITING_APPROVAL;
            case 'FAILED':
                return Payment::STATUS_CANCELED;
            case 'SUCCESSFUL':
                return Payment::STATUS_APPROVED;
        }
    }

    /**
     * Returns a PaymentProperty with the given params
     *
     * @param int $typeId
     * @param mixed $data
     * @return PaymentProperty
     */
    private function getPaymentProperty($typeId, $value)
    {
        /** @var PaymentProperty $paymentProperty */
        $paymentProperty = pluginApp(\Plenty\Modules\Payment\Models\PaymentProperty::class);
        $paymentProperty->typeId = $typeId;
        $paymentProperty->value = (string) $value;
        return $paymentProperty;
    }

    /**
     *
     * @param Payment $payment
     * @param int $propertyType
     * @return null|string
     */
    public function getPaymentPropertyValue($payment, $propertyType)
    {
        $properties = $payment->properties;
        if (($properties->count() > 0) || (is_array($properties) && count($properties) > 0)) {
            /** @var PaymentProperty $property */
            foreach ($properties as $property) {
                if ($property instanceof PaymentProperty) {
                    if ($property->typeId == $propertyType) {
                        return $property->value;
                    }
                }
            }
        }
        return null;
    }
}