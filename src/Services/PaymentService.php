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
use Wallee\Helper\OrderAccessHelper;
use Wallee\Helper\PaymentHelper;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Payment\Method\Models\PaymentMethod;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Property\Models\OrderPropertyType;
use Wallee\Helper\OrderHelper;
use Wallee\Helper\OrderItemSkuHelper;
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
     *
     * @var OrderAccessHelper
     */
    private $orderAccessHelper;

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
     * @param OrderAccessHelper $orderAccessHelper
     */
    public function __construct(WalleeSdkService $sdkService, ConfigRepository $config, ItemRepositoryContract $itemRepository, VariationRepositoryContract $variationRepository, VariationPropertyValueRepositoryContract $variationPropertyValueRepository, PropertyNameRepositoryContract $propertyNameRepository, PropertyGroupNameRepositoryContract $propertyGroupNameRepository, PropertySelectionRepositoryContract $propertySelectionRepository, FrontendSessionStorageFactoryContract $session, AddressRepositoryContract $addressRepository, CountryRepositoryContract $countryRepository, WebstoreHelper $webstoreHelper, PaymentHelper $paymentHelper, OrderHelper $orderHelper, OrderRepositoryContract $orderRepository, OrderAccessHelper $orderAccessHelper)
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
        $this->orderAccessHelper = $orderAccessHelper;
    }

    public function createWebhook()
    {
        /** @var \Plenty\Modules\Helper\Services\WebstoreHelper $webstoreHelper */
        $webstoreHelper = pluginApp(\Plenty\Modules\Helper\Services\WebstoreHelper::class);
        /** @var \Plenty\Modules\System\Models\WebstoreConfiguration $webstoreConfig */
        $webstoreConfig = $webstoreHelper->getCurrentWebstoreConfiguration();
        $this->sdkService->call('createWebhook', [
            'storeId' => $webstoreConfig->webstoreId,
            'notificationUrl' => $webstoreConfig->domainSsl . '/rest/v1/wallee/update-transaction' . ($this->config->get('plenty.system.info.urlTrailingSlash', 0) == 2 ? '/' : ''),
        ]);
    }

    /**
     * Creates the payment from basket for PWA (before order is created).
     *
     * @param PaymentMethod $paymentMethod
     * @return array
     */
    public function executePaymentFromBasket(PaymentMethod $paymentMethod): array
    {
        try {
            $transactionId = $this->session->getPlugin()->getValue('walleeTransactionId');

            /** @var \IO\Services\BasketService $basketService */
            $basketService = pluginApp(\IO\Services\BasketService::class);
            $basket = $basketService->getBasket();
            $basketForTemplate = $basketService->getBasketForTemplate();

            // Generate temporary merchant reference for PWA (order doesn't exist yet)
            // Use session ID + timestamp to ensure uniqueness
            $tempMerchantRef = 'PWA_' . $basket->sessionId . '_' . time();

            $basketItems = $this->getBasketItems($basket);

            // Build parameters - SDK expects basketItems at root level (see createTransactionFromBasket.php line 54)
            $parameters = [
                'basket' => [
                    'currency' => $basket->currency,
                    'customerId' => $basket->customerId ?? '',
                    'orderId' => $tempMerchantRef, // PWA: Use temp reference until order is created
                    'basketAmount' => $basket->basketAmount ?? 0,
                    'basketAmountNet' => $basket->basketAmountNet ?? 0,
                    'shippingAmount' => $basket->shippingAmount ?? 0,
                    'shippingAmountNet' => $basket->shippingAmountNet ?? 0,
                    'couponDiscount' => $basket->couponDiscount ?? 0,
                    'paymentAmount' => 0,
                ],
                'basketItems' => $basketItems, // SDK expects this at root level, not inside basket!
                'basketForTemplate' => $basketForTemplate,
                'paymentMethod' => [
                    'id' => $paymentMethod->id,
                    'paymentKey' => $paymentMethod->paymentKey
                ],
                'billingAddress' => $this->getBasketBillingAddressSafe($basket),
                'shippingAddress' => $this->getBasketShippingAddressSafe($basket),
                // Use the same language for the hosted payment page as for the return URLs
                'language' => $this->resolveLanguage(),
                'successUrl' => $this->getSuccessUrl(),
                'failedUrl' => $this->getFailedUrl(),
                'checkoutUrl' => $this->getFailedUrl()
            ];

            // Only add transactionId if it exists (not null)
            if ($transactionId !== null) {
                $parameters['transactionId'] = $transactionId;
            }

            $this->session->getPlugin()->unsetKey('walleeTransactionId');

            try {
                $transaction = $this->sdkService->call('createTransactionFromBasket', $parameters);

                if (is_array($transaction) && isset($transaction['error']) && $transaction['error']) {
                    $this->getLogger(__METHOD__)->error('wallee::BasketTransactionError', $transaction);
                    return [
                        'transactionId' => $transactionId,
                        'type' => GetPaymentMethodContent::RETURN_TYPE_ERROR,
                        'content' => $transaction['error_msg'] ?? 'Transaction creation failed'
                    ];
                }
            } catch (\Exception $e) {
                $this->getLogger(__METHOD__)->error('wallee::BasketTransactionException', [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return [
                    'transactionId' => $transactionId,
                    'type' => GetPaymentMethodContent::RETURN_TYPE_ERROR,
                    'content' => 'Failed to create transaction: ' . $e->getMessage()
                ];
            }

            // Store transaction ID for later order association
            $this->session->getPlugin()->setValue('walleeTransactionId', $transaction['id']);

            // Mark the transaction as belonging to this visitor, the payment return urls
            // are the only place where the customer can be identified without an order.
            $this->orderAccessHelper->rememberTransaction($transaction['id']);

            $isFetchPossiblePaymentMethodsEnabled = $this->config->get('wallee.enable_payment_fetch');

            if ($isFetchPossiblePaymentMethodsEnabled == "true") {
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
            }

            $paymentPageUrl = $this->sdkService->call('buildPaymentPageUrl', [
                'id' => $transaction['id']
            ]);

            if (is_array($paymentPageUrl) && isset($paymentPageUrl['error'])) {
                $this->getLogger(__METHOD__)->error('wallee::PaymentPageUrlError', $paymentPageUrl);
                return [
                    'transactionId' => $transaction['id'],
                    'type' => GetPaymentMethodContent::RETURN_TYPE_ERROR,
                    'content' => $paymentPageUrl['error_msg'] ?? 'Payment page URL generation failed'
                ];
            }

            $result = [
                'type' => GetPaymentMethodContent::RETURN_TYPE_REDIRECT_URL,
                'content' => $paymentPageUrl,
                'redirectUrl' => $paymentPageUrl, // Additional field for PWA
                'transactionId' => $transaction['id']
            ];

            return $result;

        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('wallee::BasketPaymentException', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'type' => GetPaymentMethodContent::RETURN_TYPE_ERROR,
                'content' => 'An error occurred while processing the payment.'
            ];
        }
    }

    /**
     * Creates the payment in plentymarkets.
     *
     * @param Order $order
     * @param PaymentMethod $paymentMethod
     * @return string[]
     */
    public function executePayment(Order $order, PaymentMethod $paymentMethod, bool $skipPaymentCreation = false): array
    {
        $transactionId = $this->session->getPlugin()->getValue('walleeTransactionId');

        $parameters = [
            'transactionId' => $transactionId,
            'order' => $order,
            'itemIdsByOrderItemId' => $this->getOrderItemItemIds($order),
            'itemAttributes' => $this->getLineItemAttributes($order),
            'paymentMethod' => $paymentMethod,
            'billingAddress' => $this->getAddress($order->billingAddress),
            'shippingAddress' => $this->getAddress($order->deliveryAddress),
            // Use the same language for the hosted payment page as for the return URLs
            'language' => $this->resolveLanguage($order),
            'customerId' => $this->orderHelper->getOrderRelationId($order, OrderRelationReference::REFERENCE_TYPE_CONTACT),
            'successUrl' => $this->getSuccessUrl($order),
            'failedUrl' => $this->getFailedUrl($order),
            'checkoutUrl' => $this->getFailedUrl($order)
        ];

        $existingTransaction = $this->sdkService->call('getTransactionByMerchantReference', [
            'merchantReference' => $order->id
        ]);

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

        // Mark the transaction as belonging to this visitor, so the payment return urls
        // can tell the customer apart from someone guessing transaction ids.
        $this->orderAccessHelper->rememberTransaction($transaction['id']);

        if (!$skipPaymentCreation) {
            $payment = $this->paymentHelper->createPlentyPayment($transaction);
            $this->paymentHelper->assignPlentyPaymentToPlentyOrder($payment, $order->id);
        }

        $isFetchPossiblePaymentMethodsEnabled = $this->config->get('wallee.enable_payment_fetch');

        if ($isFetchPossiblePaymentMethodsEnabled == "true") {
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
        }

        $paymentPageUrl = $this->sdkService->call('buildPaymentPageUrl', [
            'id' => $transaction['id']
        ]);

        if (is_array($paymentPageUrl) && isset($paymentPageUrl['error'])) {
            $this->getLogger(__METHOD__)->error('wallee::PaymentPageUrlError', $paymentPageUrl);
            return [
                'transactionId' => $transaction['id'],
                'type' => GetPaymentMethodContent::RETURN_TYPE_ERROR,
                'content' => $paymentPageUrl['error_msg']
            ];
        }

        return [
            'type' => GetPaymentMethodContent::RETURN_TYPE_REDIRECT_URL,
            'redirectUrl' => $paymentPageUrl, // Additional field for PWA
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

    private function getOrderItemItemIds(Order $order): array
    {
        $itemIdsByOrderItemId = [];
        $itemIdsByVariationId = [];
        $unresolvedOrderItems = [];
        $language = $this->session->getLocaleSettings()->language;
        if (empty($language)) {
            $language = 'de';
        }
        /** @var AuthHelper $authHelper */
        $authHelper = pluginApp(AuthHelper::class);
        foreach ($order->orderItems as $orderItem) {
            if (! in_array($orderItem->typeId, [
                OrderItemSkuHelper::TYPE_VARIATION,
                OrderItemSkuHelper::TYPE_ITEM_BUNDLE,
                OrderItemSkuHelper::TYPE_BUNDLE_COMPONENT
            ])) {
                continue;
            }
            if (! empty($orderItem->itemId)) {
                $itemIdsByOrderItemId[$orderItem->id] = $orderItem->itemId;
                continue;
            }
            $itemIdFromSku = OrderItemSkuHelper::resolveItemIdFromOrderItemModel($orderItem);
            if (! empty($itemIdFromSku)) {
                $itemIdsByOrderItemId[$orderItem->id] = $itemIdFromSku;
                continue;
            }
            if (empty($orderItem->itemVariationId)) {
                $unresolvedOrderItems[] = [
                    'orderItemId' => $orderItem->id,
                    'reason' => 'missingItemIdAndVariationId'
                ];
                continue;
            }

            $variationId = $orderItem->itemVariationId;
            if (! array_key_exists($variationId, $itemIdsByVariationId)) {
                try {
                    $itemIdsByVariationId[$variationId] = $this->resolveItemIdByVariationId($variationId, $language, $authHelper);
                } catch (\Exception $e) {
                    $itemIdsByVariationId[$variationId] = null;
                    $this->getLogger(__METHOD__)->error('wallee::debug.basic', [
                        'logCode' => 'OrderVariationItemResolveFailed',
                        'orderId' => $order->id,
                        'variationId' => $variationId,
                        'orderItemId' => $orderItem->id,
                        'message' => $e->getMessage()
                    ]);
                }
            }

            if (! empty($itemIdsByVariationId[$variationId])) {
                $itemIdsByOrderItemId[$orderItem->id] = $itemIdsByVariationId[$variationId];
            } else {
                $unresolvedOrderItems[] = [
                    'orderItemId' => $orderItem->id,
                    'variationId' => $variationId,
                    'reason' => 'missingItemIdForVariation'
                ];
            }
        }

        return $itemIdsByOrderItemId;
    }

    private function resolveItemIdByVariationId($variationId, $preferredLanguage, AuthHelper $authHelper)
    {
        if (empty($variationId)) {
            return null;
        }

        $languages = [];
        if (! empty($preferredLanguage)) {
            $languages[] = $preferredLanguage;
        }
        if (! in_array('de', $languages, true)) {
            $languages[] = 'de';
        }
        if (! in_array('en', $languages, true)) {
            $languages[] = 'en';
        }

        foreach ($languages as $language) {
            try {
                $variationRepository = $this->variationRepository;
                $variation = $authHelper->processUnguarded(function () use ($variationId, $variationRepository, $language) {
                    try {
                        return $variationRepository->show($variationId, [
                            'item'
                        ], $language);
                    } catch (\Throwable $firstException) {
                        return $variationRepository->show($variationId, $language, [
                            'item'
                        ]);
                    }
                });
                $itemId = OrderItemSkuHelper::extractItemIdFromVariation($variation);
                if (! empty($itemId)) {
                    return $itemId;
                }
            } catch (\Throwable $exception) {
                continue;
            }
        }

        return null;
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
     * Safely get basket billing address with error handling
     *
     * @param Basket $basket
     * @return array
     */
    private function getBasketBillingAddressSafe(Basket $basket): array
    {
        try {
            $address = $this->getBasketBillingAddress($basket);
            return $this->getAddress($address);
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('vRPayment::BillingAddressError', [
                'error' => $e->getMessage(),
                'addressId' => $basket->customerInvoiceAddressId ?? 'null'
            ]);
            // Return minimal valid address structure
            return [
                'city' => '',
                'gender' => '',
                'country' => 'DE',
                'dateOfBirth' => null,
                'emailAddress' => '',
                'familyName' => '',
                'givenName' => '',
                'organisationName' => '',
                'phoneNumber' => '',
                'postCode' => '',
                'street' => ''
            ];
        }
    }

    /**
     * Safely get basket shipping address with error handling
     *
     * @param Basket $basket
     * @return array
     */
    private function getBasketShippingAddressSafe(Basket $basket): array
    {
        try {
            $address = $this->getBasketShippingAddress($basket);
            return $this->getAddress($address);
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('wallee::ShippingAddressError', [
                'error' => $e->getMessage(),
                'addressId' => $basket->customerShippingAddressId ?? 'null'
            ]);
            // Fallback to billing address
            return $this->getBasketBillingAddressSafe($basket);
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
     * Extract basket items from basketForTemplate (for PWA)
     * 
     * @param array $basketForTemplate
     * @return array
     */
    private function getBasketItemsFromTemplate(array $basketForTemplate): array
    {
        $items = [];

        if (!isset($basketForTemplate['basketItems']) || !is_array($basketForTemplate['basketItems'])) {
            return [];
        }

        foreach ($basketForTemplate['basketItems'] as $basketItem) {
            // basketItem from template is already an array with the data we need
            if (isset($basketItem['plenty_basket_row_item_variation_id'])) {
                // Already formatted from template
                $items[] = $basketItem;
            } else {
                // Fallback: format manually if not already formatted
                $items[] = [
                    'plenty_basket_row_item_variation_id' => $basketItem['variationId'] ?? 0,
                    'itemId' => $basketItem['itemId'] ?? 0,
                    'name' => $basketItem['name'] ?? 'Product',
                    'quantity' => $basketItem['quantity'] ?? 1,
                    'price' => $basketItem['price'] ?? 0,
                    'vat' => $basketItem['vat'] ?? 0
                ];
            }
        }

        return $items;
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
     * Resolves the language the end user is browsing the shop with.
     *
     * Resolution order:
     * 1. The order's DOCUMENT_LANGUAGE property (typeId 6) — per-order, survives session loss.
     * 2. The walleeOriginLang session value registered by the PWA via register-return.
     * 3. The frontend session locale (Ceres / last resort).
     *
     * @param Order|null $order
     * @return string|null
     */
    private function resolveLanguage(?Order $order = null): ?string
    {
        $orderLang = null;
        if ($order) {
            try {
                $orderLang = $this->orderHelper->getOrderPropertyValue($order, OrderPropertyType::DOCUMENT_LANGUAGE);
            } catch (\Exception $e) {
                $this->getLogger(__METHOD__)->error('Wallee::resolveLanguageOrderPropertyFailed', [
                    'orderId' => $order->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $sessionLang = $this->session->getPlugin()->getValue('walleeOriginLang');
        $localeLang = $this->session->getLocaleSettings()->language;
        $lang = $orderLang ?: ($sessionLang ?: $localeLang);
        return $lang;
    }

    /**
     *
     * @return string
     */
    private function getSuccessUrl(?Order $order = null): string
    {
        $originUrl = $this->session->getPlugin()->getValue('walleeOriginUrl');
        if ($originUrl && $order) {
            $accessKey = $this->orderRepository->generateAccessKey($order->id);
            $originLang = $this->resolveLanguage($order);
            $defaultLang = $this->webstoreHelper->getCurrentWebstoreConfiguration()->defaultLanguage;
            $langPrefix = ($originLang && $originLang !== $defaultLang) ? '/' . $originLang : '';
            $url = sprintf('%s%s/confirmation/%d/%s', rtrim($originUrl, '/'), $langPrefix, $order->id, $accessKey);
            return $url;
        }
        $lang = $this->resolveLanguage($order) ?: $this->session->getLocaleSettings()->language;
        $domain = $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl;
        return sprintf('%s/%s/confirmation', $domain, $lang);
    }

    /**
     *
     * @return string
     */
    private function getFailedUrl(?Order $order = null): string
    {
        $originUrl = $this->session->getPlugin()->getValue('walleeOriginUrl');
        if ($originUrl) {
            $originLang = $this->resolveLanguage($order);
            $defaultLang = $this->webstoreHelper->getCurrentWebstoreConfiguration()->defaultLanguage;
            $langPrefix = ($originLang && $originLang !== $defaultLang) ? '/' . $originLang : '';
            if ($order) {
                // Redirect to the new PWA payment selection page (outside /checkout guard)
                // Wallee will automatically append /{transactionId} to this URL
                $url = sprintf(
                    '%s%s/payment-selection/%d',
                    rtrim($originUrl, '/'),
                    $langPrefix,
                    $order->id,
                );
                return $url;
            }
            // Fallback: no order available, redirect to PWA checkout with failure flag
            return sprintf('%s%s/checkout?wallee_failed=1', rtrim($originUrl, '/'), $langPrefix);
        }
        $lang = $this->resolveLanguage($order) ?: $this->session->getLocaleSettings()->language;
        $domain = $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl;
        return sprintf('%s/%s/wallee/fail-transaction', $domain, $lang);
    }

    /**
     *
     * @return string
     */
    private function getCheckoutUrl(): string
    {
        $frontendSession = pluginApp(\Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract::class);
        $originUrl = $frontendSession->getPlugin()->getValue('walleeOriginUrl');
        if ($originUrl) {
            $failedUrl = sprintf('%s/checkout', rtrim($originUrl, '/'));
            return $failedUrl;
        }
        $lang = $this->session->getLocaleSettings()->language;
        $domain = $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl;
        return sprintf('%s/%s/checkout', $domain, $lang);
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
