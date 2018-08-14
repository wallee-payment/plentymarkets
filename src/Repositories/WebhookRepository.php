<?php
namespace Wallee\Repositories;

use Wallee\Contracts\WebhookRepositoryContract;
use Wallee\Models\Webhook;
use Plenty\Modules\Plugin\DataBase\Contracts\DataBase;

class WebhookRepository implements WebhookRepositoryContract
{

    public function registerWebhook(array $data): Webhook
    {
        /**
         *
         * @var DataBase $database
         */
        $database = pluginApp(DataBase::class);

        $webhookList = $database->query(Webhook::class)
            ->where('listenerEntityTechnicalName', '=', $data['listenerEntityTechnicalName'])
            ->where('entityId', '=', $data['entityId'])
            ->get();
        if (! empty($webhookList)) {
            return current($webhookList);
        }

        $webhook = pluginApp(Webhook::class);

        $webhook->listenerEntityTechnicalName = $data['listenerEntityTechnicalName'];
        $webhook->entityId = $data['entityId'];
        $webhook->createdAt = time();

        $database->save($webhook);

        return $webhook;
    }

    public function deleteWebhook($id): Webhook
    {
        /**
         *
         * @var DataBase $database
         */
        $database = pluginApp(DataBase::class);

        $webhookList = $database->query(Webhook::class)
            ->where('id', '=', $id)
            ->get();

        $webhook = $webhookList[0];
        $database->delete($webhook);

        return $webhook;
    }

    public function getWebhookList(): array
    {
        $database = pluginApp(DataBase::class);

        /**
         *
         * @var Webhook[] $webhookList
         */
        $webhookList = $database->query(Webhook::class)->get();
        return $webhookList;
    }
}