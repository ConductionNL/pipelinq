<?php

/**
 * Pipelinq QuotaService.
 *
 * Quota lookup, attainment calculation and advisory hierarchy validation for
 * the forecast feature.
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
 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Sales quota lookup and attainment math.
 *
 * Quotas are sales_quota objects in the pipelinq register. The hierarchy check
 * (sum of rep quotas vs team quota) is advisory only — it never blocks a save.
 *
 * @spec exclude infrastructure utility with no feature requirement of its own; it is
 *   exercised through the features that call it
 */
class QuotaService {
	/**
	 * App-config key for the at-risk quota-attainment threshold (percent).
	 *
	 * @var string
	 */
	public const AT_RISK_PERCENT_KEY = 'forecast_at_risk_percent';

	/**
	 * App-config key for the at-risk days-remaining threshold.
	 *
	 * @var string
	 */
	public const AT_RISK_DAYS_KEY = 'forecast_at_risk_days';

	/**
	 * Default at-risk attainment threshold (90%).
	 *
	 * @var int
	 */
	public const AT_RISK_PERCENT_DEFAULT = 90;

	/**
	 * Default at-risk days-remaining threshold (30 days).
	 *
	 * @var int
	 */
	public const AT_RISK_DAYS_DEFAULT = 30;

	/**
	 * Best-case weighting used in the projected-attainment formula.
	 *
	 * @var float
	 */
	public const BEST_CASE_WEIGHT = 0.5;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Fetch the quota row for an owner/period/level.
	 *
	 * @param string $ownerId The owner identifier.
	 * @param string $periodId The fiscal period identifier.
	 * @param string $level The hierarchy level.
	 *
	 * @return array<string, mixed>|null The quota data, or null when unset.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-008-01
	 */
	public function getQuota(string $ownerId, string $periodId, string $level): ?array {
		$rows = $this->findObjects(
			schemaKey: 'salesQuota_schema',
			filters: [
				'owner_id' => $ownerId,
				'period_id' => $periodId,
				'level' => $level,
			]
		);

		if ($rows === []) {
			return null;
		}

		return $rows[0];
	}//end getQuota()

	/**
	 * Resolve the quota amount for an owner/period/level.
	 *
	 * @param string $ownerId The owner identifier.
	 * @param string $periodId The fiscal period identifier.
	 * @param string $level The hierarchy level.
	 *
	 * @return float|null The quota amount, or null when unset.
	 * @spec exclude infrastructure utility with no feature requirement of its own; it is
	 *   exercised through the features that call it
	 */
	public function getQuotaAmount(string $ownerId, string $periodId, string $level): ?float {
		$quota = $this->getQuota(ownerId: $ownerId, periodId: $periodId, level: $level);
		if ($quota === null || isset($quota['quota_amount']) === false) {
			return null;
		}

		return (float)$quota['quota_amount'];
	}//end getQuotaAmount()

	/**
	 * Compute projected attainment: closed_won + commit + 0.5 * best_case.
	 *
	 * @param float $closedWon The closed-won amount.
	 * @param float $commit The (override-applied) commit amount.
	 * @param float $bestCase The best-case amount.
	 *
	 * @return float The projected attainment.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-008-03
	 */
	public function projectedAttainment(float $closedWon, float $commit, float $bestCase): float {
		return round($closedWon + $commit + (self::BEST_CASE_WEIGHT * $bestCase), 2);
	}//end projectedAttainment()

	/**
	 * Whether a forecast is at risk for its quota.
	 *
	 * At risk when projected attainment is below the configured percent of quota
	 * AND fewer than the configured number of days remain. No warning when the
	 * quota is unset or zero.
	 *
	 * @param float $projected The projected attainment.
	 * @param float $quota The quota amount.
	 * @param int $daysRemaining Days remaining in the period.
	 *
	 * @return bool True when the forecast is at risk.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-008-04, REQ-FRC-008-05, REQ-FRC-008-06
	 */
	public function isAtRisk(float $projected, float $quota, int $daysRemaining): bool {
		if ($quota <= 0) {
			return false;
		}

		$thresholdPercent = $this->getAtRiskPercent();
		$thresholdDays = $this->getAtRiskDays();

		$attainmentPercent = ($projected / $quota) * 100;
		return $attainmentPercent < $thresholdPercent && $daysRemaining < $thresholdDays;
	}//end isAtRisk()

	/**
	 * Build an advisory quota-hierarchy report for a period.
	 *
	 * For each team, compares the team quota to the sum of its member reps'
	 * quotas. Membership is resolved by the caller-supplied map of team =>
	 * [repIds].
	 *
	 * @param string $periodId The fiscal period identifier.
	 * @param array<string, array<int,string>> $teamMembers Map of team id => rep ids.
	 *
	 * @return array<int, array<string, mixed>> The advisory variance report rows.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-008
	 */
	public function validateQuotaHierarchy(string $periodId, array $teamMembers): array {
		$report = [];
		foreach ($teamMembers as $teamId => $repIds) {
			$teamQuota = $this->getQuotaAmount(ownerId: (string)$teamId, periodId: $periodId, level: 'team') ?? 0.0;
			$repSum = 0.0;
			foreach ($repIds as $repId) {
				$repSum += $this->getQuotaAmount(ownerId: $repId, periodId: $periodId, level: 'rep') ?? 0.0;
			}

			$variance = 0.0;
			if ($teamQuota > 0) {
				$variance = round((($repSum - $teamQuota) / $teamQuota) * 100, 1);
			}

			$report[] = [
				'team_id' => (string)$teamId,
				'team_quota' => $teamQuota,
				'rep_quotas_sum' => round($repSum, 2),
				'variance_percent' => $variance,
			];
		}

		return $report;
	}//end validateQuotaHierarchy()

	/**
	 * Resolve the at-risk attainment threshold (percent).
	 *
	 * @return int The percent threshold.
	 * @spec exclude infrastructure utility with no feature requirement of its own; it is
	 *   exercised through the features that call it
	 */
	public function getAtRiskPercent(): int {
		$value = $this->appConfig->getValueInt(Application::APP_ID, self::AT_RISK_PERCENT_KEY, self::AT_RISK_PERCENT_DEFAULT);
		if ($value > 0) {
			return $value;
		}

		return self::AT_RISK_PERCENT_DEFAULT;
	}//end getAtRiskPercent()

	/**
	 * Resolve the at-risk days-remaining threshold.
	 *
	 * @return int The days threshold.
	 * @spec exclude infrastructure utility with no feature requirement of its own; it is
	 *   exercised through the features that call it
	 */
	public function getAtRiskDays(): int {
		$value = $this->appConfig->getValueInt(Application::APP_ID, self::AT_RISK_DAYS_KEY, self::AT_RISK_DAYS_DEFAULT);
		if ($value > 0) {
			return $value;
		}

		return self::AT_RISK_DAYS_DEFAULT;
	}//end getAtRiskDays()

	/**
	 * Query objects of a configured schema with equality filters.
	 *
	 * @param string $schemaKey The app-config schema key.
	 * @param array<string, mixed> $filters Additional equality filters.
	 *
	 * @return array<int, array<string, mixed>> The matching objects as arrays.
	 */
	private function findObjects(string $schemaKey, array $filters): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			return [];
		}

		$allFilters = array_merge(['register' => $register, 'schema' => $schema], $filters);

		try {
			$results = $this->getObjectService()->findAll(config: ['filters' => $allFilters]);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: quota query failed', ['exception' => $e->getMessage()]);
			return [];
		}

		$objects = [];
		foreach (($results ?? []) as $result) {
			$objects[] = $this->toArray(object: $result);
		}

		return $objects;
	}//end findObjects()

	/**
	 * Normalize an OpenRegister entity (or array) to a plain array.
	 *
	 * @param mixed $object The entity or array.
	 *
	 * @return array<string, mixed> The object data.
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end toArray()

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
