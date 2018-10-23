<?php
use Wallee\Sdk\Service\TransactionService;
use Wallee\Sdk\Model\EntityQuery;
use Wallee\Sdk\Model\EntityQueryFilter;
use Wallee\Sdk\Model\EntityQueryOrderBy;
use Wallee\Sdk\Model\EntityQueryOrderByType;
use Wallee\Sdk\Model\EntityQueryFilterType;
use Wallee\Sdk\Model\CriteriaOperator;

require_once __DIR__ . '/WalleeSdkHelper.php';

$client = WalleeSdkHelper::getApiClient(SdkRestApi::getParam('gatewayBasePath'), SdkRestApi::getParam('apiUserId'), SdkRestApi::getParam('apiUserKey'));

$spaceId = SdkRestApi::getParam('spaceId');

$service = new TransactionService($client);
$query = new EntityQuery();
$filter = new EntityQueryFilter();
$filter->setType(EntityQueryFilterType::LEAF);
$filter->setOperator(CriteriaOperator::EQUALS);
$filter->setFieldName('merchantReference');
$filter->setValue(SdkRestApi::getParam('merchantReference'));
$query->setFilter($filter);
$orderBy = new EntityQueryOrderBy();
$orderBy->setFieldName('createdOn');
$orderBy->setSorting(EntityQueryOrderByType::DESC);
$query->setOrderBys($orderBy);
$query->setNumberOfEntities(1);
$transactions = $service->search($spaceId, $query);

return WalleeSdkHelper::convertData(current($transactions));