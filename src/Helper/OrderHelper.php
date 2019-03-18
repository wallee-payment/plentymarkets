<?php
namespace Wallee\Helper;

use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Property\Models\OrderProperty;
use Plenty\Modules\Order\RelationReference\Models\OrderRelationReference;

class OrderHelper
{
    use Loggable;

    public function getOrderRelationId(Order $order, $referenceType)
    {
        $relations = $order->relations;
        if (($relations->count() > 0) || (is_array($relations) && count($relations) > 0)) {
            /** @var OrderRelationReference $relation */
            foreach ($relations as $relation) {
                if ($relation instanceof OrderRelationReference) {
                    if ($relation->referenceType == $referenceType) {
                        return $relation->referenceId;
                    }
                }
            }
        }
        return null;
    }

    /**
     *
     * @param Order $order
     * @param int $propertyType
     * @return null|string
     */
    public function getOrderPropertyValue(Order $order, $propertyType)
    {
        $properties = $order->properties;
        if (($properties->count() > 0) || (is_array($properties) && count($properties) > 0)) {
            /** @var OrderProperty $property */
            foreach ($properties as $property) {
                if ($property instanceof OrderProperty) {
                    if ($property->typeId == $propertyType) {
                        return $property->value;
                    }
                }
            }
        }
        return null;
    }
}