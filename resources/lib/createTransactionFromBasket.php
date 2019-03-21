<?php
use Wallee\Sdk\Service\TransactionService;
use Wallee\Sdk\Service\LanguageService;
use Wallee\Sdk\Model\LineItemCreate;
use Wallee\Sdk\Model\AddressCreate;
use Wallee\Sdk\Model\TaxCreate;
use Wallee\Sdk\Service\PaymentMethodConfigurationService;
use Wallee\Sdk\Model\EntityQuery;
use Wallee\Sdk\Model\EntityQueryFilter;
use Wallee\Sdk\Service\CurrencyService;
use Wallee\Sdk\Model\Gender;
use Wallee\Sdk\Model\TransactionPending;
use Wallee\Sdk\Model\TransactionCreate;

require_once __DIR__ . '/WalleeSdkHelper.php';

$client = WalleeSdkHelper::getApiClient(SdkRestApi::getParam('gatewayBasePath'), SdkRestApi::getParam('apiUserId'), SdkRestApi::getParam('apiUserKey'));

function collectTransactionData($transactionRequest, $client)
{
    $spaceId = SdkRestApi::getParam('spaceId');

    $basket = SdkRestApi::getParam('basket');
    $basketForTemplate = SdkRestApi::getParam('basketForTemplate');

    $transactionRequest->setCurrency($basket['currency']);
    $transactionRequest->setCustomerId($basket['customerId']); // FIXME: only set customer id if customer has account.
    $transactionRequest->setMerchantReference($basket['orderId']);
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
        if ($currency->getCurrencyCode() == $basket['currency']) {
            $currencyDecimalPlaces = $currency->getFractionDigits();
            break;
        }
    }

    $basketNetPrices = $basketForTemplate['basketAmountNet'] == $basketForTemplate['basketAmount'];
    $lineItems = [];
    $maxTaxRate = 0;
    foreach (SdkRestApi::getParam('basketItems') as $basketItem) {
        $lineItem = new LineItemCreate();
        $lineItem->setUniqueId($basketItem['plenty_basket_row_item_variation_id']);
        $lineItem->setSku($basketItem['itemId']);
        $lineItem->setName(mb_substr($basketItem['name'], 0, 40, "UTF-8"));
        $lineItem->setQuantity((int) $basketItem['quantity']);
        $lineItem->setShippingRequired(true);
        if ($basketNetPrices && isset($basketItem['vat']) && ! empty($basketItem['vat'])) {
            $lineItem->setAmountIncludingTax(WalleeSdkHelper::roundAmount(($basketItem['price'] / (1 + $basketItem['vat'] / 100)) * $basketItem['quantity'], $currencyDecimalPlaces));
        } else {
            $lineItem->setAmountIncludingTax(WalleeSdkHelper::roundAmount($basketItem['price'] * $basketItem['quantity'], $currencyDecimalPlaces));
        }
        if (isset($basketItem['vat']) && ! empty($basketItem['vat'])) {
            $lineItem->setTaxes([
                new TaxCreate([
                    'rate' => $basketItem['vat'],
                    'title' => 'Tax'
                ])
            ]);
            if ($basketItem['vat'] > $maxTaxRate) {
                $maxTaxRate = $basketItem['vat'];
            }
        }
        $lineItem->setType('PRODUCT');
        $lineItems[] = $lineItem;
    }
    if ($basket['shippingAmount'] > 0) {
        $lineItem = new LineItemCreate();
        $lineItem->setUniqueId('shipping');
        $lineItem->setSku('shipping');
        $lineItem->setName('Shipping');
        $lineItem->setQuantity(1);
        $lineItem->setAmountIncludingTax(WalleeSdkHelper::roundAmount($basket['shippingAmount'], $currencyDecimalPlaces));
        $taxAmount = $basket['shippingAmount'] - $basket['shippingAmountNet'];
        if ($taxAmount > 0) {
            $lineItem->setTaxes([
                new TaxCreate([
                    'rate' => $maxTaxRate,
                    'title' => 'TAX'
                ])
            ]);
        }
        $lineItem->setType('SHIPPING');
        $lineItems[] = $lineItem;
    }
    if ($basket['couponDiscount'] < 0) {
        $lineItem = new LineItemCreate();
        $lineItem->setUniqueId('coupon-discount');
        $lineItem->setSku('coupon-discount');
        $lineItem->setName('Coupon Discount');
        $lineItem->setQuantity(1);
        $lineItem->setAmountIncludingTax(WalleeSdkHelper::roundAmount($basket['couponDiscount'], $currencyDecimalPlaces));
        $lineItem->setType('DISCOUNT');
        $lineItems[] = $lineItem;
    }
    if ($basket['paymentAmount'] > 0) {
        $lineItem = new LineItemCreate();
        $lineItem->setUniqueId('payment-fee');
        $lineItem->setSku('payment-fee');
        $lineItem->setName('Payment Fee');
        $lineItem->setQuantity(1);
        $lineItem->setAmountIncludingTax(WalleeSdkHelper::roundAmount($basket['paymentAmount'], $currencyDecimalPlaces));
        $lineItem->setType('FEE');
        $lineItems[] = $lineItem;
    }
    $lineItemTotalAmount = WalleeSdkHelper::calculateLineItemTotalAmount($lineItems);
    $basketAmount = $basketForTemplate['basketAmount'];
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
    $transactionRequest->setAutoConfirmationEnabled(true);
    $transactionRequest->setCustomersPresence(\Wallee\Sdk\Model\CustomersPresence::VIRTUAL_PRESENT);
    $createdTransaction = $service->create($spaceId, $transactionRequest);
}

$pendingTransaction = new TransactionPending();
$pendingTransaction->setId($createdTransaction->getId());
$pendingTransaction->setVersion($createdTransaction->getVersion());
collectTransactionData($pendingTransaction, $client);
$pendingTransaction->setFailedUrl(SdkRestApi::getParam('failedUrl') . '/' . $createdTransaction->getId());
$transactionResponse = $service->update($spaceId, $pendingTransaction);

return WalleeSdkHelper::convertData($transactionResponse);