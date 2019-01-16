<?php
use Wallee\Sdk\Service\RefundService;

require_once __DIR__ . '/WalleeSdkHelper.php';

$client = WalleeSdkHelper::getApiClient(SdkRestApi::getParam('gatewayBasePath'), SdkRestApi::getParam('apiUserId'), SdkRestApi::getParam('apiUserKey'));

$spaceId = SdkRestApi::getParam('spaceId');

$service = new RefundService($client);
$refund = $service->read($spaceId, SdkRestApi::getParam('id'));

return WalleeSdkHelper::convertData($refund);