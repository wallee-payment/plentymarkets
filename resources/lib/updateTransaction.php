<?php
use Wallee\Sdk\Service\TransactionService;
use Wallee\Sdk\Model\TransactionPending;
use Wallee\Sdk\VersioningException;
use Wallee\Sdk\Model\Transaction;

require_once __DIR__ . '/WalleeSdkHelper.php';

$client = WalleeSdkHelper::getApiClient(SdkRestApi::getParam('gatewayBasePath'), SdkRestApi::getParam('apiUserId'), SdkRestApi::getParam('apiUserKey'));

function collectTransactionData(Transaction $transaction, TransactionPending $transactionRequest, $client)
{
    $order = SdkRestApi::getParam('order');

    $transactionRequest->setCurrency($transaction->getCurrency());
    $transactionRequest->setCustomerId($transaction->getCustomerId());
    $transactionRequest->setMerchantReference($order['id']);
    $transactionRequest->setSuccessUrl(SdkRestApi::getParam('successUrl'));
    $transactionRequest->setFailedUrl(SdkRestApi::getParam('checkoutUrl'));
    $transactionRequest->setLanguage($transaction->getLanguage());
    $transactionRequest->setLineItems($transaction->getLineItems());
    $transactionRequest->setBillingAddress($transaction->getBillingAddress());
    $transactionRequest->setShippingAddress($transaction->getShippingAddress());
    $transactionRequest->setAllowedPaymentMethodConfigurations($transaction->getAllowedPaymentMethodConfigurations());
}

$spaceId = SdkRestApi::getParam('spaceId');

$service = new TransactionService($client);

for ($i = 0; $i < 5; $i ++) {
    try {
        $transaction = $service->read($spaceId, SdkRestApi::getParam('id'));

        $transactionRequest = new TransactionPending();
        $transactionRequest->setId($transaction->getId());
        $transactionRequest->setVersion($transaction->getVersion());
        collectTransactionData($transaction, $transactionRequest, $client);
        $confirmedTransaction = $service->confirm($spaceId, $transactionRequest);

        return WalleeSdkHelper::convertData($confirmedTransaction);
    } catch (VersioningException $e) {}
}

throw new VersioningException();