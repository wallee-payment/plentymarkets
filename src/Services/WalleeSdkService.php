<?php
namespace Wallee\Services;

use Plenty\Modules\Plugin\Libs\Contracts\LibraryCallContract;
use Plenty\Plugin\ConfigRepository;

class WalleeSdkService
{

    const GATEWAY_BASE_PATH = 'https://staging-wallee.com';

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
        return $this->libCall->call('wallee::' . $method, $parameters);
    }
}
