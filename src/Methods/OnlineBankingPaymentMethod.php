<?php
namespace Wallee\Methods;

use Plenty\Plugin\Log\Loggable;

class OnlineBankingPaymentMethod extends AbstractPaymentMethod
{
    use Loggable;

    /**
     * Defines whether the payment method is active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        if ($this->configRepo->get('wallee.onlinebanking_active') === "true") {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Returns the payment method's name that is displayed to the customer.
     *
     * @return string
     */
    public function getName(): string
    {
        $title = $this->configRepo->get('wallee.onlinebanking_title');
        if (! empty($title)) {
            return $title;
        } else {
            return 'Online Banking';
        }
    }

    /**
     * Returns the fee that is applied when this payment method is used.
     *
     * @return float
     */
    public function getFee(): float
    {
        $fee = $this->configRepo->get('wallee.onlinebanking_fee');
        if (! empty($fee)) {
            return (float) $fee;
        } else {
            return 0.00;
        }
    }

    /**
     * Returns the path to the payment method's icon.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return $this->getImagePath('online-banking.svg');
    }

    /**
     * Returns the payment method's description.
     *
     * @return string
     */
    public function getDescription(): string
    {
        $title = $this->configRepo->get('wallee.onlinebanking_description');
        if (! empty($title)) {
            return $title;
        } else {
            return '';
        }
    }
}