<?php
namespace Wallee\Providers;

use Plenty\Plugin\Events\Dispatcher;
use Plenty\Plugin\ServiceProvider;
use Plenty\Modules\Basket\Events\Basket\AfterBasketCreate;
use Plenty\Modules\Basket\Events\Basket\AfterBasketChanged;
use Plenty\Modules\Payment\Events\Checkout\GetPaymentMethodContent;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodContainer;
use Wallee\Helper\PaymentHelper;
use Wallee\Helper\WalleeServiceProviderHelper;
use Wallee\Services\PaymentService;
use Plenty\Modules\Payment\Events\Checkout\ExecutePayment;
use Wallee\Methods\CreditDebitCardPaymentMethod;
use Wallee\Methods\InvoicePaymentMethod;
use Wallee\Methods\OnlineBankingPaymentMethod;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Wallee\Methods\AlipayPaymentMethod;
use Wallee\Methods\BankTransferPaymentMethod;
use Wallee\Methods\CashuPaymentMethod;
use Wallee\Methods\DaoPayPaymentMethod;
use Wallee\Methods\DirectDebitSepaPaymentMethod;
use Wallee\Methods\DirectDebitUkPaymentMethod;
use Wallee\Methods\EpsPaymentMethod;
use Wallee\Methods\GiropayPaymentMethod;
use Wallee\Methods\IDealPaymentMethod;
use Wallee\Methods\MasterPassPaymentMethod;
use Wallee\Methods\PayboxPaymentMethod;
use Wallee\Methods\PaydirektPaymentMethod;
use Wallee\Methods\PaylibPaymentMethod;
use Wallee\Methods\PayPalPaymentMethod;
use Wallee\Methods\PaysafecardPaymentMethod;
use Wallee\Methods\PoliPaymentMethod;
use Wallee\Methods\Przelewy24PaymentMethod;
use Wallee\Methods\QiwiPaymentMethod;
use Wallee\Methods\SkrillPaymentMethod;
use Wallee\Methods\SofortBankingPaymentMethod;
use Wallee\Methods\TenpayPaymentMethod;
use Wallee\Methods\TrustlyPaymentMethod;
use Wallee\Methods\TwintPaymentMethod;
use Wallee\Procedures\RefundEventProcedure;
use Plenty\Modules\EventProcedures\Services\EventProceduresService;
use Plenty\Modules\EventProcedures\Services\Entries\ProcedureEntry;
use Plenty\Modules\Cron\Services\CronContainer;
use Wallee\Services\WebhookCronHandler;
use Wallee\Contracts\WebhookRepositoryContract;
use Wallee\Repositories\WebhookRepository;
use IO\Services\BasketService;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;

class WalleeServiceProvider extends ServiceProvider
{

    public function register()
    {
        $this->getApplication()->register(WalleeRouteServiceProvider::class);
        $this->getApplication()->bind(WebhookRepositoryContract::class, WebhookRepository::class);
        $this->getApplication()->bind(RefundEventProcedure::class);
    }

    /**
     * Boot services of the wallee plugin.
     *
     * @param PaymentMethodContainer $payContainer
     */
    public function boot(
        PaymentMethodContainer $payContainer,
        EventProceduresService $eventProceduresService,
        CronContainer $cronContainer,
        WalleeServiceProviderHelper $walleeServiceProviderHelper,
        PaymentService $paymentService
    ) {
        $this->registerPaymentMethod($payContainer, 1457546097615, AlipayPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1457546097602, BankTransferPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1477573906453, CashuPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1457546097597, CreditDebitCardPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1477574926155, DaoPayPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1457546097601, DirectDebitSepaPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1464254757862, DirectDebitUkPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1457546097609, EpsPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1457546097610, GiropayPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1461674005576, IDealPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1457546097598, InvoicePaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1457546097621, MasterPassPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1460954915005, OnlineBankingPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1484231986107, PayboxPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1457546097640, PaydirektPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1476259715349, PaylibPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1457546097613, PayPalPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1457546097612, PaysafecardPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1457546097618, PoliPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1689233132073, PostFinancePayPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1457546097617, Przelewy24PaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1457546097616, QiwiPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1457546097614, SkrillPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1457546097603, SofortBankingPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1477574502344, TenpayPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1457546097619, TrustlyPaymentMethod::class);
        $this->registerPaymentMethod($payContainer, 1457546097639, TwintPaymentMethod::class);

        // Register Refund Event Procedure
        $eventProceduresService->registerProcedure('plentyWallee', ProcedureEntry::PROCEDURE_GROUP_ORDER, [
            'de' => 'Rückzahlung der wallee-Zahlung',
            'en' => 'Refund the wallee payment'
        ], 'Wallee\Procedures\RefundEventProcedure@run');

        $walleeServiceProviderHelper->addExecutePaymentContentEventListener();

        $cronContainer->add(CronContainer::EVERY_FIFTEEN_MINUTES, WebhookCronHandler::class);
    }

    private function registerPaymentMethod($payContainer, $id, $class)
    {
        $payContainer->register('wallee::' . $id, $class, [
            AfterBasketChanged::class,
            AfterBasketCreate::class
        ]);
    }
}