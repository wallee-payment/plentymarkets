<?php
namespace Wallee\Helper;

use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Frontend\Services\AccountService;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\RelationReference\Models\OrderRelationReference;
use Plenty\Modules\Payment\Contracts\PaymentOrderRelationRepositoryContract;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\Payment\Models\Payment;
use Plenty\Modules\Payment\Models\PaymentProperty;
use Plenty\Plugin\Log\Loggable;

/**
 * Decides whether the current visitor is allowed to see data of an order or of a
 * wallee transaction.
 *
 * Transaction ids are sequential, so no endpoint may expose order data based on a
 * transaction id taken from the URL alone. Access is granted only if either
 * - the order belongs to the contact that is currently logged in, or
 * - the caller knows the order access key (guest checkout), or
 * - the transaction was created in the current frontend session (payment return urls).
 */
class OrderAccessHelper
{

    use Loggable;

    /**
     * Session key holding the transactions created in the current frontend session.
     */
    const SESSION_TRANSACTION_IDS = 'walleeOwnTransactionIds';

    /**
     * Number of transaction ids kept per session.
     */
    const MAX_SESSION_TRANSACTION_IDS = 10;

    /**
     *
     * @var OrderRepositoryContract
     */
    private $orderRepository;

    /**
     *
     * @var PaymentRepositoryContract
     */
    private $paymentRepository;

    /**
     *
     * @var PaymentOrderRelationRepositoryContract
     */
    private $paymentOrderRelationRepository;

    /**
     *
     * @var AccountService
     */
    private $accountService;

    /**
     *
     * @var FrontendSessionStorageFactoryContract
     */
    private $frontendSession;

    /**
     *
     * @var OrderHelper
     */
    private $orderHelper;

    /**
     *
     * @var PaymentHelper
     */
    private $paymentHelper;

    /**
     * Constructor.
     *
     * @param OrderRepositoryContract $orderRepository
     * @param PaymentRepositoryContract $paymentRepository
     * @param PaymentOrderRelationRepositoryContract $paymentOrderRelationRepository
     * @param AccountService $accountService
     * @param FrontendSessionStorageFactoryContract $frontendSession
     * @param OrderHelper $orderHelper
     * @param PaymentHelper $paymentHelper
     */
    public function __construct(OrderRepositoryContract $orderRepository, PaymentRepositoryContract $paymentRepository, PaymentOrderRelationRepositoryContract $paymentOrderRelationRepository, AccountService $accountService, FrontendSessionStorageFactoryContract $frontendSession, OrderHelper $orderHelper, PaymentHelper $paymentHelper)
    {
        $this->orderRepository = $orderRepository;
        $this->paymentRepository = $paymentRepository;
        $this->paymentOrderRelationRepository = $paymentOrderRelationRepository;
        $this->accountService = $accountService;
        $this->frontendSession = $frontendSession;
        $this->orderHelper = $orderHelper;
        $this->paymentHelper = $paymentHelper;
    }

    /**
     * Returns the order if the current visitor is allowed to access it, null otherwise.
     *
     * @param int $orderId
     * @param string $accessKey
     * @return Order|null
     */
    public function findOwnOrder(int $orderId, string $accessKey)
    {
        $order = $this->loadOrder($orderId);
        if ($order instanceof Order && $this->isOrderOfCurrentContact($order)) {
            return $order;
        }

        // Guest orders are only accessible with the order access key, the same key
        // plentymarkets uses for the guest order confirmation page.
        if (empty($accessKey)) {
            return null;
        }

        try {
            $order = $this->orderRepository->findOrderByAccessKey($orderId, $accessKey);
        } catch (\Exception $e) {
            return null;
        }

        if ($order instanceof Order && $order->id == $orderId) {
            return $order;
        }

        return null;
    }

    /**
     * Returns the access key of the given order, an empty string if it cannot be generated.
     *
     * @param int $orderId
     * @return string
     */
    public function getOrderAccessKey(int $orderId): string
    {
        try {
            return (string) $this->orderRepository->generateAccessKey($orderId);
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Wallee::AccessKeyGenerationFailed', [
                'orderId' => $orderId,
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }

    /**
     * Returns true if the current visitor may see data of the given transaction.
     *
     * @param array $transaction
     * @return bool
     */
    public function mayAccessTransaction($transaction): bool
    {
        if (! is_array($transaction) || empty($transaction['id'])) {
            return false;
        }

        if (in_array((string) $transaction['id'], $this->getOwnTransactionIds(), true)) {
            return true;
        }

        $order = $this->findOrderByTransaction($transaction);

        return ($order instanceof Order) && $this->isOrderOfCurrentContact($order);
    }

    /**
     * Remembers a transaction as belonging to the current frontend session, so the
     * customer can be identified when the payment page redirects back to the shop.
     *
     * @param mixed $transactionId
     */
    public function rememberTransaction($transactionId)
    {
        $transactionId = (string) $transactionId;
        if (empty($transactionId)) {
            return;
        }

        $transactionIds = $this->getOwnTransactionIds();
        if (in_array($transactionId, $transactionIds, true)) {
            return;
        }

        $transactionIds[] = $transactionId;
        $transactionIds = array_slice($transactionIds, - self::MAX_SESSION_TRANSACTION_IDS);

        $this->frontendSession->getPlugin()->setValue(self::SESSION_TRANSACTION_IDS, implode(',', $transactionIds));
    }

    /**
     * Returns the id of the wallee transaction the given order was paid with.
     *
     * @param Order $order
     * @return string|null
     */
    public function getTransactionIdForOrder(Order $order)
    {
        $payments = $this->paymentRepository->getPaymentsByOrderId($order->id);
        foreach (array_reverse($payments) as $payment) {
            /* @var Payment $payment */
            if ($payment->status == Payment::STATUS_CANCELED) {
                continue;
            }
            if (! $this->paymentHelper->isWalleePaymentMopId($payment->mopId)) {
                continue;
            }
            $transactionId = $this->paymentHelper->getPaymentPropertyValue($payment, PaymentProperty::TYPE_TRANSACTION_ID);
            if (! empty($transactionId)) {
                return $transactionId;
            }
        }

        return null;
    }

    /**
     * Loads an order without applying the backend permission checks.
     *
     * @param int $orderId
     * @return Order|null
     */
    private function loadOrder(int $orderId)
    {
        /** @var AuthHelper $authHelper */
        $authHelper = pluginApp(AuthHelper::class);
        $orderRepository = $this->orderRepository;

        try {
            $order = $authHelper->processUnguarded(function () use ($orderId, $orderRepository) {
                return $orderRepository->findOrderById($orderId);
            });
        } catch (\Exception $e) {
            return null;
        }

        return ($order instanceof Order) ? $order : null;
    }

    /**
     * Returns the order belonging to the given transaction, null if it cannot be resolved.
     *
     * @param array $transaction
     * @return Order|null
     */
    private function findOrderByTransaction($transaction)
    {
        $payments = $this->paymentRepository->getPaymentsByPropertyTypeAndValue(PaymentProperty::TYPE_TRANSACTION_ID, $transaction['id']);
        foreach ($payments as $payment) {
            $orderRelation = $this->paymentOrderRelationRepository->findOrderRelation($payment);
            if ($orderRelation) {
                $order = $this->loadOrder((int) $orderRelation->orderId);
                if ($order instanceof Order) {
                    return $order;
                }
            }
        }

        // Transactions carry the order id as merchant reference, which is the only
        // link available while the payment record has not been created yet.
        if (! empty($transaction['merchantReference']) && is_numeric($transaction['merchantReference'])) {
            return $this->loadOrder((int) $transaction['merchantReference']);
        }

        return null;
    }

    /**
     * Returns true if the given order belongs to the contact that is currently logged in.
     *
     * @param Order $order
     * @return bool
     */
    private function isOrderOfCurrentContact(Order $order): bool
    {
        $contactId = $this->getCurrentContactId();
        if (empty($contactId)) {
            return false;
        }

        $orderContactId = $this->orderHelper->getOrderRelationId($order, OrderRelationReference::REFERENCE_TYPE_CONTACT);

        return ! empty($orderContactId) && ((int) $orderContactId === (int) $contactId);
    }

    /**
     * Returns the contact id of the logged in customer, null for guests.
     *
     * @return int|null
     */
    private function getCurrentContactId()
    {
        try {
            $contactId = $this->accountService->getAccountContactId();
        } catch (\Exception $e) {
            return null;
        }

        return ! empty($contactId) && $contactId > 0 ? (int) $contactId : null;
    }

    /**
     * Returns the transaction ids created in the current frontend session.
     *
     * @return array
     */
    private function getOwnTransactionIds(): array
    {
        $storedIds = (string) $this->frontendSession->getPlugin()->getValue(self::SESSION_TRANSACTION_IDS);
        if (empty($storedIds)) {
            return [];
        }

        return array_values(array_filter(explode(',', $storedIds)));
    }
}
