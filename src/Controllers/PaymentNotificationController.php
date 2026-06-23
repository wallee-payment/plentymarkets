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
use Wallee\Contracts\WebhookRepositoryContract;

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
     *
     * @var WebhookRepositoryContract
     */
    private $webhookRepository;

    /**
     * PaymentController constructor.
     *
     * @param Request $request
     * @param Response $response
     * @param ConfigRepository $config
     * @param PaymentHelper $paymentHelper
     * @param PaymentService $paymentService
     * @param WalleeSdkService $sdkService
     * @param WebhookRepositoryContract $webhookRepository
     */
    public function __construct(
        Request $request,
        Response $response,
        ConfigRepository $config,
        PaymentHelper $paymentHelper,
        PaymentService $paymentService,
        WalleeSdkService $sdkService,
        WebhookRepositoryContract $webhookRepository
    )
    {
        $this->request = $request;
        $this->response = $response;
        $this->config = $config;
        $this->paymentHelper = $paymentHelper;
        $this->paymentService = $paymentService;
        $this->sdkService = $sdkService;
        $this->webhookRepository = $webhookRepository;
    }

    public function updateTransaction()
    {
        $rawBody = $this->request->getContent();
        $signature = $this->request->header('x-signature');

        if (!$signature) {
            $this->getLogger(__METHOD__)->error('Webhook without signature');
            return $this->response->make('', 401);
        }

        try {
            $decoded = json_decode($rawBody);

            $this->sdkService->validateWebhook(
                $decoded->spaceId,
                $signature,
                $rawBody
            );

        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error(
                'Webhook signature validation failed',
                [
                    'exceptionMessage' => $e->getMessage(),
                    'exceptionCode' => $e->getCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            );
            return $this->response->make('', 403);
        }

        $webhookRequest = json_decode($this->request->getContent());

        if (in_array(strtolower($webhookRequest->listenerEntityTechnicalName), [
            'transaction',
            'transactioninvoice',
            'refund'
        ])) {
            $this->webhookRepository->registerWebhook([
                'listenerEntityTechnicalName' => $webhookRequest->listenerEntityTechnicalName,
                'entityId' => $webhookRequest->entityId,
                'spaceId' => $webhookRequest->spaceId
            ]);
        }
        return "OK";
    }
}