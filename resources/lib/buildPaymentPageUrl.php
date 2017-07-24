<?php
use Wallee\Sdk\Service\TransactionService;

require_once __DIR__ . '/WalleeSdkHelper.php';

$spaceId = SdkRestApi::getParam('spaceId');
$id = SdkRestApi::getParam('id');

$client = WalleeSdkHelper::getApiClient(SdkRestApi::getParam('gatewayBasePath'), SdkRestApi::getParam('apiUserId'), SdkRestApi::getParam('apiUserKey'));
$service = new TransactionService($client);
return $service->buildPaymentPageUrl($spaceId, $id);