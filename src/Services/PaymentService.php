<?php
namespace Wallee\Services;

use Plenty\Modules\Basket\Models\Basket;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Item\Item\Contracts\ItemRepositoryContract;
use Plenty\Modules\Basket\Models\BasketItem;
use Plenty\Modules\Account\Address\Models\Address;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use Plenty\Modules\Payment\Events\Checkout\GetPaymentMethodContent;
use Plenty\Modules\Helper\Services\WebstoreHelper;
use Wallee\Helper\PaymentHelper;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Payment\Method\Models\PaymentMethod;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;

class PaymentService
{

    use Loggable;

    /**
     *
     * @var WalleeSdkService
     */
    private $sdkService;

    /**
     *
     * @var ConfigRepository
     */
    private $config;

    /**
     *
     * @var ItemRepositoryContract
     */
    private $itemRepository;

    /**
     *
     * @var FrontendSessionStorageFactoryContract
     */
    private $session;

    /**
     *
     * @var AddressRepositoryContract
     */
    private $addressRepository;

    /**
     *
     * @var CountryRepositoryContract
     */
    private $countryRepository;

    /**
     *
     * @var WebstoreHelper
     */
    private $webstoreHelper;

    /**
     *
     * @var PaymentHelper
     */
    private $paymentHelper;

    /**
     *
     * @var OrderRepositoryContract
     */
    private $orderRepository;

    /**
     * Constructor.
     *
     * @param WalleeSdkService $sdkService
     * @param ConfigRepository $config
     * @param ItemRepositoryContract $itemRepository
     * @param FrontendSessionStorageFactoryContract $session
     * @param AddressRepositoryContract $addressRepository
     * @param CountryRepositoryContract $countryRepository
     * @param WebstoreHelper $webstoreHelper
     * @param PaymentHelper $paymentHelper
     * @param OrderRepositoryContract $orderRepository
     */
    public function __construct(WalleeSdkService $sdkService, ConfigRepository $config, ItemRepositoryContract $itemRepository, FrontendSessionStorageFactoryContract $session, AddressRepositoryContract $addressRepository, CountryRepositoryContract $countryRepository, WebstoreHelper $webstoreHelper, PaymentHelper $paymentHelper, OrderRepositoryContract $orderRepository)
    {
        $this->sdkService = $sdkService;
        $this->config = $config;
        $this->itemRepository = $itemRepository;
        $this->session = $session;
        $this->addressRepository = $addressRepository;
        $this->countryRepository = $countryRepository;
        $this->webstoreHelper = $webstoreHelper;
        $this->paymentHelper = $paymentHelper;
        $this->orderRepository = $orderRepository;
    }

    /**
     * Returns the payment method's content.
     *
     * @param array $basket
     * @param PaymentMethod $paymentMethod
     * @return string[]
     */
    public function getPaymentContent(Basket $basket, array $basketForTemplate, PaymentMethod $paymentMethod): array
    {
        $parameters = [
            'basket' => $basket,
            'basketForTemplate' => $basketForTemplate,
            'paymentMethod' => $paymentMethod,
            'basketItems' => $this->getBasketItems($basket),
            'billingAddress' => $this->getAddress($this->getBillingAddress($basket)),
            'shippingAddress' => $this->getAddress($this->getShippingAddress($basket)),
            'language' => $this->session->getLocaleSettings()->language,
            'successUrl' => $this->getSuccessUrl(),
            'failedUrl' => $this->getFailedUrl(),
            'checkoutUrl' => $this->getCheckoutUrl()
        ];
        $this->getLogger(__METHOD__)->debug('wallee::TransactionParameters', $parameters);

        $transaction = $this->sdkService->call('createTransaction', $parameters);

        $this->createWebhook();

        if (is_array($transaction) && isset($transaction['error'])) {
            return [
                'type' => GetPaymentMethodContent::RETURN_TYPE_ERROR,
                'content' => $transaction['error_msg']
            ];
        }

        $this->getLogger(__METHOD__)->debug('wallee::transaction result', $transaction);

        $this->session->getPlugin()->setValue('walleeTransactionId', $transaction['id']);

        $paymentPageUrl = $this->sdkService->call('buildPaymentPageUrl', [
            'id' => $transaction['id']
        ]);
        if (is_array($paymentPageUrl) && isset($paymentPageUrl['error'])) {
            return [
                'type' => GetPaymentMethodContent::RETURN_TYPE_ERROR,
                'content' => $paymentPageUrl['error_msg']
            ];
        }
        $this->getLogger(__METHOD__)->debug('wallee::before redirect', $paymentPageUrl);

        return [
            'type' => GetPaymentMethodContent::RETURN_TYPE_REDIRECT_URL,
            'content' => $paymentPageUrl
        ];
    }

    private function createWebhook()
    {
        /** @var \Plenty\Modules\Helper\Services\WebstoreHelper $webstoreHelper */
        $webstoreHelper = pluginApp(\Plenty\Modules\Helper\Services\WebstoreHelper::class);
        /** @var \Plenty\Modules\System\Models\WebstoreConfiguration $webstoreConfig */
        $webstoreConfig = $webstoreHelper->getCurrentWebstoreConfiguration();
        $this->sdkService->call('createWebhook', [
            'storeId' => $webstoreConfig->webstoreId,
            'notificationUrl' => $webstoreConfig->domainSsl . '/wallee/update-transaction'
        ]);
    }

    /**
     * Creates the payment in plentymarkets.
     *
     * @param number $orderId
     * @return string[]
     */
    public function executePayment($orderId)
    {
        $transactionId = $this->session->getPlugin()->getValue('walleeTransactionId');

        $transaction = $this->sdkService->call('getTransaction', [
            'id' => $transactionId
        ]);

        if (is_array($transaction) && $transaction['error']) {
            return [
                'type' => 'error',
                'value' => $transaction['error_msg']
            ];
        }

        $payment = $this->paymentHelper->createPlentyPayment($transaction);
        $this->paymentHelper->assignPlentyPaymentToPlentyOrder($payment, $orderId);

        $this->session->getPlugin()->setValue('walleeTransactionId', null);

        return [
            'type' => 'success',
            'value' => 'The payment has been executed successfully.'
        ];
    }

    /**
     *
     * @param Basket $basket
     * @return Address
     */
    private function getBillingAddress(Basket $basket): Address
    {
        $addressId = $basket->customerInvoiceAddressId;
        return $this->addressRepository->findAddressById($addressId);
    }

    /**
     *
     * @param Basket $basket
     * @return Address
     */
    private function getShippingAddress(Basket $basket)
    {
        $addressId = $basket->customerShippingAddressId;
        if ($addressId != null && $addressId != - 99) {
            return $this->addressRepository->findAddressById($addressId);
        } else {
            return $this->getBillingAddress($basket);
        }
    }

    /**
     *
     * @param Address $address
     * @return array
     */
    private function getAddress(Address $address): array
    {
        $birthday = $address->birthday;
        if (empty($birthday) || ! preg_match('/[0-9]{4}-[0-9]{2}-[0-9]{2}/', $birthday)) {
            $birthday = null;
        }

        return [
            'city' => $address->town,
            'gender' => $address->gender,
            'country' => $this->countryRepository->findIsoCode($address->countryId, 'iso_code_2'),
            'dateOfBirth' => $birthday,
            'emailAddress' => $address->email,
            'familyName' => $address->lastName,
            'givenName' => $address->firstName,
            'organisationName' => $address->companyName,
            'phoneNumber' => $address->phone,
            'postCode' => $address->postalCode,
            'street' => $address->street . ' ' . $address->houseNumber
        ];
    }

    /**
     *
     * @param Basket $basket
     * @return array
     */
    private function getBasketItems(Basket $basket): array
    {
        $items = [];
        /** @var BasketItem $basketItem */
        foreach ($basket->basketItems as $basketItem) {
            $item = $basketItem->getAttributes();
            $item['name'] = $this->getBasketItemName($basketItem);
            $items[] = $item;
        }
        return $items;
    }

    /**
     *
     * @param BasketItem $basketItem
     * @return string
     */
    private function getBasketItemName(BasketItem $basketItem): string
    {
        /** @var \Plenty\Modules\Item\Item\Models\Item $item */
        $item = $this->itemRepository->show($basketItem->itemId);

        /** @var \Plenty\Modules\Item\Item\Models\ItemText $itemText */
        $itemText = $item->texts;
        if (! empty($itemText) && ! empty($itemText->first()->name1)) {
            return $itemText->first()->name1;
        } else {
            return "Product";
        }
    }

    /**
     *
     * @return string
     */
    private function getSuccessUrl(): string
    {
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/place-order';
    }

    /**
     *
     * @return string
     */
    private function getFailedUrl(): string
    {
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/wallee/fail-transaction';
    }

    /**
     *
     * @return string
     */
    private function getCheckoutUrl(): string
    {
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/checkout';
    }

    /**
     *
     * @param number $transactionId
     * @param Order $order
     */
    public function refund($transactionId, Order $order)
    {
        $this->getLogger(__METHOD__)->debug('Wallee:RefundOrder', [
            'transactionId' => $transactionId,
            'order' => $order
        ]);
        try {
            $refund = $this->sdkService->call('createRefund', [
                'transactionId' => $transactionId,
                'order' => $order
            ]);

            if (is_array($refund) && $refund['error']) {
                throw new \Exception($refund['error_msg']);
            }

            $payment = $this->paymentHelper->createRefundPlentyPayment($refund);
            $this->paymentHelper->assignPlentyPaymentToPlentyOrder($payment, $order->id);

            $this->orderRepository->updateOrder([
                'statusId' => $this->getRefundSuccessfulStatus()
            ], $order->id);
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('The refund failed.', $e);

            $this->orderRepository->updateOrder([
                'statusId' => $this->getRefundFailedStatus()
            ], $order->id);
        }
    }

    private function getRefundSuccessfulStatus()
    {
        $status = $this->config->get('wallee.refund_successful_status');
        if (empty($status)) {
            return '11.2';
        } else {
            return $status;
        }
    }

    private function getRefundFailedStatus()
    {
        $status = $this->config->get('wallee.refund_failed_status');
        if (empty($status)) {
            return '11.3';
        } else {
            return $status;
        }
    }
}
