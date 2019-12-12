<?php
use Wallee\Sdk\Service\TransactionPaymentPageService;

require_once __DIR__ . '/WalleeSdkHelper.php';

$spaceId = SdkRestApi::getParam('spaceId');
$id = SdkRestApi::getParam('id');

$client = WalleeSdkHelper::getApiClient(SdkRestApi::getParam('gatewayBasePath'), SdkRestApi::getParam('apiUserId'), SdkRestApi::getParam('apiUserKey'));
$service = new TransactionPaymentPageService($client);
return $service->paymentPageUrl($spaceId, $id);