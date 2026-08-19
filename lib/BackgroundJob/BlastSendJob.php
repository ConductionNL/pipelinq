<?php

/**
 * Pipelinq BlastSendJob.
 *
 * TimedJob that drives the marketing dispatch loop. Every 5 minutes it
 * queries Blasts with status `sending`, calls
 * {@see BlastService::dispatchBlastDeliveries()} per Blast (catching
 * per-Blast failures so a single bad row never aborts the whole job),
 * then drains the webhook event queue and processes any inbound events
 * via {@see WebhookProcessorService}.
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
 * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#blastsendjob
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\BlastService;
use OCA\Pipelinq\Service\WebhookProcessorService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background job that dispatches `sending` Blasts and drains webhook events.
 *
 * - Interval: 300 seconds (5 minutes) — matches the proposal's promise of
 *   "unsubscribe propagates within minutes".
 * - Catches per-Blast errors: a failed Blast logs and the loop continues.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#blastsendjob
 */
class BlastSendJob extends TimedJob
{
    /**
     * Interval in seconds (5 minutes).
     *
     * @var int
     */
    private const INTERVAL = 300;

    /**
     * App-config key that holds the JSON-encoded webhook event queue.
     */
    public const WEBHOOK_QUEUE_KEY = 'blast.webhook_queue';

    /**
     * Maximum number of webhook events drained per run — guards against a
     * runaway queue dominating the job budget.
     */
    private const MAX_DRAIN_PER_RUN = 500;

    /**
     * Constructor.
     *
     * @param ITimeFactory            $time             The time factory.
     * @param IAppConfig              $appConfig        The app config.
     * @param ContainerInterface      $container        DI container for lazy OR.
     * @param BlastService            $blastService     Dispatch service.
     * @param WebhookProcessorService $webhookProcessor Webhook event processor.
     * @param LoggerInterface         $logger           Logger.
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#blastsendjob
     */
    public function __construct(
        ITimeFactory $time,
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private BlastService $blastService,
        private WebhookProcessorService $webhookProcessor,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::INTERVAL);
    }//end __construct()

    /**
     * Run the dispatch + webhook drain loop.
     *
     * @param mixed $argument The job argument (unused).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by TimedJob::run().
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#blastsendjob
     */
    protected function run(mixed $argument): void
    {
        $this->logger->info('BlastSendJob: starting cycle');

        $dispatched = $this->dispatchSendingBlasts();
        $drained    = $this->drainWebhookQueue();

        $this->logger->info(
            'BlastSendJob: cycle complete',
            ['dispatched' => $dispatched, 'webhookEventsDrained' => $drained]
        );
    }//end run()

    /**
     * Iterate Blasts with status `sending` and dispatch each.
     *
     * Per-Blast errors are caught + logged so a single failure cannot
     * block the queue.
     *
     * @return int Total deliveries dispatched across all Blasts.
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#blastsendjob
     */
    public function dispatchSendingBlasts(): int
    {
        $blasts = $this->loadSendingBlasts();
        if ($blasts === []) {
            $this->logger->debug('BlastSendJob: no sending blasts');
            return 0;
        }

        $total = 0;
        foreach ($blasts as $blast) {
            $blastId = $this->extractBlastId(blast: $blast);
            if ($blastId === '') {
                continue;
            }

            try {
                $dispatched = $this->blastService->dispatchBlastDeliveries(blastId: $blastId);
                $total     += $dispatched;
                $this->blastService->updateBlastTotals(blastId: $blastId);
            } catch (Throwable $e) {
                // Critical: never abort the whole job on a per-Blast failure.
                $this->logger->warning(
                    'BlastSendJob: dispatch failed for blast — continuing',
                    ['blastId' => $blastId, 'exception' => $e->getMessage()]
                );
                continue;
            }
        }

        return $total;
    }//end dispatchSendingBlasts()

    /**
     * Drain queued webhook events into the processor.
     *
     * Reads the JSON-encoded queue from app config, pops up to
     * {@see MAX_DRAIN_PER_RUN} events, hands each to
     * {@see WebhookProcessorService::processEvent()}, and writes the
     * remainder back. The remainder write happens even on a partial run
     * so a high-volume burst is absorbed across multiple cycles.
     *
     * @return int Number of events drained.
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#blastsendjob
     */
    public function drainWebhookQueue(): int
    {
        $queue = $this->readQueue();
        if ($queue === []) {
            return 0;
        }

        $batch     = array_slice($queue, 0, self::MAX_DRAIN_PER_RUN);
        $remainder = array_slice($queue, count($batch));

        $processed = 0;
        foreach ($batch as $envelope) {
            $provider = (string) ($envelope['provider'] ?? 'unknown');
            $event    = ($envelope['event'] ?? []);
            if (is_array($event) === false) {
                continue;
            }

            try {
                $this->webhookProcessor->processEvent(event: $event, provider: $provider);
                $processed++;
            } catch (Throwable $e) {
                $this->logger->warning(
                    'BlastSendJob: webhook event processing failed',
                    ['provider' => $provider, 'exception' => $e->getMessage()]
                );
            }
        }//end foreach

        $this->writeQueue(queue: $remainder);
        return $processed;
    }//end drainWebhookQueue()

    /**
     * Append one webhook event envelope to the queue (called from the
     * BlastWebhookController fast path).
     *
     * @param string               $provider Provider key.
     * @param array<string, mixed> $event    Normalised event payload.
     *
     * @return void
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
     */
    public function enqueueWebhookEvent(string $provider, array $event): void
    {
        $queue   = $this->readQueue();
        $queue[] = [
            'provider'   => $provider,
            'event'      => $event,
            'receivedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $this->writeQueue(queue: $queue);
    }//end enqueueWebhookEvent()

    /**
     * Load Blasts with status `sending` via OpenRegister.
     *
     * @return array<int, array<string, mixed>> Sending blasts.
     */
    private function loadSendingBlasts(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'pipelinq');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'blast_schema', 'blast');
        if ($register === '' || $schema === '') {
            return [];
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return [];
        }

        try {
            $rows = $objectService->findAll(
                config: [
                    'filters' => [
                        'status'   => 'sending',
                        'register' => $register,
                        'schema'   => $schema,
                    ],
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'BlastSendJob.loadSendingBlasts: findAll failed',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        $out = [];
        foreach (($rows ?? []) as $row) {
            $payload = $this->toArray(value: $row);
            if ($payload !== []) {
                $out[] = $payload;
            }
        }

        return $out;
    }//end loadSendingBlasts()

    /**
     * Read the webhook queue from app config.
     *
     * @return array<int, array<string, mixed>> Queue entries.
     */
    private function readQueue(): array
    {
        $raw = $this->appConfig->getValueString(Application::APP_ID, self::WEBHOOK_QUEUE_KEY, '');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) === false) {
            return [];
        }

        return $decoded;
    }//end readQueue()

    /**
     * Persist the webhook queue back to app config.
     *
     * @param array<int, array<string, mixed>> $queue Queue entries.
     *
     * @return void
     */
    private function writeQueue(array $queue): void
    {
        if ($queue === []) {
            try {
                $this->appConfig->deleteKey(Application::APP_ID, self::WEBHOOK_QUEUE_KEY);
            } catch (Throwable $e) {
                $this->logger->info(
                    'BlastSendJob.writeQueue: deleteKey failed — falling back to empty array',
                    ['exception' => $e->getMessage()]
                );
                $this->appConfig->setValueString(Application::APP_ID, self::WEBHOOK_QUEUE_KEY, '[]');
            }

            return;
        }

        $encoded = json_encode($queue);
        if ($encoded === false) {
            return;
        }

        $this->appConfig->setValueString(Application::APP_ID, self::WEBHOOK_QUEUE_KEY, $encoded);
    }//end writeQueue()

    /**
     * Extract a Blast identifier from a loaded row.
     *
     * @param array<string, mixed> $blast Blast payload.
     *
     * @return string Identifier or empty.
     */
    private function extractBlastId(array $blast): string
    {
        foreach (['uuid', 'id', 'slug'] as $key) {
            $value = ($blast[$key] ?? null);
            if (is_scalar($value) === true && (string) $value !== '') {
                return (string) $value;
            }
        }

        if (isset($blast['@self']) === true && is_array($blast['@self']) === true) {
            foreach (['uuid', 'id', 'slug'] as $key) {
                $value = ($blast['@self'][$key] ?? null);
                if (is_scalar($value) === true && (string) $value !== '') {
                    return (string) $value;
                }
            }
        }

        return '';
    }//end extractBlastId()

    /**
     * Lazy-resolve OpenRegister's ObjectService.
     *
     * @return object|null ObjectService or null.
     */
    private function getObjectService(): ?object
    {
        try {
            return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (Throwable $e) {
            $this->logger->warning(
                'BlastSendJob.getObjectService: OpenRegister unavailable',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getObjectService()

    /**
     * Normalise an OpenRegister entity into a plain array.
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
}//end class
