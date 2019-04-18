<?php
use Wallee\Sdk\Service\TransactionService;
use Wallee\Sdk\Service\LanguageService;
use Wallee\Sdk\Model\TransactionCreate;
use Wallee\Sdk\Model\LineItemCreate;
use Wallee\Sdk\Model\AddressCreate;
use Wallee\Sdk\Model\TaxCreate;
use Wallee\Sdk\Service\PaymentMethodConfigurationService;
use Wallee\Sdk\Model\EntityQuery;
use Wallee\Sdk\Model\EntityQueryFilter;
use Wallee\Sdk\Service\CurrencyService;
use Wallee\Sdk\Model\Gender;
use Wallee\Sdk\Model\TransactionPending;
use Wallee\Sdk\Model\LineItemType;

require_once __DIR__ . '/WalleeSdkHelper.php';

$client = WalleeSdkHelper::getApiClient(SdkRestApi::getParam('gatewayBasePath'), SdkRestApi::getParam('apiUserId'), SdkRestApi::getParam('apiUserKey'));

function buildLineItem($orderItem, $uniqueId, $sku, $type, $basketNetPrices, $currencyDecimalPlaces)
{
    $lineItem = new LineItemCreate();
    $lineItem->setUniqueId($uniqueId);
    $lineItem->setSku($sku);
    $lineItem->setName(mb_substr($orderItem['orderItemName'], 0, 40, "UTF-8"));
    $lineItem->setQuantity((int) $orderItem['quantity']);
    if ($basketNetPrices) {
        $lineItem->setAmountIncludingTax(WalleeSdkHelper::roundAmount($orderItem['amounts'][0]['priceNet'], $currencyDecimalPlaces));
    } else {
        $lineItem->setAmountIncludingTax(WalleeSdkHelper::roundAmount($orderItem['amounts'][0]['priceGross'], $currencyDecimalPlaces));
    }
    if (isset($orderItem['vatRate']) && ! empty($orderItem['vatRate'])) {
        $lineItem->setTaxes([
            new TaxCreate([
                'rate' => $orderItem['vatRate'],
                'title' => 'Tax'
            ])
        ]);
    }
    $lineItem->setType($type);
    return $lineItem;
}

function collectTransactionData($transactionRequest, $client)
{
    $spaceId = SdkRestApi::getParam('spaceId');
    $order = SdkRestApi::getParam('order');

    $transactionRequest->setCurrency($order['amounts'][0]['currency']);
    $transactionRequest->setCustomerId(SdkRestApi::getParam('customerId')); // FIXME: only set customer id if customer has account.
    $transactionRequest->setMerchantReference($order['id']);
    $transactionRequest->setSuccessUrl(SdkRestApi::getParam('successUrl'));
    $transactionRequest->setFailedUrl(SdkRestApi::getParam('checkoutUrl'));

    $service = new LanguageService($client);
    $languages = $service->all();
    foreach ($languages as $language) {
        if ($language->getIso2Code() == SdkRestApi::getParam('language') && $language->getPrimaryOfGroup()) {
            $transactionRequest->setLanguage($language->getIetfCode());
        }
    }

    $currencyService = new CurrencyService($client);
    $currencyDecimalPlaces = 2;
    $currencies = $currencyService->all();
    foreach ($currencies as $currency) {
        if ($currency->getCurrencyCode() == $order['amounts'][0]['currency']) {
            $currencyDecimalPlaces = $currency->getFractionDigits();
            break;
        }
    }

    $netPrices = $order['amounts'][0]['isNet'];
    $lineItems = [];
    foreach ($order['orderItems'] as $orderItem) {
        if ($orderItem['typeId'] == 1 || $orderItem['typeId'] == 2 || $orderItem['typeId'] == 3) {
            // VARIANTION
            $lineItem = buildLineItem($orderItem, $orderItem['itemVariationId'], $orderItem['itemVariationId'], LineItemType::PRODUCT, $netPrices, $currencyDecimalPlaces);
            $lineItem->setShippingRequired(true);
            $lineItems[] = $lineItem;
        } elseif ($orderItem['typeId'] == 4 || $orderItem['typeId'] == 5) {
            // GIFT_CARD
            $lineItem = buildLineItem($orderItem, 'coupon-discount', 'coupon-discount', LineItemType::DISCOUNT, $netPrices, $currencyDecimalPlaces);
            $lineItems[] = $lineItem;
        } elseif ($orderItem['typeId'] == 6) {
            // SHIPPING
            $lineItems[] = buildLineItem($orderItem, 'shipping', 'shipping', LineItemType::SHIPPING, false, $currencyDecimalPlaces);
        } elseif ($orderItem['typeId'] == 7) {
            // PAYMENT SURCHARGE
            $lineItems[] = buildLineItem($orderItem, 'payment-fee', 'payment-fee', LineItemType::FEE, $netPrices, $currencyDecimalPlaces);
        } elseif ($orderItem['typeId'] == 8) {
            // GIFT WRAP
            $lineItems[] = buildLineItem($orderItem, 'gift-wrap', 'gift-wrap', LineItemType::FEE, $netPrices, $currencyDecimalPlaces);
        }
    }

    $lineItemTotalAmount = WalleeSdkHelper::calculateLineItemTotalAmount($lineItems);
    $basketAmount = $netPrices ? $order['amounts'][0]['netTotal'] : $order['amounts'][0]['grossTotal'];
    if (WalleeSdkHelper::roundAmount($lineItemTotalAmount, $currencyDecimalPlaces) > WalleeSdkHelper::roundAmount($basketAmount, $currencyDecimalPlaces)) {
        $lineItem = new LineItemCreate();
        $lineItem->setUniqueId('adjustment');
        $lineItem->setSku('adjustment');
        $lineItem->setName('Adjustment');
        $lineItem->setQuantity(1);
        $lineItem->setAmountIncludingTax(WalleeSdkHelper::roundAmount($basketAmount - $lineItemTotalAmount, $currencyDecimalPlaces));
        $lineItem->setType('DISCOUNT');
        $lineItems[] = $lineItem;
    } elseif ($lineItemTotalAmount < $basketAmount) {
        $lineItem = new LineItemCreate();
        $lineItem->setUniqueId('adjustment');
        $lineItem->setSku('adjustment');
        $lineItem->setName('Adjustment');
        $lineItem->setQuantity(1);
        $lineItem->setAmountIncludingTax(WalleeSdkHelper::roundAmount($basketAmount - $lineItemTotalAmount, $currencyDecimalPlaces));
        $lineItem->setType('FEE');
        $lineItems[] = $lineItem;
    }
    $transactionRequest->setLineItems($lineItems);

    $basketBillingAddress = SdkRestApi::getParam('billingAddress');
    $billingAddress = new AddressCreate();
    $billingAddress->setCity(mb_substr($basketBillingAddress['city'], 0, 100, "UTF-8"));
    $billingAddress->setCountry($basketBillingAddress['country']);
    $billingAddress->setDateOfBirth($basketBillingAddress['dateOfBirth']);
    $billingAddress->setEmailAddress(mb_substr($basketBillingAddress['emailAddress'], 0, 254, "UTF-8"));
    $billingAddress->setFamilyName(mb_substr($basketBillingAddress['familyName'], 0, 100, "UTF-8"));
    $billingAddress->setGivenName(mb_substr($basketBillingAddress['givenName'], 0, 100, "UTF-8"));
    $billingAddress->setOrganizationName(mb_substr($basketBillingAddress['organisationName'], 0, 100, "UTF-8"));
    $billingAddress->setPhoneNumber($basketBillingAddress['phoneNumber']);
    $billingAddress->setPostCode(mb_substr($basketBillingAddress['postCode'], 0, 40, "UTF-8"));
    $billingAddress->setStreet(mb_substr($basketBillingAddress['street'], 0, 300, "UTF-8"));

    if (isset($basketBillingAddress['gender'])) {
        if (strtolower($basketBillingAddress['gender']) == 'male') {
            $billingAddress->setGender(Gender::MALE);
        } else if (strtolower($basketBillingAddress['gender']) == 'female') {
            $billingAddress->setGender(Gender::FEMALE);
        }
    }

    $transactionRequest->setBillingAddress($billingAddress);

    $basketShippingAddress = SdkRestApi::getParam('shippingAddress');
    $shippingAddress = new AddressCreate();
    $shippingAddress->setCity(mb_substr($basketShippingAddress['city'], 0, 100, "UTF-8"));
    $shippingAddress->setCountry($basketShippingAddress['country']);
    $shippingAddress->setDateOfBirth($basketShippingAddress['dateOfBirth']);
    $shippingAddress->setEmailAddress(mb_substr($basketShippingAddress['emailAddress'], 0, 254, "UTF-8"));
    $shippingAddress->setFamilyName(mb_substr($basketShippingAddress['familyName'], 0, 100, "UTF-8"));
    $shippingAddress->setGivenName(mb_substr($basketShippingAddress['givenName'], 0, 100, "UTF-8"));
    $shippingAddress->setOrganizationName(mb_substr($basketShippingAddress['organisationName'], 0, 100, "UTF-8"));
    $shippingAddress->setPhoneNumber($basketShippingAddress['phoneNumber']);
    $shippingAddress->setPostCode(mb_substr($basketShippingAddress['postCode'], 0, 40, "UTF-8"));
    $shippingAddress->setStreet(mb_substr($basketShippingAddress['street'], 0, 300, "UTF-8"));

    if (isset($basketShippingAddress['gender'])) {
        if (strtolower($basketShippingAddress['gender']) == 'male') {
            $shippingAddress->setGender(Gender::MALE);
        } else if (strtolower($basketShippingAddress['gender']) == 'female') {
            $shippingAddress->setGender(Gender::FEMALE);
        }
    }

    $transactionRequest->setShippingAddress($shippingAddress);

    $paymentMethod = SdkRestApi::getParam('paymentMethod');
    $paymentMethodId = (int) $paymentMethod['paymentKey'];

    $paymentMethodConfigurationService = new PaymentMethodConfigurationService($client);
    $query = new EntityQuery();
    $query->setNumberOfEntities(20);
    $filter = new EntityQueryFilter();
    $filter->setType(\Wallee\Sdk\Model\EntityQueryFilterType::_AND);
    $filter->setChildren([
        WalleeSdkHelper::createEntityFilter('state', \Wallee\Sdk\Model\CreationEntityState::ACTIVE),
        WalleeSdkHelper::createEntityFilter('paymentMethod', $paymentMethodId)
    ]);
    $query->setFilter($filter);
    $paymentMethodConfigurations = $paymentMethodConfigurationService->search($spaceId, $query);

    $allowedPaymentMethodConfigurations = [];
    foreach ($paymentMethodConfigurations as $paymentMethodConfiguration) {
        $allowedPaymentMethodConfigurations[] = $paymentMethodConfiguration->getId();
    }

    $transactionRequest->setAllowedPaymentMethodConfigurations($allowedPaymentMethodConfigurations);
}

$service = new TransactionService($client);
$spaceId = SdkRestApi::getParam('spaceId');
$transactionId = SdkRestApi::getParam('transactionId');
if (! empty($transactionId)) {
    $createdTransaction = $service->read($spaceId, $transactionId);
} else {
    $transactionRequest = new TransactionCreate();
    collectTransactionData($transactionRequest, $client);
    $transactionRequest->setAutoConfirmationEnabled(false);
    $transactionRequest->setCustomersPresence(\Wallee\Sdk\Model\CustomersPresence::VIRTUAL_PRESENT);
    $createdTransaction = $service->create($spaceId, $transactionRequest);
}

$pendingTransaction = new TransactionPending();
$pendingTransaction->setId($createdTransaction->getId());
$pendingTransaction->setVersion($createdTransaction->getVersion());
collectTransactionData($pendingTransaction, $client);
$pendingTransaction->setFailedUrl(SdkRestApi::getParam('failedUrl') . '/' . $createdTransaction->getId());
$transactionResponse = $service->confirm($spaceId, $pendingTransaction);

return WalleeSdkHelper::convertData($transactionResponse);