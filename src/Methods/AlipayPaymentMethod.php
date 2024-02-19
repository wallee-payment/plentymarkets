<?php
namespace Wallee\Methods;

use Plenty\Plugin\Log\Loggable;

class AlipayPaymentMethod extends AbstractPaymentMethod
{
    use Loggable;

    /**
     * Defines whether the payment method is active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        if ($this->configRepo->get('wallee.alipay_active') === "true") {
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
        /** @var Translator $translator */
        $translator = pluginApp(Translator::class)

        $title = $translator->trans('wallee::AliPay.AliPayTitle');
        if (! empty($title)) {
            return $title;
        } else {
            return 'Alipay';
        }
    }

    /**
     * Returns the fee that is applied when this payment method is used.
     *
     * @return float
     */
    public function getFee(): float
    {
        $fee = $this->configRepo->get('wallee.alipay_fee');
        if (! empty($fee)) {
            return (float) $fee;
        } else {
            return 0.00;
        }
    }

    /**
     * Returns the payment method's description.
     *
     * @return string
     */
    public function getDescription(): string
    {
        $translator = pluginApp(Translator::class)
        $title = $translator->trans('wallee::AliPay.AliPayDescription');
        if (! empty($title)) {
            return $title;
        } else {
            return '';
        }
    }

    /**
     * Returns the payment method's description.
     *
     * @return string
     */
    public function getIcon(): string
    {
        $translator = pluginApp(Translator::class)
        $iconUrl = $translator->trans('wallee::AliPay.AliPayIconUrl');
        if (!empty($iconUrl)) {
            return $iconUrl;
        } else {
            return $this->getImagePath('alipay.svg');
        }
    }
}
