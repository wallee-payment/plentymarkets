<?php
namespace Wallee\Models;

use Plenty\Modules\Plugin\DataBase\Contracts\Model;

/**
 * Class Webhook
 *
 * @property int $id
 * @property string $listenerEntityTechnicalName
 * @property int $entityId
 * @property int $createdAt
 */
class Webhook extends Model
{

    /**
     *
     * @var int
     */
    public $id = 0;

    /**
     *
     * @var string
     */
    public $listenerEntityTechnicalName = '';

    /**
     *
     * @var int
     */
    public $entityId = 0;

    /**
     *
     * @var int
     */
    public $createdAt;

    /**
     *
     * @return string
     */
    public function getTableName(): string
    {
        return 'wallee::Webhook';
    }
}