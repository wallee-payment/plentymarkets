<?php
use Wallee\Sdk\Service\TransactionService;

require_once __DIR__ . '/WalleeSdkHelper.php';

$client = WalleeSdkHelper::getApiClient(SdkRestApi::getParam('gatewayBasePath'), SdkRestApi::getParam('apiUserId'), SdkRestApi::getParam('apiUserKey'));

$spaceId = SdkRestApi::getParam('spaceId');
$transactionId = SdkRestApi::getParam('transactionId');

$service = new TransactionService($client);
$possiblePaymentMethods = $service->fetchPossiblePaymentMethods($spaceId, $transactionId);
if ($possiblePaymentMethods != null && ! empty($possiblePaymentMethods)) {
    return true;
} else {
    return false;
}