<?php
namespace Wallee\Migrations;

use Wallee\Models\Webhook;
use Plenty\Modules\Plugin\DataBase\Contracts\Migrate;

class CreateWebhookTable
{

    /**
     *
     * @param Migrate $migrate
     */
    public function run(Migrate $migrate)
    {
        $migrate->createTable(Webhook::class);
    }
}