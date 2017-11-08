<?php
use Wallee\Sdk\ApiClient;
use Wallee\Sdk\Model\LineItem;

class WalleeSdkHelper
{

    /**
     *
     * @param string $gatewayBasePath
     * @param string $userId
     * @param string $userKey
     * @return \Wallee\Sdk\ApiClient
     */
    public static function getApiClient($gatewayBasePath, $userId, $userKey): ApiClient
    {
        $client = new ApiClient($userId, $userKey);
        $client->setBasePath($gatewayBasePath . '/api');
        return $client;
    }

    /**
     *
     * @param float $amount
     * @param number $currencyDecimalPlaces
     * @return float
     */
    public static function roundAmount($amount, $currencyDecimalPlaces = 2)
    {
        return round($amount, $currencyDecimalPlaces);
    }

    /**
     *
     * @param LineItem[] $lineItems
     * @return float
     */
    public static function calculateLineItemTotalAmount(array $lineItems)
    {
        $total = 0;
        foreach ($lineItems as $lineItem) {
            $total += $lineItem->getAmountIncludingTax();
        }
        return $total;
    }

    /**
     * Convert data to string|array.
     *
     * @param mixed $data
     *            the data to string|array
     * @return string|array
     */
    public static function convertData($data)
    {
        if (is_scalar($data) || null === $data) {
            return $data;
        } elseif ($data instanceof \DateTime) {
            return $data->format(\DateTime::ATOM);
        } elseif (is_array($data)) {
            foreach ($data as $property => $value) {
                $data[$property] = self::convertData($value);
            }
            return $data;
        } elseif (is_object($data)) {
            $values = [];
            foreach (array_keys($data::swaggerTypes()) as $property) {
                $getter = 'get' . ucfirst($property);
                if ($data->$getter() !== null) {
                    $values[$property] = self::convertData($data->$getter());
                }
            }
            return $values;
        } else {
            return (string) $data;
        }
    }

    /**
     * Creates and returns a new entity filter.
     *
     * @param string $fieldName
     * @param mixed $value
     * @param string $operator
     * @return \Wallee\Sdk\Model\EntityQueryFilter
     */
    public static function createEntityFilter($fieldName, $value, $operator = \Wallee\Sdk\Model\CriteriaOperator::EQUALS)
    {
        $filter = new \Wallee\Sdk\Model\EntityQueryFilter();
        $filter->setType(\Wallee\Sdk\Model\EntityQueryFilterType::LEAF);
        $filter->setOperator($operator);
        $filter->setFieldName($fieldName);
        $filter->setValue($value);
        return $filter;
    }
}