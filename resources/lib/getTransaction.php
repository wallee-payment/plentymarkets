<?php
use Wallee\Sdk\Service\TransactionService;
use Wallee\Sdk\ApiClient;

require_once __DIR__ . '/WalleeSdkHelper.php';

$gatewayBasePath = SdkRestApi::getParam('gatewayBasePath');
$userId = SdkRestApi::getParam('apiUserId');
$userKey = SdkRestApi::getParam('apiUserKey');

$client = new ApiClient($userId, $userKey);
$client->setBasePath($gatewayBasePath . '/api');

$spaceId = SdkRestApi::getParam('spaceId');

$service = new TransactionService($client);
$transaction = $service->read($spaceId, SdkRestApi::getParam('id'));

return WalleeSdkHelper::convertData($transaction);