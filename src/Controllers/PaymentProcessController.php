<?php
namespace Wallee\Controllers;

use IO\Services\NotificationService;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\Log\Loggable;
use Wallee\Services\WalleeSdkService;
use Wallee\Helper\PaymentHelper;

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
     *
     * @var PaymentHelper
     */
    private $paymentHelper;

    /**
     * PaymentController constructor.
     *
     * @param Request $request
     * @param Response $response
     * @param WalleeSdkService $sdkService
     * @param NotificationService $notificationService
     * @param PaymentHelper $paymentHelper
     */
    public function __construct(Request $request, Response $response, WalleeSdkService $sdkService, NotificationService $notificationService, PaymentHelper $paymentHelper)
    {
        parent::__construct();
        $this->request = $request;
        $this->response = $response;
        $this->sdkService = $sdkService;
        $this->notificationService = $notificationService;
        $this->paymentHelper = $paymentHelper;
    }

    /**
     *
     * @param int $id
     */
    public function failTransaction(int $id)
    {
        $transaction = $this->sdkService->call('getTransaction', [
            'id' => $id
        ]);
        $this->getLogger(__METHOD__)->debug('wallee:failTransaction', $transaction);
        if (is_array($transaction) && ! isset($transaction['error']) && isset($transaction['userFailureMessage']) && ! empty($transaction['userFailureMessage'])) {
            $this->notificationService->error($transaction['userFailureMessage']);
            $this->paymentHelper->updatePlentyPayment($transaction);
        }
        return $this->response->redirectTo('confirmation');
    }
}