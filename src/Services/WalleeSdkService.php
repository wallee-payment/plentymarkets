<?php
namespace Wallee\Services;

use Plenty\Modules\Plugin\Libs\Contracts\LibraryCallContract;
use Plenty\Plugin\ConfigRepository;

class WalleeSdkService
{

    const GATEWAY_BASE_PATH = 'https://staging-wallee.com:443';

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
        $parameters['apiUserId'] = $this->config->get('Wallee.api_user_id');
        $parameters['apiUserKey'] = $this->config->get('Wallee.api_user_key');
        $parameters['spaceId'] = $this->config->get('Wallee.space_id');
        return $this->libCall->call('Wallee::' . $method, $parameters);
    }
}