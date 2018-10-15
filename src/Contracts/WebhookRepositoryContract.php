<?php
namespace Wallee\Contracts;

use Wallee\Models\Webhook;

interface WebhookRepositoryContract
{

    /**
     * Register a new webhook
     *
     * @param array $data
     * @return Webhook
     */
    public function registerWebhook(array $data): Webhook;

    /**
     * List all webhooks
     *
     * @return Webhook[]
     */
    public function getWebhookList(): array;

    /**
     * Delete a webhook
     *
     * @param int $id
     * @return Webhook
     */
    public function deleteWebhook($id): Webhook;
}