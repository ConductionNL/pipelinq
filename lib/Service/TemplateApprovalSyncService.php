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
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\TemplateApprovalSyncServiceInterface;
use OCA\Pipelinq\Util\PayloadToArray;
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
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class TemplateApprovalSyncService implements TemplateApprovalSyncServiceInterface {
	use PayloadToArray;

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
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
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
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
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
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 */
	public function syncOne(array $channelProvider): array {
		$providerId = $this->extractId(payload: $channelProvider);
		if ($providerId === '') {
			return ['templatesUpdated' => 0, 'statusChanges' => 0];
		}

		$remoteTemplates = $this->fetchRemoteTemplates(channelProvider: $channelProvider, providerId: $providerId);
		if ($remoteTemplates === null) {
			return ['templatesUpdated' => 0, 'statusChanges' => 0];
		}

		return $this->syncRemoteTemplates(remoteTemplates: $remoteTemplates, providerId: $providerId);
	}//end syncOne()

	/**
	 * List a provider's remote templates, or null when the call fails.
	 *
	 * @param array<string, mixed> $channelProvider Provider row.
	 * @param string $providerId The provider id.
	 *
	 * @return array<int, array<string, mixed>>|null The remote templates.
	 */
	private function fetchRemoteTemplates(array $channelProvider, string $providerId): array|null {
		try {
			return $this->providerClient->listTemplates(channelProvider: $channelProvider);
		} catch (Throwable $e) {
			$this->logger->warning(
				'TemplateApprovalSyncService.syncOne: listTemplates failed',
				['providerId' => $providerId, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end fetchRemoteTemplates()

	/**
	 * Upsert every remote template against the local index and count the results.
	 *
	 * @param array<int, array<string, mixed>> $remoteTemplates The remote templates.
	 * @param string $providerId The provider id.
	 *
	 * @return array{templatesUpdated: int, statusChanges: int} Summary.
	 */
	private function syncRemoteTemplates(array $remoteTemplates, string $providerId): array {
		$localIndex = $this->buildLocalIndex(providerId: $providerId);
		$updates = 0;
		$changes = 0;

		foreach ($remoteTemplates as $remote) {
			$changed = $this->syncRemoteTemplate(remote: $remote, providerId: $providerId, localIndex: $localIndex);
			if ($changed === null) {
				continue;
			}

			$updates++;
			$changes += (int)$changed;
		}

		return ['templatesUpdated' => $updates, 'statusChanges' => $changes];
	}//end syncRemoteTemplates()

	/**
	 * Upsert one remote template and notify when its status changed.
	 *
	 * @param array<string, mixed> $remote The remote template.
	 * @param string $providerId The provider id.
	 * @param array<string, array<string, mixed>> $localIndex Local templates keyed by `externalId:language`.
	 *
	 * @return bool|null Whether the status changed, or null when the template has no id.
	 */
	private function syncRemoteTemplate(array $remote, string $providerId, array $localIndex): bool|null {
		$externalId = (string)($remote['name'] ?? ($remote['externalId'] ?? ''));
		$language = (string)($remote['language'] ?? 'nl');
		if ($externalId === '') {
			return null;
		}

		$local = ($localIndex[$externalId . ':' . $language] ?? null);
		$payload = $local ?? $this->newTemplatePayload(remote: $remote, providerId: $providerId, externalId: $externalId, language: $language);
		$oldStatus = (string)($payload['status'] ?? 'pending');
		$payload = $this->applyRemoteTemplate(payload: $payload, remote: $remote);
		$this->saveTemplate(payload: $payload, local: $local);

		return $this->notifyIfStatusChanged(providerId: $providerId, externalId: $externalId, oldStatus: $oldStatus, newStatus: $payload['status']);
	}//end syncRemoteTemplate()

	/**
	 * Notify about a status change when the status actually changed.
	 *
	 * @param string $providerId The provider id.
	 * @param string $externalId The template's external id.
	 * @param string $oldStatus The status before the sync.
	 * @param string $newStatus The status after the sync.
	 *
	 * @return bool Whether the status changed.
	 */
	private function notifyIfStatusChanged(string $providerId, string $externalId, string $oldStatus, string $newStatus): bool {
		if ($oldStatus === $newStatus) {
			return false;
		}

		$this->notifyStatusChange(providerId: $providerId, externalId: $externalId, oldStatus: $oldStatus, newStatus: $newStatus);
		return true;
	}//end notifyIfStatusChanged()

	/**
	 * A fresh, pending local template for a remote template seen for the first time.
	 *
	 * @param array<string, mixed> $remote The remote template.
	 * @param string $providerId The provider id.
	 * @param string $externalId The template's external id.
	 * @param string $language The template language.
	 *
	 * @return array<string, mixed> The new template payload.
	 */
	private function newTemplatePayload(array $remote, string $providerId, string $externalId, string $language): array {
		return [
			'providerId' => $providerId,
			'externalId' => $externalId,
			'language' => $language,
			'category' => (string)($remote['category'] ?? 'utility'),
			'body' => (string)($remote['body'] ?? ''),
			'header' => (string)($remote['header'] ?? ''),
			'buttons' => $remote['buttons'] ?? [],
			'status' => 'pending',
		];
	}//end newTemplatePayload()

	/**
	 * Overlay the remote content and normalised status onto a template payload.
	 *
	 * @param array<string, mixed> $payload The local (or new) template payload.
	 * @param array<string, mixed> $remote The remote template.
	 *
	 * @return array<string, mixed> The updated payload.
	 */
	private function applyRemoteTemplate(array $payload, array $remote): array {
		$newStatus = $this->normaliseStatus(remoteStatus: (string)($remote['status'] ?? 'pending'));
		$payload['category'] = (string)($remote['category'] ?? ($payload['category'] ?? 'utility'));
		$payload['body'] = (string)($remote['body'] ?? ($payload['body'] ?? ''));
		$payload['header'] = (string)($remote['header'] ?? ($payload['header'] ?? ''));
		$payload['buttons'] = $remote['buttons'] ?? ($payload['buttons'] ?? []);
		$payload['status'] = $newStatus;
		$payload['lastSyncedAt'] = $this->nowIso();

		return $payload;
	}//end applyRemoteTemplate()

	/**
	 * Save a template, updating the local object when one exists.
	 *
	 * @param array<string, mixed> $payload The template payload.
	 * @param array<string, mixed>|null $local The existing local template, if any.
	 *
	 * @return void
	 */
	private function saveTemplate(array $payload, array|null $local): void {
		$id = null;
		if ($local !== null) {
			$id = $this->extractId(payload: $local);
		}

		$this->saveObject(payload: $payload, id: $id);
	}//end saveTemplate()

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
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
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
			AppIdentity::APP_ID,
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
