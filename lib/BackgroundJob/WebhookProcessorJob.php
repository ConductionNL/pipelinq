<?php

/**
 * Pipelinq WebhookProcessorJob.
 *
 * Drains the internal `webhook_queue` (populated by openconnector's
 * webhook ingress) and routes each event to the appropriate adapter
 * (WhatsApp or SMS) for processing.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#6.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SmsAdapter;
use OCA\Pipelinq\Service\WhatsAppAdapter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drains the internal webhook queue.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Bridges OR + two
 * adapters + logger.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#6.2
 */
class WebhookProcessorJob extends TimedJob
{
    /**
     * Default register slug.
     */
    private const DEFAULT_REGISTER_SLUG = 'pipelinq';

    /**
     * Default webhook_queue schema slug.
     */
    private const DEFAULT_SCHEMA_SLUG = 'webhookQueue';

    /**
     * Constructor.
     *
     * @param ITimeFactory       $time            Time factory.
     * @param ContainerInterface $container       DI container.
     * @param IAppConfig         $appConfig       App config.
     * @param WhatsAppAdapter    $whatsAppAdapter WhatsApp adapter.
     * @param SmsAdapter         $smsAdapter      SMS adapter.
     * @param LoggerInterface    $logger          Logger.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#6.2
     */
    public function __construct(
        ITimeFactory $time,
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private WhatsAppAdapter $whatsAppAdapter,
        private SmsAdapter $smsAdapter,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        // Drain the queue every minute.
        $this->setInterval(seconds: 60);
        $this->setTimeSensitivity(sensitivity: self::TIME_SENSITIVE);
    }//end __construct()

    /**
     * Drain the queue.
     *
     * @param mixed $argument Unused.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run($argument): void
    {
        try {
            $this->drain();
        } catch (Throwable $e) {
            $this->logger->error(
                'WebhookProcessorJob failed',
                ['exception' => $e->getMessage()]
            );
        }
    }//end run()

    /**
     * Drain `status: queued` rows.
     *
     * @return void
     */
    private function drain(): void
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return;
        }

        try {
            $rows = $objectService->findAll(
                filters: ['status' => 'queued'],
                register: $this->getRegisterSlug(),
                schema: $this->getSchemaSlug(),
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'WebhookProcessorJob.drain: findAll failed',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        if (is_array($rows) === false || $rows === []) {
            return;
        }

        foreach ($rows as $raw) {
            $arr        = $this->toArray(value: $raw);
            $channel    = (string) ($arr['channel'] ?? '');
            $providerId = (string) ($arr['providerId'] ?? '');
            $signature  = (string) ($arr['signature'] ?? '');
            $body       = (string) ($arr['rawBody'] ?? '');
            $id         = $this->extractId(payload: $arr);

            try {
                if ($channel === 'whatsapp') {
                    $result = $this->whatsAppAdapter->handleInboundWebhook(
                        rawBody: $body,
                        signature: $signature,
                        providerId: $providerId,
                    );
                } else if ($channel === 'sms') {
                    $result = $this->smsAdapter->handleInboundWebhook(
                        rawBody: $body,
                        signature: $signature,
                        providerId: $providerId,
                    );
                } else {
                    $result = ['status' => 'unknownChannel'];
                }
            } catch (Throwable $e) {
                $this->logger->warning(
                    'WebhookProcessorJob.drain: processing failed',
                    ['id' => $id, 'exception' => $e->getMessage()]
                );
                $result = ['status' => 'processingFailed', 'error' => $e->getMessage()];
            }//end try

            $arr['status']      = (string) ($result['status'] ?? 'processed');
            $arr['processedAt'] = gmdate('Y-m-d\TH:i:s\Z');
            $arr['result']      = $result;

            try {
                if ($id === '') {
                    $saveUuid = null;
                } else {
                    $saveUuid = $id;
                }

                $objectService->saveObject(
                    object: $arr,
                    register: $this->getRegisterSlug(),
                    schema: $this->getSchemaSlug(),
                    uuid: $saveUuid,
                );
            } catch (Throwable $e) {
                $this->logger->warning(
                    'WebhookProcessorJob.drain: save failed',
                    ['id' => $id, 'exception' => $e->getMessage()]
                );
            }
        }//end foreach
    }//end drain()

    /**
     * Resolve OpenRegister ObjectService.
     *
     * @return object|null Service or null.
     */
    private function getObjectService(): ?object
    {
        try {
            return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (Throwable $e) {
            $this->logger->warning(
                'WebhookProcessorJob.getObjectService: OpenRegister unavailable',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getObjectService()

    /**
     * Normalise an OR entity to an array.
     *
     * @param mixed $value Entity or array.
     *
     * @return array<string, mixed> Plain payload.
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
            $serialised = $value->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        if (is_object($value) === true && method_exists($value, 'getObject') === true) {
            $payload = $value->getObject();
            if (is_array($payload) === true) {
                return $payload;
            }
        }

        return [];
    }//end toArray()

    /**
     * Extract a UUID / id / slug from a payload.
     *
     * @param array<string, mixed> $payload Payload.
     *
     * @return string Id or empty.
     */
    private function extractId(array $payload): string
    {
        foreach (['uuid', 'id', 'slug'] as $key) {
            if (isset($payload[$key]) === true && is_scalar($payload[$key]) === true && (string) $payload[$key] !== '') {
                return (string) $payload[$key];
            }
        }

        if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
            foreach (['uuid', 'id', 'slug'] as $key) {
                $value = ($payload['@self'][$key] ?? null);
                if (is_scalar($value) === true && (string) $value !== '') {
                    return (string) $value;
                }
            }
        }

        return '';
    }//end extractId()

    /**
     * Register slug.
     *
     * @return string Slug.
     */
    private function getRegisterSlug(): string
    {
        $slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        if ($slug !== '') {
            return $slug;
        }

        return self::DEFAULT_REGISTER_SLUG;
    }//end getRegisterSlug()

    /**
     * Schema slug.
     *
     * @return string Slug.
     */
    private function getSchemaSlug(): string
    {
        $slug = $this->appConfig->getValueString(Application::APP_ID, 'webhookQueue_schema', '');
        if ($slug !== '') {
            return $slug;
        }

        return self::DEFAULT_SCHEMA_SLUG;
    }//end getSchemaSlug()
}//end class
