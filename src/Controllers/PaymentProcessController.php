<?php
namespace Wallee\Controllers;

use IO\Services\NotificationService;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\Log\Loggable;
use Wallee\Services\WalleeSdkService;

class PaymentProcessController extends Controller
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
     * @var WalleeSdkService
     */
    private $sdkService;

    /**
     *
     * @var NotificationService
     */
    private $notificationService;

    /**
     * PaymentController constructor.
     *
     * @param Request $request
     * @param Response $response
     * @param WalleeSdkService $sdkService
     * @param NotificationService $notificationService
     */
    public function __construct(Request $request, Response $response, WalleeSdkService $sdkService, NotificationService $notificationService)
    {
        parent::__construct();
        $this->request = $request;
        $this->response = $response;
        $this->sdkService = $sdkService;
        $this->notificationService = $notificationService;
    }

    /**
     *
     * @param int $id
     */
    public function failTransaction(int $id)
    {
        $transaction = $this->sdkService->call('getTransactionByMerchantReference', [
            'merchantReference' => $id
        ]);
        $this->getLogger(__METHOD__)->debug('wallee:failTransaction', $transaction);
        if (is_array($transaction) && ! isset($transaction['error']) && isset($transaction['userFailureMessage']) && ! empty($transaction['userFailureMessage'])) {
            $this->notificationService->error($transaction['userFailureMessage']);
        }
        return $this->response->redirectTo('checkout');
    }
}