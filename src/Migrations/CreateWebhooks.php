<?php
namespace Wallee\Migrations;

use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Plugin\Log\Loggable;
use Wallee\Services\PaymentService;

class CreateWebhooks
{

    use Loggable;

    /**
     *
     * @var PaymentService
     */
    private $paymentService;

    /**
     * Constructor.
     *
     * @param PaymentService $paymentService
     */
    public function __construct(
        PaymentService $paymentService
    ) {
        $this->paymentService = $paymentService;
    }

    /**
     * Creates the webhook listeners for the wallee plugin.
     */
    public function run()
    {
        // The webhook listeners are created through the API, which fails as long as the API
        // credentials are not configured yet - the normal state during a first deployment. An
        // uncaught exception here aborts the deployment and leaves the plugin without its payment
        // methods, so the failure is only logged. WebhookCronHandler retries every 15 minutes.
        try {
            $this->paymentService->createWebhook();
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Wallee::DeploymentWebhookCreationFailed', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
