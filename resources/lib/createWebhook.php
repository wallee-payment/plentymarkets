<?php
use Wallee\Sdk\Model\WebhookUrlCreate;
use Wallee\Sdk\Model\WebhookListenerCreate;
use Wallee\Sdk\Service\WebhookUrlService;
use Wallee\Sdk\Service\WebhookListenerService;

require_once __DIR__ . '/WalleeSdkHelper.php';

$client = WalleeSdkHelper::getApiClient(SdkRestApi::getParam('gatewayBasePath'), SdkRestApi::getParam('apiUserId'), SdkRestApi::getParam('apiUserKey'));

$spaceId = SdkRestApi::getParam('spaceId');

$webhookUrlService = new WebhookUrlService($client);

$query = new \Wallee\Sdk\Model\EntityQuery();
$query->setNumberOfEntities(1);
$filter = new \Wallee\Sdk\Model\EntityQueryFilter();
$filter->setType(\Wallee\Sdk\Model\EntityQueryFilter::TYPE_AND);
$filter->setChildren([
    WalleeSdkHelper::createEntityFilter('url', SdkRestApi::getParam('notificationUrl')),
    WalleeSdkHelper::createEntityFilter('state', \Wallee\Sdk\Model\WebhookUrl::STATE_ACTIVE)
]);
$query->setFilter($filter);
$webhookResult = $webhookUrlService->search($spaceId, $query);
if (empty($webhookResult)) {
    $webhookUrlRequest = new WebhookUrlCreate();
    $webhookUrlRequest->setName('plentymarkets ' . SdkRestApi::getParam('storeId'));
    $webhookUrlRequest->setUrl(SdkRestApi::getParam('notificationUrl'));
    $webhookUrl = $webhookUrlService->create($spaceId, $webhookUrlRequest);

    $webhookListenerRequest = new WebhookListenerCreate();
    $webhookListenerRequest->setEntity(1472041829003);
    $webhookListenerRequest->setEntityStates([
        'FAILED',
        'AUTHORIZED',
        'VOIDED',
        'COMPLETED',
        'FULFILL',
        'DECLINE'
    ]);
    $webhookListenerRequest->setName('plentymarkets ' . SdkRestApi::getParam('storeId') . ' Transaction');
    $webhookListenerRequest->setUrl($webhookUrl);

    $webhookListenerService = new WebhookListenerService($client);
    $webhookListenerService->create($spaceId, $webhookListenerRequest);
}