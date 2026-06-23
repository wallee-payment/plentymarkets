<?php
namespace Wallee\Services;

use Plenty\Modules\Plugin\Libs\Contracts\LibraryCallContract;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;

class WalleeSdkService
{

    use Loggable;

    const GATEWAY_BASE_PATH = 'https://app-wallee.com';

    /**
     *
     * @var LibraryCallContract
     */
    private $libCall;

    /**
     *
     * @var ConfigRepository
     */
    private $config;

    /**
     *
     * @param LibraryCallContract $libCall
     * @param ConfigRepository $config
     */
    public function __construct(LibraryCallContract $libCall, ConfigRepository $config)
    {
        $this->libCall = $libCall;
        $this->config = $config;
    }

    /**
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function call(string $method, array $parameters)
    {
        $parameters['gatewayBasePath'] = self::GATEWAY_BASE_PATH;
        $parameters['apiUserId'] = $this->config->get('wallee.api_user_id');
        $parameters['apiUserKey'] = $this->config->get('wallee.api_user_key');
        if (!isset($parameters['spaceId']) || $parameters['spaceId'] == 0) {
            $parameters['spaceId'] = $this->config->get('wallee.space_id');
        }

        // Timing instrumentation: every SDK method is a Plenty library call that
        // boots an isolated PHP runtime and makes HTTPS round-trips to the Wallee
        // API. Logging the per-call duration lets us see exactly which remote call
        // owns each second of the checkout delay. Remove once profiling is done.
        $start = microtime(true);
        $result = $this->libCall->call('wallee::' . $method, $parameters);
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        $this->getLogger(__METHOD__)->error('Wallee::SdkCallTiming', [
            'method' => $method,
            'durationMs' => $durationMs,
        ]);

        return $result;
    }

    public function validateWebhook(int $spaceId, string $signature, string $payload)
    {
        return $this->call('WebhookService.validate', [
            'spaceId' => $spaceId,
            'signature' => $signature,
            'payload' => $payload
        ]);
    }
}
