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
use Wallee\Helper\OrderHelper;
use Plenty\Modules\Item\Variation\Contracts\VariationRepositoryContract;
use Plenty\Modules\Order\RelationReference\Models\OrderRelationReference;
use Plenty\Modules\Item\VariationProperty\Contracts\VariationPropertyValueRepositoryContract;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Item\Property\Contracts\PropertyGroupNameRepositoryContract;
use Plenty\Modules\Item\Property\Contracts\PropertyNameRepositoryContract;
use Plenty\Modules\Item\Property\Contracts\PropertySelectionRepositoryContract;

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
     * @var VariationRepositoryContract
     */
    private $variationRepository;

    /**
     *
     * @var VariationPropertyValueRepositoryContract
     */
    private $variationPropertyValueRepository;

    /**
     *
     * @var PropertyNameRepositoryContract
     */
    private $propertyNameRepository;

    /**
     *
     * @var PropertyGroupNameRepositoryContract
     */
    private $propertyGroupNameRepository;

    /**
     *
     * @var PropertySelectionRepositoryContract
     */
    private $propertySelectionRepository;

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
     * @var OrderHelper
     */
    private $orderHelper;

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
     * @param VariationRepositoryContract $variationRepository
     * @param VariationPropertyValueRepositoryContract $variationPropertyValueRepository
     * @param PropertyNameRepositoryContract $propertyNameRepository
     * @param PropertyGroupNameRepositoryContract $propertyGroupNameRepository
     * @param PropertySelectionRepositoryContract $propertySelectionRepository
     * @param FrontendSessionStorageFactoryContract $session
     * @param AddressRepositoryContract $addressRepository
     * @param CountryRepositoryContract $countryRepository
     * @param WebstoreHelper $webstoreHelper
     * @param PaymentHelper $paymentHelper
     * @param OrderHelper $orderHelper
     * @param OrderRepositoryContract $orderRepository
     */
    public function __construct(WalleeSdkService $sdkService, ConfigRepository $config, ItemRepositoryContract $itemRepository, VariationRepositoryContract $variationRepository, VariationPropertyValueRepositoryContract $variationPropertyValueRepository, PropertyNameRepositoryContract $propertyNameRepository, PropertyGroupNameRepositoryContract $propertyGroupNameRepository, PropertySelectionRepositoryContract $propertySelectionRepository, FrontendSessionStorageFactoryContract $session, AddressRepositoryContract $addressRepository, CountryRepositoryContract $countryRepository, WebstoreHelper $webstoreHelper, PaymentHelper $paymentHelper, OrderHelper $orderHelper, OrderRepositoryContract $orderRepository)
    {
        $this->sdkService = $sdkService;
        $this->config = $config;
        $this->itemRepository = $itemRepository;
        $this->variationRepository = $variationRepository;
        $this->variationPropertyValueRepository = $variationPropertyValueRepository;
        $this->propertyNameRepository = $propertyNameRepository;
        $this->propertyGroupNameRepository = $propertyGroupNameRepository;
        $this->propertySelectionRepository = $propertySelectionRepository;
        $this->session = $session;
        $this->addressRepository = $addressRepository;
        $this->countryRepository = $countryRepository;
        $this->webstoreHelper = $webstoreHelper;
        $this->paymentHelper = $paymentHelper;
        $this->orderHelper = $orderHelper;
        $this->orderRepository = $orderRepository;
    }

    public function createWebhook()
    {
        /** @var \Plenty\Modules\Helper\Services\WebstoreHelper $webstoreHelper */
        $webstoreHelper = pluginApp(\Plenty\Modules\Helper\Services\WebstoreHelper::class);
        /** @var \Plenty\Modules\System\Models\WebstoreConfiguration $webstoreConfig */
        $webstoreConfig = $webstoreHelper->getCurrentWebstoreConfiguration();
        $this->sdkService->call('createWebhook', [
            'storeId' => $webstoreConfig->webstoreId,
            'notificationUrl' => $webstoreConfig->domainSsl . '/wallee/update-transaction' . ($this->config->get('plenty.system.info.urlTrailingSlash', 0) == 2 ? '/' : '')
        ]);
    }

    /**
     * Creates the payment in plentymarkets.
     *
     * @param Order $order
     * @param PaymentMethod $paymentMethod
     * @return string[]
     */
    public function executePayment(Order $order, PaymentMethod $paymentMethod): array
    {
        $transactionId = $this->session->getPlugin()->getValue('walleeTransactionId');
        $time_start = microtime(true);
        $timingLogs = [];

        $timingLogs["start"] = microtime(true) - $time_start;

        $parameters = [
            'transactionId' => $transactionId,
            'order' => $order,
            'itemAttributes' => $this->getLineItemAttributes($order),
            'paymentMethod' => $paymentMethod,
            'billingAddress' => $this->getAddress($order->billingAddress),
            'shippingAddress' => $this->getAddress($order->deliveryAddress),
            'language' => $this->session->getLocaleSettings()->language,
            'customerId' => $this->orderHelper->getOrderRelationId($order, OrderRelationReference::REFERENCE_TYPE_CONTACT),
            'successUrl' => $this->getSuccessUrl(),
            'failedUrl' => $this->getFailedUrl(),
            'checkoutUrl' => $this->getCheckoutUrl()
        ];
        $this->getLogger(__METHOD__)->debug('wallee::TransactionParameters', $parameters);

        $this->session->getPlugin()->unsetKey('walleeTransactionId');

        $existingTransaction = $this->sdkService->call('getTransactionByMerchantReference', [
            'merchantReference' => $order->id
        ]);

        $timingLogs["getTransactionByMerchantReference"] = microtime(true) - $time_start;

        if (is_array($existingTransaction) && $existingTransaction['error']) {
            $this->getLogger(__METHOD__)->error('wallee::ExistingTransactionsError', $existingTransaction);
            return [
                'transactionId' => $transactionId,
                'type' => GetPaymentMethodContent::RETURN_TYPE_ERROR,
                'content' => $existingTransaction['error_msg']
            ];
        } elseif (!empty($existingTransaction)) {
            if (in_array($existingTransaction['state'], [
                'CONFIRMED',
                'PROCESSING'
            ])) {
                return [
                    'transactionId' => $transactionId,
                    'type' => GetPaymentMethodContent::RETURN_TYPE_ERROR,
                    'content' => 'The payment is being processed.'
                ];
            } elseif (in_array($existingTransaction['state'], [
                'PENDING',
                'FAILED'
            ])) {
                // Ok, continue.
            } else {
                return [
                    'type' => GetPaymentMethodContent::RETURN_TYPE_REDIRECT_URL,
                    'content' => $this->getSuccessUrl()
                ];
            }
        }
        
        $transaction = $this->sdkService->call('createTransactionFromOrder', $parameters);
        if (is_array($transaction) && $transaction['error']) {
            $this->getLogger(__METHOD__)->error('wallee::TransactionError', $transaction);
            return [
                'transactionId' => $transactionId,
                'type' => GetPaymentMethodContent::RETURN_TYPE_ERROR,
                'content' => $transaction['error_msg']
            ];
        }

        $timingLogs["createTransactionFromOrder"] = microtime(true) - $time_start;

        $payment = $this->paymentHelper->createPlentyPayment($transaction);
        $this->paymentHelper->assignPlentyPaymentToPlentyOrder($payment, $order->id);

        $timingLogs["createPlentyPayment"] = microtime(true) - $time_start;

        $hasPossiblePaymentMethods = $this->sdkService->call('hasPossiblePaymentMethods', [
            'transactionId' => $transaction['id']
        ]);
        if (! $hasPossiblePaymentMethods) {
            return [
                'transactionId' => $transaction['id'],
                'type' => GetPaymentMethodContent::RETURN_TYPE_ERROR,
                'content' => 'The selected payment method is not available.'
            ];
        }

        $timingLogs["hasPossiblePaymentMethods"] = microtime(true) - $time_start;

        $paymentPageUrl = $this->sdkService->call('buildPaymentPageUrl', [
            'id' => $transaction['id']
        ]);

        $timingLogs["buildPaymentPageUrl"] = microtime(true) - $time_start;

        if (is_array($paymentPageUrl) && isset($paymentPageUrl['error'])) {
            $this->getLogger(__METHOD__)->error('wallee::PaymentPageUrlError', $paymentPageUrl);
            return [
                'transactionId' => $transaction['id'],
                'type' => GetPaymentMethodContent::RETURN_TYPE_ERROR,
                'content' => $paymentPageUrl['error_msg']
            ];
        }

        $timingLogs["finished"] = microtime(true) - $time_start;
        $this->getLogger(__METHOD__)->debug('wallee::debug.wallee_timing', $timingLogs);

        return [
            'type' => GetPaymentMethodContent::RETURN_TYPE_REDIRECT_URL,
            'content' => $paymentPageUrl
        ];
    }

    private function getLineItemAttributes(Order $order)
    {
        $itemAttributes = [];
        /** @var AuthHelper $authHelper */
        $authHelper = pluginApp(AuthHelper::class);
        foreach ($order->orderItems as $orderItem) {
            if (! empty($orderItem->orderProperties)) {
                $attributes = [];
                foreach ($orderItem->orderProperties as $orderProperty) {
                    $variationPropertyValueRepository = $this->variationPropertyValueRepository;
                    $variationPropertyValue = $authHelper->processUnguarded(function () use ($orderItem, $orderProperty, $variationPropertyValueRepository) {
                        return $variationPropertyValueRepository->show($orderItem->itemVariationId, $orderProperty->propertyId);
                    });

                    $language = $this->session->getLocaleSettings()->language;

                    $propertyNameRepository = $this->propertyNameRepository;
                    $propertyName = $authHelper->processUnguarded(function () use ($orderProperty, $language, $propertyNameRepository) {
                        return $propertyNameRepository->findOne($orderProperty->propertyId, $language);
                    });

                    $this->getLogger(__METHOD__)->debug('wallee::Variation', [
                        'variation' => $variationPropertyValue,
                        'propertyName' => $propertyName
                    ]);

                    switch ($orderProperty->type) {
                        case '':
                            $propertyGroupId = $variationPropertyValue->property->propertyGroupId;
                            $propertyGroupNameRepository = $this->propertyGroupNameRepository;
                            $propertyGroup = $authHelper->processUnguarded(function () use ($propertyGroupId, $language, $propertyGroupNameRepository) {
                                return $propertyGroupNameRepository->findOne($propertyGroupId, $language);
                            });
                            $attributes[] = [
                                'key' => $orderProperty->propertyId,
                                'label' => $propertyGroup->name,
                                'value' => $propertyName->name
                            ];
                            break;
                        case 'selection':
                            $propertySelectionId = $orderProperty->value;
                            $propertySelectionRepository = $this->propertySelectionRepository;
                            $propertySelection = $authHelper->processUnguarded(function () use ($propertySelectionId, $language, $propertySelectionRepository) {
                                return $propertySelectionRepository->findOne($propertySelectionId, $language);
                            });
                            $attributes[] = [
                                'key' => $orderProperty->propertyId,
                                'label' => $propertyName->name,
                                'value' => $propertySelection->name
                            ];
                            break;
                        case 'text':
                        case 'float':
                        case 'int':
                        default:
                            $attributes[] = [
                                'key' => $orderProperty->propertyId,
                                'label' => $propertyName->name,
                                'value' => $orderProperty->value
                            ];
                    }
                }
                if (! empty($attributes)) {
                    $itemAttributes[$orderItem->id] = $attributes;
                }
            }
        }
        return $itemAttributes;
    }

    /**
     *
     * @param Basket $basket
     * @return Address
     */
    private function getBasketBillingAddress(Basket $basket): Address
    {
        $addressId = $basket->customerInvoiceAddressId;
        return $this->addressRepository->findAddressById($addressId);
    }

    /**
     *
     * @param Basket $basket
     * @return Address
     */
    private function getBasketShippingAddress(Basket $basket)
    {
        $addressId = $basket->customerShippingAddressId;
        if ($addressId != null && $addressId != - 99) {
            return $this->addressRepository->findAddressById($addressId);
        } else {
            return $this->getBasketBillingAddress($basket);
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
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/confirmation';
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
    public function refund($transactionId, Order $refundOrder, Order $order)
    {
        $this->getLogger(__METHOD__)->debug('wallee:RefundOrder', [
            'transactionId' => $transactionId,
            'refundOrder' => $refundOrder,
            'order' => $order
        ]);
        try {
            $refund = $this->sdkService->call('createRefund', [
                'transactionId' => $transactionId,
                'refundOrder' => $refundOrder,
                'order' => $order
            ]);

            if (is_array($refund) && $refund['error']) {
                throw new \Exception($refund['error_msg']);
            }

            $payment = $this->paymentHelper->createRefundPlentyPayment($refund);
            $this->paymentHelper->assignPlentyPaymentToPlentyOrder($payment, $refundOrder->id);

            $this->orderRepository->updateOrder([
                'statusId' => $this->getRefundSuccessfulStatus()
            ], $refundOrder->id);
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('The refund failed.', $e);

            $this->orderRepository->updateOrder([
                'statusId' => $this->getRefundFailedStatus()
            ], $refundOrder->id);
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
