<?php
namespace Wallee\Methods;

use Plenty\Plugin\Log\Loggable;
use Plenty\Plugin\Translation\Translator;

class Przelewy24PaymentMethod extends AbstractPaymentMethod
{
    use Loggable;

    /**
     * Defines whether the payment method is active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        if ($this->configRepo->get('wallee.przelewy24_active') == "true") {
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
    public function getName(string $lang = 'de'): string
    {
        /** @var Translator $translator */
        $translator = pluginApp(Translator::class);

        $title = $translator->trans('wallee::Payment.Przelewy24Title', [], $lang);
        if (! empty($title)) {
            return $title;
        } else {
            return 'Przelewy24';
        }
    }

    /**
     * Returns the fee that is applied when this payment method is used.
     *
     * @return float
     */
    public function getFee(): float
    {
        $fee = $this->configRepo->get('wallee.przelewy24_fee');
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
    public function getDescription(string $lang = 'de'): string
    {
        /** @var Translator $translator */
        $translator = pluginApp(Translator::class);

        $title = $translator->trans('wallee::Payment.Przelewy24Description', [], $lang);
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
    public function getIcon(string $lang = 'de'): string
    {
        /** @var Translator $translator */
        $translator = pluginApp(Translator::class);

        $iconUrl = $translator->trans('wallee::Payment.Przelewy24IconUrl', [], $lang);
        if (!empty($iconUrl)) {
            return $iconUrl;
        } else {
            return $this->getImagePath('przelewy24.svg');
        }
    }
}