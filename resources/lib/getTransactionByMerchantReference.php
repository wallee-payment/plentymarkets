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

$merchantReferenceFilter = new EntityQueryFilter();
$merchantReferenceFilter->setType(EntityQueryFilterType::LEAF);
$merchantReferenceFilter->setOperator(CriteriaOperator::EQUALS);
$merchantReferenceFilter->setFieldName('merchantReference');
$merchantReferenceFilter->setValue(SdkRestApi::getParam('merchantReference'));

// To avoid overlapping usage of old IDs we check also that the already 
// created transaction is not very long back.
$dateFilter = new EntityQueryFilter();
$dateFilter->setType(EntityQueryFilterType::LEAF);
$dateFilter->setOperator(CriteriaOperator::GREATER_THAN);
$dateFilter->setFieldName('createdOn');
$dateFilter->setValue(date('c', strtotime("-3 months")));

$filter = new EntityQueryFilter();
$filter->setType(EntityQueryFilterType::_AND);
$filter->setChildren([$dateFilter, $merchantReferenceFilter]);

$query = new EntityQuery();
$query->setFilter($filter);
$orderBy = new EntityQueryOrderBy();
$orderBy->setFieldName('createdOn');
$orderBy->setSorting(EntityQueryOrderByType::DESC);
$query->setOrderBys($orderBy);
$query->setNumberOfEntities(1);
$transactions = $service->search($spaceId, $query);

return WalleeSdkHelper::convertData(current($transactions));