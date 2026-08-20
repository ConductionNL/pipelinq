<?php

/**
 * Pipelinq TemplateApprovalSyncService.
 *
 * Polls Meta / BSP for template approval state and reconciles the
 * local messageTemplate rows. Fires an admin notification on
 * approval, rejection, or disablement so agents see the change in
 * the send-picker promptly.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#2.8
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * TemplateApprovalSyncService — Meta / BSP template-state sync.
 *
 * Public entry points:
 * - syncAll() — iterate every WhatsApp provider, fetch remote
 *   templates, upsert local rows, fire admin notifications on
 *   state transitions.
 * - syncOne(channelProvider) — sync a single provider; returns a
 *   per-provider summary used by the background job.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Bridges provider
 * repository + provider client + notifications + OR.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#2.8
 */
class TemplateApprovalSyncService {
	/**
	 * Default register slug.
	 */
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	/**
	 * Default messageTemplate schema slug.
	 */
	private const DEFAULT_SCHEMA_SLUG = 'messageTemplate';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container.
	 * @param IAppConfig $appConfig App config.
	 * @param ChannelProviderRepository $providerRepo Provider read-side.
	 * @param WhatsAppProviderClient $providerClient Vendor transport.
	 * @param NotificationService $notificationService Admin notifications.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#2.8
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ChannelProviderRepository $providerRepo,
		private WhatsAppProviderClient $providerClient,
		private NotificationService $notificationService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Sync every WhatsApp provider's templates.
	 *
	 * @return array{providers: int, templatesUpdated: int, statusChanges: int} Summary.
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#2.8
	 */
	public function syncAll(): array {
		$providers = array_merge(
			$this->providerRepo->listActive(kind: 'whatsapp-cloud-api'),
			$this->providerRepo->listActive(kind: 'whatsapp-bsp'),
		);

		$totalUpdates = 0;
		$totalChanges = 0;
		foreach ($providers as $provider) {
			$summary = $this->syncOne(channelProvider: $provider);
			$totalUpdates += (int)$summary['templatesUpdated'];
			$totalChanges += (int)$summary['statusChanges'];
		}

		return [
			'providers' => count($providers),
			'templatesUpdated' => $totalUpdates,
			'statusChanges' => $totalChanges,
		];
	}//end syncAll()

	/**
	 * Sync one provider's templates.
	 *
	 * @param array<string, mixed> $channelProvider Provider row.
	 *
	 * @return array{templatesUpdated: int, statusChanges: int} Summary.
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#2.8
	 */
	public function syncOne(array $channelProvider): array {
		$providerId = $this->extractId(payload: $channelProvider);
		if ($providerId === '') {
			return ['templatesUpdated' => 0, 'statusChanges' => 0];
		}

		try {
			$remoteTemplates = $this->providerClient->listTemplates(channelProvider: $channelProvider);
		} catch (Throwable $e) {
			$this->logger->warning(
				'TemplateApprovalSyncService.syncOne: listTemplates failed',
				['providerId' => $providerId, 'exception' => $e->getMessage()]
			);
			return ['templatesUpdated' => 0, 'statusChanges' => 0];
		}

		$localIndex = $this->buildLocalIndex(providerId: $providerId);
		$updates = 0;
		$changes = 0;

		foreach ($remoteTemplates as $remote) {
			$externalId = (string)($remote['name'] ?? ($remote['externalId'] ?? ''));
			$language = (string)($remote['language'] ?? 'nl');
			if ($externalId === '') {
				continue;
			}

			$key = $externalId . ':' . $language;
			$local = ($localIndex[$key] ?? null);
			$newStatus = $this->normaliseStatus(remoteStatus: (string)($remote['status'] ?? 'pending'));

			$payload = $local ?? [
				'providerId' => $providerId,
				'externalId' => $externalId,
				'language' => $language,
				'category' => (string)($remote['category'] ?? 'utility'),
				'body' => (string)($remote['body'] ?? ''),
				'header' => (string)($remote['header'] ?? ''),
				'buttons' => $remote['buttons'] ?? [],
				'status' => 'pending',
			];

			$oldStatus = (string)($payload['status'] ?? 'pending');
			$payload['category'] = (string)($remote['category'] ?? ($payload['category'] ?? 'utility'));
			$payload['body'] = (string)($remote['body'] ?? ($payload['body'] ?? ''));
			$payload['header'] = (string)($remote['header'] ?? ($payload['header'] ?? ''));
			$payload['buttons'] = $remote['buttons'] ?? ($payload['buttons'] ?? []);
			$payload['status'] = $newStatus;
			$payload['lastSyncedAt'] = $this->nowIso();

			$id = null;
			if ($local !== null) {
				$id = $this->extractId(payload: $local);
			}

			$this->saveObject(payload: $payload, id: $id);
			$updates++;

			if ($oldStatus !== $newStatus) {
				$changes++;
				$this->notifyStatusChange(
					providerId: $providerId,
					externalId: $externalId,
					oldStatus: $oldStatus,
					newStatus: $newStatus,
				);
			}
		}//end foreach

		return ['templatesUpdated' => $updates, 'statusChanges' => $changes];
	}//end syncOne()

	/**
	 * Map remote provider status strings to our local enum.
	 *
	 * Meta: APPROVED / IN_APPEAL / PENDING / REJECTED / FLAGGED / PAUSED / DISABLED.
	 *
	 * @param string $remoteStatus Provider-reported status.
	 *
	 * @return string Local enum value.
	 */
	private function normaliseStatus(string $remoteStatus): string {
		return match (strtolower($remoteStatus)) {
			'approved' => 'approved',
			'rejected', 'flagged' => 'rejected',
			'disabled', 'paused' => 'disabled',
			default => 'pending',
		};
	}//end normaliseStatus()

	/**
	 * Build a (externalId+language) → row index for one provider.
	 *
	 * @param string $providerId Provider UUID.
	 *
	 * @return array<string, array<string, mixed>> Index.
	 */
	private function buildLocalIndex(string $providerId): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'providerId' => $providerId,
						'register' => $this->getRegisterSlug(),
						'schema' => $this->getSchemaSlug(),
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'TemplateApprovalSyncService.buildLocalIndex: findAll failed',
				['providerId' => $providerId, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$index = [];
		foreach (($rows ?? []) as $row) {
			$arr = $this->toArray(value: $row);
			$externalId = (string)($arr['externalId'] ?? '');
			$language = (string)($arr['language'] ?? 'nl');
			if ($externalId === '') {
				continue;
			}

			$index[$externalId . ':' . $language] = $arr;
		}

		return $index;
	}//end buildLocalIndex()

	/**
	 * Fire an admin notification on a status transition.
	 *
	 * @param string $providerId Provider UUID.
	 * @param string $externalId Template external id.
	 * @param string $oldStatus Previous status.
	 * @param string $newStatus New status.
	 *
	 * @return void
	 */
	private function notifyStatusChange(
		string $providerId,
		string $externalId,
		string $oldStatus,
		string $newStatus,
	): void {
		try {
			$this->notificationService->sendNotification(
				userId: 'admin',
				subject: 'messaging_template_status_changed',
				parameters: [
					'providerId' => $providerId,
					'externalId' => $externalId,
					'oldStatus' => $oldStatus,
					'newStatus' => $newStatus,
				],
				objectType: 'messageTemplate',
				objectId: $externalId,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'TemplateApprovalSyncService.notifyStatusChange: notification failed',
				['exception' => $e->getMessage()]
			);
		}
	}//end notifyStatusChange()

	/**
	 * Persist a template row.
	 *
	 * Return value is kept for future callers that need the saved row; the
	 * current call site at syncTemplate() intentionally discards it.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @param string|null $id Existing id or null.
	 *
	 * @return array<string, mixed>|null Saved row.
	 *
	 * @psalm-suppress UnusedReturnValue — call site at syncTemplate() discards
	 *   the saved row; the return type is preserved for future use.
	 */
	private function saveObject(array $payload, ?string $id): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$saved = $objectService->saveObject(
				object: $payload,
				register: $this->getRegisterSlug(),
				schema: $this->getSchemaSlug(),
				uuid: $id,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'TemplateApprovalSyncService.saveObject: save failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(value: $saved);
	}//end saveObject()

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
				'TemplateApprovalSyncService.getObjectService: OpenRegister unavailable',
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
		$searchSpaces = [$payload];
		$self = ($payload['@self'] ?? null);
		if (is_array($self) === true) {
			$searchSpaces[] = $self;
		}

		foreach ($searchSpaces as $space) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				$value = ($space[$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
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
		$slug = $this->appConfig->getValueString(
			Application::APP_ID,
			'messageTemplate_schema',
			''
		);
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_SCHEMA_SLUG;
	}//end getSchemaSlug()

	/**
	 * Current ISO 8601 UTC timestamp.
	 *
	 * @return string Timestamp.
	 */
	private function nowIso(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}//end nowIso()
}//end class
