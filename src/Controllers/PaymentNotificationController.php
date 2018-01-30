<?php
namespace Wallee\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Wallee\Helper\PaymentHelper;
use Wallee\Services\PaymentService;
use Plenty\Plugin\Log\Loggable;
use Wallee\Services\WalleeSdkService;

class PaymentNotificationController extends Controller
{

    use Loggable;

    /**
     *
     * @var Request
     */
    private $request;

    /**
     *
     * @var Response
     */
    private $response;

    /**
     *
     * @var ConfigRepository
     */
    private $config;

    /**
     *
     * @var PaymentHelper
     */
    private $paymentHelper;

    /**
     *
     * @var PaymentService
     */
    private $paymentService;

    /**
     *
     * @var WalleeSdkService
     */
    private $sdkService;

    /**
     * PaymentController constructor.
     *
     * @param Request $request
     * @param Response $response
     * @param ConfigRepository $config
     * @param PaymentHelper $paymentHelper
     * @param PaymentService $paymentService
     * @param WalleeSdkService $sdkService
     */
    public function __construct(Request $request, Response $response, ConfigRepository $config, PaymentHelper $paymentHelper, PaymentService $paymentService, WalleeSdkService $sdkService)
    {
        $this->request = $request;
        $this->response = $response;
        $this->config = $config;
        $this->paymentHelper = $paymentHelper;
        $this->paymentService = $paymentService;
        $this->sdkService = $sdkService;
    }

    public function updateTransaction()
    {
        $webhookRequest = json_decode($this->request->getContent());
        $this->getLogger(__METHOD__)->error('webhookRequest', $webhookRequest);

        if (strtolower($webhookRequest->listenerEntityTechnicalName) == 'transaction') {
            $transactionId = $webhookRequest->entityId;
            $transaction = $this->sdkService->call('getTransaction', [
                'id' => $transactionId
            ]);
            if (is_array($transaction) && isset($transaction['error'])) {
                throw new \Exception($transaction['error_msg']);
            }
            $this->paymentHelper->updatePlentyPayment($transaction);
        } elseif (strtolower($webhookRequest->listenerEntityTechnicalName) == 'transactioninvoice') {
            $transactionInvoiceId = $webhookRequest->entityId;
            $transactionInvoice = $this->sdkService->call('getTransactionInvoice', [
                'id' => $transactionInvoiceId
            ]);
            if (is_array($transactionInvoice) && isset($transactionInvoice['error'])) {
                throw new \Exception($transactionInvoice['error_msg']);
            }
            $this->paymentHelper->updateInvoice($transactionInvoice);
        }
    }
}