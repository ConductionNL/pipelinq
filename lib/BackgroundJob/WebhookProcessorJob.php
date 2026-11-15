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
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\SmsAdapterInterface;
use OCA\Pipelinq\Kernel\Contracts\WhatsAppAdapterInterface;
use OCA\Pipelinq\Util\ConfiguredRegisterSlug;
use OCA\Pipelinq\Util\ContainerObjectService;
use OCA\Pipelinq\Util\PayloadToArray;
use OCA\Pipelinq\Util\SavedPayloadId;
use OCA\Pipelinq\Util\SchemaRowQuery;
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
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class WebhookProcessorJob extends TimedJob {
	use PayloadToArray;
	use ConfiguredRegisterSlug;
	use ContainerObjectService;
	use SavedPayloadId;
	use SchemaRowQuery;

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
	 * @param WhatsAppAdapterInterface $whatsAppAdapter WhatsApp adapter.
	 * @param SmsAdapterInterface $smsAdapter SMS adapter.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 */
	public function __construct(
		ITimeFactory $time,
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private WhatsAppAdapterInterface $whatsAppAdapter,
		private SmsAdapterInterface $smsAdapter,
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

		$rows = $this->queuedRows(objectService: $objectService);
		if (is_array($rows) === false || $rows === []) {
			return;
		}

		foreach ($rows as $raw) {
			$this->processRow(objectService: $objectService, raw: $raw);
		}//end foreach
	}//end drain()

	/**
	 * The queued webhook rows, or null (logged) when they cannot be read.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 *
	 * @return mixed
	 */
	private function queuedRows(object $objectService): mixed {
		return $this->findSchemaRows(
			objectService: $objectService,
			filters: ['status' => 'queued'],
			failure: 'WebhookProcessorJob.drain: findAll failed',
			context: []
		);
	}//end queuedRows()

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
	 * Schema slug.
	 *
	 * @return string Slug.
	 */
	private function getSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'webhookQueue_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_SCHEMA_SLUG;
	}//end getSchemaSlug()
}//end class
