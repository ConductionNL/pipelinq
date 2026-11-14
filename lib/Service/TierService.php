<?php

/**
 * Pipelinq TierService.
 *
 * Owns automatic tier classification for a CustomerLoyaltyAccount: walks TierRule
 * thresholds (lifetimePoints / rollingPoints12m / jaarlijkseSpend), picks the
 * matching tier, applies upgrade/downgrade policies (immediate vs end_of_year),
 * and emits a tier-changed CloudEvent payload via NotificationService.
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
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\TierServiceInterface;
use OCA\Pipelinq\Util\PayloadToArray;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tier classification and benefits service.
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-003
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) tier evaluation walks rules, upgrade/downgrade policy, and event emission in one cohesive service
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   orchestrates DI container, config, loyalty accounts, events and logging by design
 */
class TierService implements TierServiceInterface {
	use PayloadToArray;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 * @param LoyaltyAccountService $accountService The loyalty account service.
	 * @param IEventDispatcher $eventDispatcher The event dispatcher.
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-084).
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoyaltyAccountService $accountService,
		private IEventDispatcher $eventDispatcher,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Get TierRules for a programme sorted by sequence ascending (lowest first).
	 *
	 * @param string $programmeId The programme UUID.
	 *
	 * @return array<int, array<string, mixed>> The tier rules.
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-003
	 */
	public function getTierRules(string $programmeId): array {
		$ids = $this->config(schemaKey: 'tierRule_schema');
		if ($ids[0] === '' || $ids[1] === '' || $programmeId === '') {
			return [];
		}

		$rules = array_map([$this, 'toArray'], $this->tierRuleRows(ids: $ids, programmeId: $programmeId));

		return self::sortedBySequence(rules: $rules);
	}//end getTierRules()

	/**
	 * Read the programme's TierRule rows; an empty list when the read fails.
	 *
	 * @param array{0: string, 1: string} $ids         The [register, schema] ids.
	 * @param string                      $programmeId The programme UUID.
	 *
	 * @return array<int, mixed>
	 */
	private function tierRuleRows(array $ids, string $programmeId): array {
		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'programmeId' => $programmeId,
						'register' => $ids[0],
						'schema' => $ids[1],
					],
					'limit' => 100,
				]
			);
		} catch (\Throwable $e) {
			return [];
		}

		if (is_array($rows) === true) {
			return array_values($rows);
		}

		return [];
	}//end tierRuleRows()

	/**
	 * Sort tier rules by sequence, lowest first.
	 *
	 * @param array<int, array<string, mixed>> $rules The rules.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function sortedBySequence(array $rules): array {
		usort(
			$rules,
			static fn (array $a, array $b): int => (int)($a['sequence'] ?? 0) <=> (int)($b['sequence'] ?? 0)
		);

		return $rules;
	}//end sortedBySequence()

	/**
	 * Pick the tier rule matching a given lifetimePoints (or rolling metric).
	 *
	 * Walks the sorted rules and returns the HIGHEST whose threshold the metric
	 * has crossed. Returns null when no threshold matches.
	 *
	 * @param string $programmeId The programme UUID.
	 * @param int $lifetimePoints The metric value.
	 *
	 * @return array<string, mixed>|null The matching tier rule.
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-003
	 */
	public function calculateTier(string $programmeId, int $lifetimePoints): ?array {
		$rules = $this->getTierRules(programmeId: $programmeId);
		$matched = null;
		foreach ($rules as $rule) {
			$threshold = (float)($rule['thresholdValue'] ?? 0);
			if ($lifetimePoints >= $threshold) {
				$matched = $rule;
			}
		}

		return $matched;
	}//end calculateTier()

	/**
	 * Re-evaluate an account's tier and update if changed.
	 *
	 * @param string $accountId The account UUID.
	 *
	 * @return array{from: ?string, to: ?string, changed: bool} Summary.
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-003
	 */
	public function updateTierIfNeeded(string $accountId): array {
		$account = $this->accountService->getAccount(accountId: $accountId);
		if ($account === null) {
			return ['from' => null, 'to' => null, 'changed' => false];
		}

		$change = new TierChange(accountId: $accountId, account: $account);
		$newTier = $this->calculateTier(programmeId: $change->programmeId(), lifetimePoints: $change->lifetimePoints());
		if ($newTier === null) {
			return ['from' => $change->currentTierId(), 'to' => null, 'changed' => false];
		}

		return $this->moveToTier(change: $change, newTier: $newTier);
	}//end updateTierIfNeeded()

	/**
	 * Move the account to the matched tier: nothing, an upgrade or a downgrade.
	 *
	 * @param TierChange           $change  The re-evaluation.
	 * @param array<string, mixed> $newTier The matched tier rule.
	 *
	 * @return array{from: ?string, to: ?string, changed: bool} Summary.
	 */
	private function moveToTier(TierChange $change, array $newTier): array {
		$currentTierId = $change->currentTierId();
		$newTierUuid = $this->extractUuid(object: $newTier);
		if ($newTierUuid === $currentTierId) {
			return ['from' => $currentTierId, 'to' => $currentTierId, 'changed' => false];
		}

		// Decide upgrade vs downgrade.
		$isUpgrade = $this->isUpgrade(change: $change, newTierId: $newTierUuid);
		if ($isUpgrade === true) {
			$this->handleTierUpgrade(accountId: $change->accountId, newTier: $newTier);
			return $this->announceTierChange(change: $change, newTier: $newTier);
		}

		return $this->moveDown(change: $change, newTier: $newTier);
	}//end moveToTier()

	/**
	 * Downgrade path: respect the current tier's end_of_period policy.
	 *
	 * @param TierChange           $change  The re-evaluation.
	 * @param array<string, mixed> $newTier The matched (lower) tier rule.
	 *
	 * @return array{from: ?string, to: ?string, changed: bool} Summary.
	 */
	private function moveDown(TierChange $change, array $newTier): array {
		$downgradePolicy = $this->downgradePolicyOf(change: $change);
		if ($downgradePolicy === 'end_of_year' || $downgradePolicy === 'end_of_quarter') {
			return $this->scheduleDowngrade(change: $change, policy: $downgradePolicy);
		}

		// Immediate downgrade.
		$this->handleTierDowngrade(accountId: $change->accountId, newTier: $newTier);
		return $this->announceTierChange(change: $change, newTier: $newTier);
	}//end moveDown()

	/**
	 * The downgrade policy of the account's current tier ('none' when unknown).
	 *
	 * @param TierChange $change The re-evaluation.
	 *
	 * @return string
	 */
	private function downgradePolicyOf(TierChange $change): string {
		$currentTier = $this->findRuleByUuid(programmeId: $change->programmeId(), tierId: (string)$change->currentTierId());
		if ($currentTier === null) {
			return 'none';
		}

		return (string)($currentTier['downgradePolicy'] ?? 'none');
	}//end downgradePolicyOf()

	/**
	 * Schedule the downgrade via tierGeldigTot; do NOT change currentTierId now.
	 *
	 * @param TierChange $change The re-evaluation.
	 * @param string     $policy "end_of_year" or "end_of_quarter".
	 *
	 * @return array{from: ?string, to: ?string, changed: bool} Summary.
	 */
	private function scheduleDowngrade(TierChange $change, string $policy): array {
		$currentTierId = $change->currentTierId();
		$end = $this->endOfPeriodTimestamp(policy: $policy);
		$this->accountService->setTier(
			accountId: $change->accountId,
			tierId: $currentTierId,
			tierAchievedOn: null,
			tierValidTo: $end
		);
		return ['from' => $currentTierId, 'to' => $currentTierId, 'changed' => false];
	}//end scheduleDowngrade()

	/**
	 * Emit the tier-changed event for an applied change and summarise it.
	 *
	 * @param TierChange           $change  The re-evaluation.
	 * @param array<string, mixed> $newTier The tier the account moved to.
	 *
	 * @return array{from: ?string, to: ?string, changed: bool} Summary.
	 */
	private function announceTierChange(TierChange $change, array $newTier): array {
		$currentTierId = $change->currentTierId();
		$this->emitTierChangedEvent(
			accountId: $change->accountId,
			fromTierId: $currentTierId,
			toTier: $newTier
		);
		return ['from' => $currentTierId, 'to' => $this->extractUuid(object: $newTier), 'changed' => true];
	}//end announceTierChange()

	/**
	 * Apply an immediate upgrade.
	 *
	 * @param string $accountId The account UUID.
	 * @param array<string, mixed> $newTier The new tier rule.
	 *
	 * @return void
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-003
	 */
	public function handleTierUpgrade(string $accountId, array $newTier): void {
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		$policy = (string)($newTier['downgradePolicy'] ?? 'none');
		$valid = null;
		if ($policy === 'end_of_year') {
			$valid = $this->endOfPeriodTimestamp(policy: $policy);
		}

		$this->accountService->setTier(
			accountId: $accountId,
			tierId: $this->extractUuid(object: $newTier),
			tierAchievedOn: $now,
			tierValidTo: $valid
		);
	}//end handleTierUpgrade()

	/**
	 * Apply an immediate downgrade.
	 *
	 * @param string $accountId The account UUID.
	 * @param array<string, mixed> $newTier The new (lower) tier rule.
	 *
	 * @return void
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-003
	 */
	public function handleTierDowngrade(string $accountId, array $newTier): void {
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		$this->accountService->setTier(
			accountId: $accountId,
			tierId: $this->extractUuid(object: $newTier),
			tierAchievedOn: $now,
			tierValidTo: null
		);
	}//end handleTierDowngrade()

	/**
	 * Extract the points multiplier from a tier's benefits (default 1.0).
	 *
	 * @param array<string, mixed> $tier The tier rule.
	 *
	 * @return float The multiplier.
	 * @spec exclude loyalty has no spec at all; the change that specified it was archived
	 *   and nothing inherited it
	 */
	public function applyTierBenefits(array $tier): float {
		$benefits = $tier['benefits'] ?? [];
		if (is_array($benefits) === false) {
			return 1.0;
		}

		return (float)($benefits['pointsMultiplier'] ?? 1.0);
	}//end applyTierBenefits()

	/**
	 * Emit a tier-changed CloudEvent-style notification.
	 *
	 * @param string $accountId The account UUID.
	 * @param ?string $fromTierId The previous tier.
	 * @param array<string, mixed> $toTier The new tier.
	 *
	 * @return void
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-003
	 */
	public function emitTierChangedEvent(string $accountId, ?string $fromTierId, array $toTier): void {
		try {
			$event = new GenericEvent(
				null,
				[
					'type' => 'loyalty.tier.changed',
					'accountId' => $accountId,
					'fromTierId' => $fromTierId,
					'toTierId' => $this->extractUuid(object: $toTier),
					'toTierNaam' => (string)($toTier['name'] ?? ''),
					'benefits' => $toTier['benefits'] ?? [],
				]
			);
			$this->eventDispatcher->dispatchTyped($event);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: tier-changed event dispatch failed', ['exception' => $e->getMessage()]);
		}
	}//end emitTierChangedEvent()

	/**
	 * Whether $newTierId is higher than the account's current tier (by sequence).
	 *
	 * @param TierChange $change    The re-evaluation (programme and current tier).
	 * @param ?string    $newTierId New tier UUID.
	 *
	 * @return bool True for upgrade (or first assignment).
	 */
	private function isUpgrade(TierChange $change, ?string $newTierId): bool {
		$currentTierId = $change->currentTierId();
		if ($currentTierId === null || $currentTierId === '') {
			return true;
		}

		if ($newTierId === null || $newTierId === '') {
			return false;
		}

		return $this->ranksAboveCurrent(change: $change, newTierId: $newTierId);
	}//end isUpgrade()

	/**
	 * Whether the new tier's sequence is above the current tier's; false when either is unknown.
	 *
	 * @param TierChange $change    The re-evaluation (programme and current tier).
	 * @param string     $newTierId New tier UUID.
	 *
	 * @return bool
	 */
	private function ranksAboveCurrent(TierChange $change, string $newTierId): bool {
		$current = $this->findRuleByUuid(programmeId: $change->programmeId(), tierId: (string)$change->currentTierId());
		$new = $this->findRuleByUuid(programmeId: $change->programmeId(), tierId: $newTierId);
		if ($current === null || $new === null) {
			return false;
		}

		return (int)($new['sequence'] ?? 0) > (int)($current['sequence'] ?? 0);
	}//end ranksAboveCurrent()

	/**
	 * Find a tier rule by UUID in a programme.
	 *
	 * @param string $programmeId The programme UUID.
	 * @param string $tierId The tier UUID.
	 *
	 * @return array<string, mixed>|null The rule.
	 */
	private function findRuleByUuid(string $programmeId, string $tierId): ?array {
		if ($tierId === '') {
			return null;
		}

		foreach ($this->getTierRules(programmeId: $programmeId) as $rule) {
			if ($this->extractUuid(object: $rule) === $tierId) {
				return $rule;
			}
		}

		return null;
	}//end findRuleByUuid()

	/**
	 * Compute an end-of-period timestamp from a policy.
	 *
	 * @param string $policy "end_of_year" or "end_of_quarter".
	 *
	 * @return string ISO-8601 timestamp.
	 */
	private function endOfPeriodTimestamp(string $policy): string {
		$tz = new DateTimeZone('UTC');
		$now = new DateTimeImmutable('now', $tz);
		$year = (int)$now->format('Y');

		if ($policy === 'end_of_quarter') {
			$month = (int)$now->format('n');
			$endMonth = ((int)ceil($month / 3)) * 3;
			return (new DateTimeImmutable(sprintf('%d-%02d-01 00:00:00', $year, $endMonth), $tz))
				->modify('last day of this month 23:59:59')->format('c');
		}

		// End_of_year (default).
		return (new DateTimeImmutable(sprintf('%d-12-31 23:59:59', $year), $tz))->format('c');
	}//end endOfPeriodTimestamp()

	/**
	 * Extract UUID from an OR entity array (favours @self.id over uuid/tierId).
	 *
	 * @param array<string, mixed> $object The OR object.
	 *
	 * @return ?string The UUID, or null.
	 */
	private function extractUuid(array $object): ?string {
		$self = $object['@self'] ?? [];
		if (is_array($self) === true && isset($self['id']) === true) {
			return (string)$self['id'];
		}

		return $object['tierId'] ?? $object['uuid'] ?? $object['id'] ?? null;
	}//end extractUuid()

	/**
	 * Resolve register + a schema id by appconfig key.
	 *
	 * Fails closed: '' on either id means "unconfigured", and every caller
	 * refuses the OpenRegister call on it. An empty id must never be handed to
	 * OpenRegister — ObjectService skips setRegister()/setSchema() for an empty
	 * value, so the query silently inherits whatever context an earlier call in
	 * the same request left on the shared service instance. The empty case is
	 * logged so an unprovisioned instance is visible rather than silent.
	 *
	 * @param string $schemaKey The schema config key (e.g. 'tierRule_schema').
	 *
	 * @return array{0: string, 1: string} The [register, schema] ids, each ''
	 *                                     when unconfigured.
	 */
	private function config(string $schemaKey): array {
		$registerId = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(AppIdentity::APP_ID, $schemaKey, '');
		if ($registerId === '' || $schemaId === '') {
			$this->logger->warning(
				'Pipelinq: register/schema not configured; OpenRegister calls are refused, not run unscoped',
				['schemaKey' => $schemaKey]
			);
		}

		return [$registerId, $schemaId];
	}//end config()

	/**
	 * Get the OpenRegister ObjectService.
	 *
	 * @return object The object service.
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()
}//end class
