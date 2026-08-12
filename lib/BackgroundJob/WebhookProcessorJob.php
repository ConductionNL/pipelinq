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
class WebhookProcessorJob extends TimedJob {
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
	 * @param ITimeFactory $time Time factory.
	 * @param ContainerInterface $container DI container.
	 * @param IAppConfig $appConfig App config.
	 * @param WhatsAppAdapter $whatsAppAdapter WhatsApp adapter.
	 * @param SmsAdapter $smsAdapter SMS adapter.
	 * @param LoggerInterface $logger Logger.
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
	protected function run($argument): void {
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
	private function drain(): void {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return;
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'status' => 'queued',
						'register' => $this->getRegisterSlug(),
						'schema' => $this->getSchemaSlug(),
					],
				]
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
			$this->processRow(objectService: $objectService, raw: $raw);
		}//end foreach
	}//end drain()

	/**
	 * Process one queued webhook row: dispatch to the channel adapter and
	 * persist the outcome.
	 *
	 * @param object $objectService OR ObjectService.
	 * @param mixed $raw Raw queued row (entity or array).
	 *
	 * @return void
	 */
	private function processRow(object $objectService, mixed $raw): void {
		$arr = $this->toArray(value: $raw);
		$channel = (string)($arr['channel'] ?? '');
		$providerId = (string)($arr['providerId'] ?? '');
		$signature = (string)($arr['signature'] ?? '');
		$body = (string)($arr['rawBody'] ?? '');
		$id = $this->extractId(payload: $arr);

		try {
			$result = $this->dispatchChannel(
				channel: $channel,
				body: $body,
				signature: $signature,
				providerId: $providerId
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'WebhookProcessorJob.drain: processing failed',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			$result = ['status' => 'processingFailed', 'error' => $e->getMessage()];
		}//end try

		$arr['status'] = (string)$result['status'];
		$arr['processedAt'] = gmdate('Y-m-d\TH:i:s\Z');
		$arr['result'] = $result;

		$saveUuid = null;
		if ($id !== '') {
			$saveUuid = $id;
		}

		try {
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
	}//end processRow()

	/**
	 * Dispatch a webhook to the adapter matching its channel.
	 *
	 * @param string $channel Channel identifier.
	 * @param string $body Raw request body.
	 * @param string $signature Provider signature header.
	 * @param string $providerId Provider message identifier.
	 *
	 * @return array<string, mixed> Adapter result (unknownChannel when unmatched).
	 */
	private function dispatchChannel(string $channel, string $body, string $signature, string $providerId): array {
		if ($channel === 'whatsapp') {
			return $this->whatsAppAdapter->handleInboundWebhook(
				rawBody: $body,
				signature: $signature,
				providerId: $providerId,
			);
		}

		if ($channel === 'sms') {
			return $this->smsAdapter->handleInboundWebhook(
				rawBody: $body,
				signature: $signature,
				providerId: $providerId,
			);
		}

		return ['status' => 'unknownChannel'];
	}//end dispatchChannel()

	/**
	 * Resolve OpenRegister ObjectService.
	 *
	 * @return object|null Service or null.
	 */
	private function getObjectService(): ?object {
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
	private function toArray(mixed $value): array {
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
	private function extractId(array $payload): string {
		$keys = ['uuid', 'id', 'slug'];
		$direct = $this->firstScalar(source: $payload, keys: $keys);
		if ($direct !== '') {
			return $direct;
		}

		if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
			return $this->firstScalar(source: $payload['@self'], keys: $keys);
		}

		return '';
	}//end extractId()

	/**
	 * Return the first non-empty scalar value among the given keys.
	 *
	 * @param array<string, mixed> $source Source array.
	 * @param array<int, string> $keys Candidate keys, in priority order.
	 *
	 * @return string First matching value cast to string, or empty.
	 */
	private function firstScalar(array $source, array $keys): string {
		foreach ($keys as $key) {
			$value = ($source[$key] ?? null);
			if (is_scalar($value) === true && (string)$value !== '') {
				return (string)$value;
			}
		}

		return '';
	}//end firstScalar()

	/**
	 * Register slug.
	 *
	 * @return string Slug.
	 */
	private function getRegisterSlug(): string {
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
	private function getSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'webhookQueue_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_SCHEMA_SLUG;
	}//end getSchemaSlug()
}//end class
