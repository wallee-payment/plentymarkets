<?php
use Wallee\Sdk\Model\LineItemReductionCreate;
use Wallee\Sdk\Model\Refund;
use Wallee\Sdk\Model\RefundCreate;
use Wallee\Sdk\Service\RefundService;

require_once __DIR__ . '/WalleeSdkHelper.php';

$client = WalleeSdkHelper::getApiClient(SdkRestApi::getParam('gatewayBasePath'), SdkRestApi::getParam('apiUserId'), SdkRestApi::getParam('apiUserKey'));

$spaceId = SdkRestApi::getParam('spaceId');

$order = SdkRestApi::getParam('order');

$refundRequest = new RefundCreate();

$transactionId = SdkRestApi::getParam('transactionId');
$refundRequest->setTransaction($transactionId);

$refundRequest->setExternalId($order['id']);

$refundRequest->setType(\Wallee\Sdk\Model\RefundType::MERCHANT_INITIATED_ONLINE);

$reductions = [];
foreach ($order['orderItems'] as $item) {
    switch ($item['typeId']) {
        case 1: // Variation
            $reduction = new LineItemReductionCreate();
            $reduction->setLineItemUniqueId($item['itemVariationId']);
            $reduction->setQuantityReduction($item['quantity']);
            $reduction->setUnitPriceReduction(0);
            $reductions[] = $reduction;
            break;
        case 4: // Promotional Coupon
            $reduction = new LineItemReductionCreate();
            $reduction->setLineItemUniqueId('coupon-discount');
            $reduction->setQuantityReduction($item['quantity']);
            $reduction->setUnitPriceReduction(0);
            $reductions[] = $reduction;
            break;
        case 6: // Shipping Costs
            $reduction = new LineItemReductionCreate();
            $reduction->setLineItemUniqueId('shipping');
            $reduction->setQuantityReduction($item['quantity']);
            $reduction->setUnitPriceReduction(0);
            $reductions[] = $reduction;
            break;
        case 7: // Payment Surcharge
            $reduction = new LineItemReductionCreate();
            $reduction->setLineItemUniqueId('payment-fee');
            $reduction->setQuantityReduction($item['quantity']);
            $reduction->setUnitPriceReduction(0);
            $reductions[] = $reduction;
            break;
        default:
            // TODO: Handle more cases:
            // VARIATION = 1
            // ITEM_BUNDLE = 2
            // BUNDLE_COMPONENT = 3
            // PROMOTIONAL_COUPON = 4
            // GIFT_CARD = 5
            // SHIPPING_COSTS = 6
            // PAYMENT_SURCHARGE = 7
            // GIFT_WRAP = 8
            // UNASSIGEND_VARIATION = 9
            // DEPOSIT = 10
            // ORDER = 11
            break;
    }
}
$refundRequest->setReductions($reductions);

$service = new RefundService($client);
$refundResponse = $service->refund($spaceId, $refundRequest);

return [
    'id' => $refundResponse->getId()
];