<?php
namespace Wallee\Models;

use Plenty\Modules\Plugin\DataBase\Contracts\Model;

class Webhook extends Model
{

    /**
     *
     * @var int
     */
    public $id;

    /**
     *
     * @var string
     */
    public $listenerEntityTechnicalName;

    /**
     *
     * @var int
     */
    public $entityId;

    /**
     *
     * @var int
     */
    public $createdAt;

    public function getTableName(): string
    {
        return 'Wallee::Webhook';
    }
}