<?php

/**
 * Pipelinq BudgetService.
 *
 * Per-tenant, per-provider send-budget enforcement for messaging
 * channels (WhatsApp / SMS). canSend() is the gate placed in front of
 * every outbound provider call; recordSend() advances the running
 * counters; the daily BudgetPeriodResetJob rolls the period
 * boundary.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#5.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * BudgetService — hard-stop / alert-only budget enforcement.
 *
 * Public entry points:
 * - canSend(tenantId, providerId, estimatedCostEur) — boolean
 *   gate; returns false only when a hardStop budget would be
 *   breached by this send.
 * - recordSend(tenantId, providerId, costEur) — advances counters
 *   post-provider success and fires the one-per-period soft-alert
 *   when crossing alertThresholdPct.
 * - resetPeriods() — invoked by BudgetPeriodResetJob; rolls every
 *   budget row whose periodResetAt has passed.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#5.1
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Budget gating spans many period/threshold branches; complexity is inherent.
 */
class BudgetService {
	/**
	 * Default messageSendBudget schema slug.
	 */
	private const DEFAULT_SCHEMA_SLUG = 'messageSendBudget';

	/**
	 * Default pipelinq register slug.
	 */
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container.
	 * @param IAppConfig $appConfig App config.
	 * @param NotificationService $notificationService Admin notifications.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#5.1
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private NotificationService $notificationService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a send may proceed under the budget for this provider.
	 *
	 * @param string $tenantId Tenant id.
	 * @param string $providerId Provider UUID.
	 * @param float $estimatedCostEur Estimated cost in EUR (may be 0).
	 *
	 * @return bool True when allowed (no budget, soft-limit, or
	 *              hard-limit not yet reached).
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#5.2
	 */
	public function canSend(string $tenantId, string $providerId, float $estimatedCostEur = 0.0): bool {
		$budget = $this->loadBudget(tenantId: $tenantId, providerId: $providerId);
		if ($budget === null) {
			return true;
		}

		$hardStop = (bool)($budget['hardStop'] ?? false);
		if ($hardStop === false) {
			return true;
		}

		$maxMessages = (int)($budget['maxMessages'] ?? 0);
		$currentMsgs = (int)($budget['currentPeriodMessages'] ?? 0);
		$maxCostEur = (float)($budget['maxCostEur'] ?? 0);
		$currentEur = (float)($budget['currentPeriodCostEur'] ?? 0);

		if ($maxMessages > 0 && ($currentMsgs + 1) > $maxMessages) {
			return false;
		}

		if ($maxCostEur > 0 && ($currentEur + $estimatedCostEur) > $maxCostEur) {
			return false;
		}

		return true;
	}//end canSend()

	/**
	 * Record a successful send against the budget.
	 *
	 * Advances `currentPeriodMessages` and `currentPeriodCostEur`,
	 * fires the one-per-period soft-alert when crossing
	 * `alertThresholdPct`, and persists the updated row.
	 *
	 * @param string $tenantId Tenant id.
	 * @param string $providerId Provider UUID.
	 * @param float $costEur Realised cost in EUR (may be 0).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#5.3
	 */
	public function recordSend(string $tenantId, string $providerId, float $costEur = 0.0): void {
		$budget = $this->loadBudget(tenantId: $tenantId, providerId: $providerId);
		if ($budget === null) {
			return;
		}

		$newMessages = ((int)($budget['currentPeriodMessages'] ?? 0)) + 1;
		$newCost = ((float)($budget['currentPeriodCostEur'] ?? 0)) + $costEur;
		$maxMessages = (int)($budget['maxMessages'] ?? 0);
		$maxCost = (float)($budget['maxCostEur'] ?? 0);
		$thresholdPct = (float)($budget['alertThresholdPct'] ?? 0.8);
		$alertedAt = (string)($budget['alertedAtPeriodStart'] ?? '');

		$payload = $budget;
		$payload['currentPeriodMessages'] = $newMessages;
		$payload['currentPeriodCostEur'] = $newCost;

		// Soft-alert: fire exactly once per period when crossing the
		// threshold (either messages or cost).
		$shouldAlert = false;
		if ($alertedAt === '') {
			if ($maxMessages > 0 && $newMessages >= (int)ceil($maxMessages * $thresholdPct)) {
				$shouldAlert = true;
			}

			if ($maxCost > 0 && $newCost >= ($maxCost * $thresholdPct)) {
				$shouldAlert = true;
			}
		}

		if ($shouldAlert === true) {
			$payload['alertedAtPeriodStart'] = $this->nowIso();
			$this->fireAlert(tenantId: $tenantId, providerId: $providerId, budget: $payload);
		}

		$this->saveObject(payload: $payload, id: $this->extractId(payload: $budget));
	}//end recordSend()

	/**
	 * Roll every budget row whose period has passed.
	 *
	 * Resets `currentPeriodMessages` / `currentPeriodCostEur` to 0,
	 * clears `alertedAtPeriodStart` and advances `periodResetAt` by
	 * the period.
	 *
	 * @return int Number of rows reset.
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#5.4
	 */
	public function resetPeriods(): int {
		$rows = $this->loadAllBudgets();
		if ($rows === []) {
			return 0;
		}

		$reset = 0;
		$now = gmdate('Y-m-d\TH:i:s\Z');
		foreach ($rows as $row) {
			$resetAt = (string)($row['periodResetAt'] ?? '');
			if ($resetAt === '' || strcmp($resetAt, $now) > 0) {
				continue;
			}

			$payload = $row;
			$payload['currentPeriodMessages'] = 0;
			$payload['currentPeriodCostEur'] = 0;
			$payload['alertedAtPeriodStart'] = '';
			$payload['periodResetAt'] = $this->advancePeriod(
				from: $resetAt,
				period: (string)($row['period'] ?? 'monthly'),
			);

			$this->saveObject(payload: $payload, id: $this->extractId(payload: $row));
			$reset++;
		}

		return $reset;
	}//end resetPeriods()

	/**
	 * Load the active budget row for a tenant + provider pair.
	 *
	 * @param string $tenantId Tenant id.
	 * @param string $providerId Provider UUID.
	 *
	 * @return array<string, mixed>|null Budget row or null.
	 */
	private function loadBudget(string $tenantId, string $providerId): ?array {
		if ($tenantId === '' || $providerId === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'tenantId' => $tenantId,
						'providerId' => $providerId,
						'register' => $this->getRegisterSlug(),
						'schema' => $this->getSchemaSlug(),
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BudgetService.loadBudget: findAll failed',
				['tenantId' => $tenantId, 'providerId' => $providerId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($rows === null || $rows === []) {
			return null;
		}

		$first = $rows[0];
		return $this->toArray(value: $first);
	}//end loadBudget()

	/**
	 * Load every budget row.
	 *
	 * @return array<int, array<string, mixed>> All budgets.
	 */
	private function loadAllBudgets(): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'register' => $this->getRegisterSlug(),
						'schema' => $this->getSchemaSlug(),
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BudgetService.loadAllBudgets: findAll failed',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach (($rows ?? []) as $row) {
			$out[] = $this->toArray(value: $row);
		}

		return $out;
	}//end loadAllBudgets()

	/**
	 * Persist a budget payload.
	 *
	 * Return value is available for callers that need the saved row (e.g. to
	 * read back the auto-assigned id); the two current call sites discard it.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @param string $id Existing id or empty.
	 *
	 * @return array<string, mixed>|null Saved row.
	 *
	 * @psalm-suppress UnusedReturnValue — callers at saveBudget / applyPeriodReset
	 *   intentionally discard the saved row; the return type is kept for future callers.
	 */
	private function saveObject(array $payload, string $id = ''): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$saveUuid = null;
			if ($id !== '') {
				$saveUuid = $id;
			}

			$saved = $objectService->saveObject(
				object: $payload,
				register: $this->getRegisterSlug(),
				schema: $this->getSchemaSlug(),
				uuid: $saveUuid,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BudgetService.saveObject: save failed',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(value: $saved);
	}//end saveObject()

	/**
	 * Fire the soft-alert (one per period) to admins.
	 *
	 * @param string $tenantId Tenant id.
	 * @param string $providerId Provider UUID.
	 * @param array<string, mixed> $budget Budget snapshot.
	 *
	 * @return void
	 */
	private function fireAlert(string $tenantId, string $providerId, array $budget): void {
		try {
			$this->notificationService->sendNotification(
				userId: 'admin',
				subject: 'messaging_budget_threshold',
				parameters: [
					'tenantId' => $tenantId,
					'providerId' => $providerId,
					'period' => (string)($budget['period'] ?? 'monthly'),
					'messages' => (int)($budget['currentPeriodMessages'] ?? 0),
					'costEur' => (float)($budget['currentPeriodCostEur'] ?? 0),
				],
				objectType: 'messageSendBudget',
				objectId: $this->extractId(payload: $budget),
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BudgetService.fireAlert: notification failed',
				['tenantId' => $tenantId, 'exception' => $e->getMessage()]
			);
		}
	}//end fireAlert()

	/**
	 * Advance an ISO 8601 timestamp by a period.
	 *
	 * @param string $from Current periodResetAt.
	 * @param string $period `daily` / `weekly` / `monthly`.
	 *
	 * @return string Next periodResetAt.
	 */
	private function advancePeriod(string $from, string $period): string {
		$modifier = match ($period) {
			'daily' => '+1 day',
			'weekly' => '+1 week',
			default => '+1 month',
		};

		try {
			$dateTime = new DateTimeImmutable($from, new DateTimeZone('UTC'));
			return $dateTime->modify($modifier)->format('Y-m-d\TH:i:s\Z');
		} catch (Throwable $e) {
			$this->logger->warning(
				'BudgetService.advancePeriod: parse failed',
				['from' => $from, 'period' => $period, 'exception' => $e->getMessage()]
			);
			return gmdate('Y-m-d\TH:i:s\Z', (time() + (30 * 86400)));
		}
	}//end advancePeriod()

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
				'BudgetService.getObjectService: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getObjectService()

	/**
	 * Normalise an OR entity to a plain array.
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
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential id-candidate probes across two payload shapes; extraction adds no clarity.
	 */
	private function extractId(array $payload): string {
		foreach (['uuid', 'id', 'slug'] as $key) {
			if (isset($payload[$key]) === true && is_scalar($payload[$key]) === true && (string)$payload[$key] !== '') {
				return (string)$payload[$key];
			}
		}

		if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				$value = ($payload['@self'][$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}//end extractId()

	/**
	 * Register slug (app-config overridable).
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
	 * Schema slug (app-config overridable).
	 *
	 * @return string Slug.
	 */
	private function getSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(
			Application::APP_ID,
			'messageSendBudget_schema',
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
