<?php
use Wallee\Sdk\Model\WebhookUrlCreate;
use Wallee\Sdk\Model\WebhookListenerCreate;
use Wallee\Sdk\Service\WebhookUrlService;
use Wallee\Sdk\Service\WebhookListenerService;

require_once __DIR__ . '/WalleeSdkHelper.php';

class WebhookEntity
{

    private $id;

    private $name;

    private $states;

    public function __construct($id, $name, array $states)
    {
        $this->id = $id;
        $this->name = $name;
        $this->states = $states;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getStates()
    {
        return $this->states;
    }
}

$webhookEntities = [];
$webhookEntities[] = new WebhookEntity(1472041829003, 'Transaction', [
    \Wallee\Sdk\Model\TransactionState::AUTHORIZED,
    \Wallee\Sdk\Model\TransactionState::DECLINE,
    \Wallee\Sdk\Model\TransactionState::FAILED,
    \Wallee\Sdk\Model\TransactionState::FULFILL,
    \Wallee\Sdk\Model\TransactionState::VOIDED,
    \Wallee\Sdk\Model\TransactionState::COMPLETED
], 'update-transaction');
$webhookEntities[] = new WebhookEntity(1472041816898, 'Transaction Invoice', [
    \Wallee\Sdk\Model\TransactionInvoiceState::NOT_APPLICABLE,
    \Wallee\Sdk\Model\TransactionInvoiceState::PAID,
    \Wallee\Sdk\Model\TransactionInvoiceState::DERECOGNIZED
], 'update-transaction-invoice');
$webhookEntities[] = new WebhookEntity(1472041839405, 'Refund', [
    \Wallee\Sdk\Model\RefundState::SUCCESSFUL,
    \Wallee\Sdk\Model\RefundState::FAILED
]);

$client = WalleeSdkHelper::getApiClient(SdkRestApi::getParam('gatewayBasePath'), SdkRestApi::getParam('apiUserId'), SdkRestApi::getParam('apiUserKey'));
$spaceId = SdkRestApi::getParam('spaceId');

$webhookUrlService = new WebhookUrlService($client);
$webhookListenerService = new WebhookListenerService($client);

$query = new \Wallee\Sdk\Model\EntityQuery();
$query->setNumberOfEntities(1);
$filter = new \Wallee\Sdk\Model\EntityQueryFilter();
$filter->setType(\Wallee\Sdk\Model\EntityQueryFilterType::_AND);
$filter->setChildren([
    WalleeSdkHelper::createEntityFilter('url', SdkRestApi::getParam('notificationUrl')),
    WalleeSdkHelper::createEntityFilter('state', \Wallee\Sdk\Model\CreationEntityState::ACTIVE)
]);
$query->setFilter($filter);
$webhookResult = $webhookUrlService->search($spaceId, $query);
if (empty($webhookResult)) {
    $webhookUrlRequest = new WebhookUrlCreate();
    $webhookUrlRequest->setState(\Wallee\Sdk\Model\CreationEntityState::ACTIVE);
    $webhookUrlRequest->setName('plentymarkets ' . SdkRestApi::getParam('storeId'));
    $webhookUrlRequest->setUrl(SdkRestApi::getParam('notificationUrl'));
    $webhookUrl = $webhookUrlService->create($spaceId, $webhookUrlRequest);
} else {
    $webhookUrl = $webhookResult[0];
}

$query = new \Wallee\Sdk\Model\EntityQuery();
$filter = new \Wallee\Sdk\Model\EntityQueryFilter();
$filter->setType(\Wallee\Sdk\Model\EntityQueryFilterType::_AND);
$filter->setChildren([
    WalleeSdkHelper::createEntityFilter('state', \Wallee\Sdk\Model\CreationEntityState::ACTIVE),
    WalleeSdkHelper::createEntityFilter('url.id', $webhookUrl->getId())
]);
$query->setFilter($filter);
$existingListeners = $webhookListenerService->search($spaceId, $query);

foreach ($webhookEntities as $webhookEntity) {
    $exists = false;
    foreach ($existingListeners as $existingListener) {
        if ($existingListener->getEntity() == $webhookEntity->getId()) {
            $exists = true;
        }
    }

    if (! $exists) {
        $webhookListenerRequest = new WebhookListenerCreate();
        $webhookListenerRequest->setState(\Wallee\Sdk\Model\CreationEntityState::ACTIVE);
        $webhookListenerRequest->setEntity($webhookEntity->getId());
        $webhookListenerRequest->setEntityStates($webhookEntity->getStates());
        $webhookListenerRequest->setName('plentymarkets ' . SdkRestApi::getParam('storeId') . ' ' . $webhookEntity->getName());
        $webhookListenerRequest->setUrl($webhookUrl);

        $webhookListenerService->create($spaceId, $webhookListenerRequest);
    }
}