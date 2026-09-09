<?php
namespace Wallee\Controllers;

use Plenty\Modules\Order\Models\Order;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\Log\Loggable;
use Wallee\Helper\OrderAccessHelper;
use Wallee\Services\WalleeSdkService;

class PaymentTransactionController extends Controller
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
     * @var OrderAccessHelper
     */
    private $orderAccessHelper;

    /**
     * PaymentController constructor.
     *
     * @param Request $request
     * @param Response $response
     * @param WalleeSdkService $sdkService
     * @param OrderAccessHelper $orderAccessHelper
     */
    public function __construct(Request $request, Response $response, WalleeSdkService $sdkService, OrderAccessHelper $orderAccessHelper)
    {
        $this->request = $request;
        $this->response = $response;
        $this->sdkService = $sdkService;
        $this->orderAccessHelper = $orderAccessHelper;
    }

    /**
     * Returns the invoice document of the given order.
     *
     * @param int $orderId
     * @param string $accessKey
     * @return Response
     */
    public function downloadInvoice(int $orderId, string $accessKey)
    {
        return $this->downloadDocument($orderId, $accessKey, 'getInvoiceDocument');
    }

    /**
     * Returns the packing slip of the given order.
     *
     * @param int $orderId
     * @param string $accessKey
     * @return Response
     */
    public function downloadPackingSlip(int $orderId, string $accessKey)
    {
        return $this->downloadDocument($orderId, $accessKey, 'getPackingSlip');
    }

    /**
     * Returns the requested document of an order the current visitor is allowed to access.
     *
     * The transaction is never taken from the url, it is resolved from the order's payment,
     * so documents of other customers cannot be requested by guessing transaction ids.
     *
     * @param int $orderId
     * @param string $accessKey
     * @param string $sdkMethod
     * @return Response
     */
    private function downloadDocument(int $orderId, string $accessKey, string $sdkMethod)
    {
        $order = $this->orderAccessHelper->findOwnOrder($orderId, $accessKey);
        if (! ($order instanceof Order)) {
            $this->getLogger(__METHOD__)->warning('Wallee::DocumentAccessDenied', [
                'orderId' => $orderId,
                'document' => $sdkMethod
            ]);
            return $this->notFound();
        }

        $transactionId = $this->orderAccessHelper->getTransactionIdForOrder($order);
        if (empty($transactionId)) {
            return $this->notFound();
        }

        $transaction = $this->sdkService->call('getTransaction', [
            'id' => $transactionId
        ]);

        // Documents only exist for fulfilled transactions.
        if (! is_array($transaction) || isset($transaction['error']) || ($transaction['state'] ?? '') != 'FULFILL') {
            return $this->notFound();
        }

        $document = $this->sdkService->call($sdkMethod, [
            'id' => $transactionId
        ]);

        if (! is_array($document) || isset($document['error']) || empty($document['data'])) {
            return $this->notFound();
        }

        return $this->download($document);
    }

    private function download($document)
    {
        $title = preg_replace('/[^A-Za-z0-9_\-.]/', '_', (string) $document['title']);

        return $this->response->make(base64_decode($document['data']), 200, [
            'Pragma' => 'public',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Content-type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename=' . $title . '.pdf',
            'Content-Description' => $title
        ]);
    }

    /**
     * Returns an empty 404 response, used for every denied or unresolvable document request
     * so no information about foreign orders or transactions is leaked.
     *
     * @return Response
     */
    private function notFound()
    {
        return $this->response->make('', 404);
    }
}
