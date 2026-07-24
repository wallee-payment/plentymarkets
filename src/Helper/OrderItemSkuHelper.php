<?php
namespace Wallee\Helper;

class OrderItemSkuHelper
{

    /**
     * @see https://developers.plentymarkets.com/en-gb/plugin-io/5.0.0/IO/Builder/Order/OrderItemType.html
     */
    const TYPE_VARIATION = 1;
    const TYPE_ITEM_BUNDLE = 2;
    const TYPE_BUNDLE_COMPONENT = 3;

    /**
     * @param mixed $compositeSku
     * @param mixed $variationId
     * @return string|null
     */
    public static function extractItemIdFromCompositeSku($compositeSku, $variationId = null)
    {
        if ($compositeSku === null || is_array($compositeSku) || is_object($compositeSku)) {
            return null;
        }

        $compositeSku = trim((string) $compositeSku);
        if ($compositeSku === '') {
            return null;
        }

        if (preg_match('/^([0-9]+)_([0-9]+)$/', $compositeSku, $matches) !== 1) {
            return null;
        }

        if (! empty($variationId) && (string) $matches[2] !== (string) $variationId) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @param array $orderItem
     * @return string|null
     */
    public static function resolveItemIdFromOrderItemArray(array $orderItem)
    {
        $variationId = isset($orderItem['itemVariationId']) ? $orderItem['itemVariationId'] : null;
        $possibleSkuFields = [
            'sku',
            'itemNumber',
            'variationNumber',
            'itemVariationNumber',
            'number'
        ];

        foreach ($possibleSkuFields as $fieldName) {
            if (! isset($orderItem[$fieldName])) {
                continue;
            }
            $itemId = self::extractItemIdFromCompositeSku($orderItem[$fieldName], $variationId);
            if (! empty($itemId)) {
                return $itemId;
            }
        }

        return null;
    }

    /**
     * @param mixed $orderItem
     * @return string|null
     */
    public static function resolveItemIdFromOrderItemModel($orderItem)
    {
        $variationId = $orderItem->itemVariationId ?? null;
        $possibleSkuValues = [
            $orderItem->sku ?? null,
            $orderItem->itemNumber ?? null,
            $orderItem->variationNumber ?? null,
            $orderItem->itemVariationNumber ?? null,
            $orderItem->number ?? null
        ];

        try {
            $attributes = $orderItem->getAttributes();
            if (is_array($attributes)) {
                $possibleSkuValues[] = $attributes['sku'] ?? null;
                $possibleSkuValues[] = $attributes['itemNumber'] ?? null;
                $possibleSkuValues[] = $attributes['variationNumber'] ?? null;
                $possibleSkuValues[] = $attributes['itemVariationNumber'] ?? null;
                $possibleSkuValues[] = $attributes['number'] ?? null;
            }
        } catch (\Throwable $exception) {
            // Ignore and continue with known order item fields.
        }

        foreach ($possibleSkuValues as $possibleSkuValue) {
            $itemId = self::extractItemIdFromCompositeSku($possibleSkuValue, $variationId);
            if (! empty($itemId)) {
                return $itemId;
            }
        }

        return null;
    }

    /**
     * @param mixed $variation
     * @return string|null
     */
    public static function extractItemIdFromVariation($variation)
    {
        $variation = is_object($variation) ? (array) $variation : $variation;
        if (! is_array($variation)) {
            return null;
        }

        if (! empty($variation['itemId'])) {
            return $variation['itemId'];
        }

        if (! empty($variation['item'])) {
            $item = is_object($variation['item']) ? (array) $variation['item'] : $variation['item'];
            if (is_array($item) && ! empty($item['id'])) {
                return $item['id'];
            }
        }

        return null;
    }
}
