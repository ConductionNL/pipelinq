<?php

/**
 * Pipelinq ComplaintSlaService.
 *
 * Service for complaint SLA deadline calculation and monitoring.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/klachtenregistratie/spec.md#Backend-SLA-Deadline-Service
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Service for complaint SLA deadline calculation and overdue detection.
 *
 * Reads per-category SLA hours from app config and provides helpers
 * for calculating deadlines and checking overdue status.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-attainment-reporting
 */
class ComplaintSlaService {
	/**
	 * Valid complaint categories that can have SLA configuration.
	 *
	 * @var array<string>
	 */
	public const VALID_CATEGORIES = [
		'service',
		'product',
		'communication',
		'billing',
		'other',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the configured SLA hours for a complaint category.
	 *
	 * Reads the `complaint_sla_{category}` key from app config.
	 * Returns 0 if the category has no SLA configured.
	 *
	 * @param string $category The complaint category.
	 *
	 * @return int The SLA hours, or 0 if not configured.
	 * @spec   openspec/specs/klachtenregistratie/spec.md#Backend-SLA-Deadline-Service
	 */
	public function getSlaHoursForCategory(string $category): int {
		if (in_array($category, self::VALID_CATEGORIES, true) === false) {
			$this->logger->warning(
				'ComplaintSlaService: Unknown category "{category}"',
				['category' => $category],
			);
			return 0;
		}

		$key = 'complaint_sla_' . $category;
		$value = $this->appConfig->getValueString(
			Application::APP_ID,
			$key,
			'',
		);

		if ($value === '' || is_numeric($value) === false) {
			return 0;
		}

		return (int)$value;
	}//end getSlaHoursForCategory()

	/**
	 * Calculate the SLA deadline for a complaint based on its category.
	 *
	 * Returns null if no SLA is configured for the given category.
	 *
	 * @param string $category The complaint category.
	 * @param DateTimeInterface|null $from The starting point (defaults to now).
	 *
	 * @return DateTimeImmutable|null The deadline, or null if no SLA configured.
	 * @spec   openspec/specs/klachtenregistratie/spec.md#Backend-SLA-Deadline-Service
	 */
	public function calculateDeadline(
		string $category,
		?DateTimeInterface $from = null,
	): ?DateTimeImmutable {
		$hours = $this->getSlaHoursForCategory(category: $category);

		if ($hours <= 0) {
			return null;
		}

		$start = new DateTimeImmutable();
		if ($from !== null) {
			$start = new DateTimeImmutable($from->format('Y-m-d\TH:i:sP'));
		}

		// DateTimeImmutable::modify() always returns static here; assert confirms it.
		$result = $start->modify('+' . $hours . ' hours');
		assert($result !== false);

		return $result;
	}//end calculateDeadline()

	/**
	 * Determine whether a complaint is overdue.
	 *
	 * A complaint is overdue when its slaDeadline is in the past and its
	 * status is still open (not resolved or rejected).
	 *
	 * @param array<string, mixed> $complaint The complaint object array.
	 * @param DateTimeInterface|null $now The reference time (defaults to now).
	 *
	 * @return bool True when the complaint is overdue.
	 * @spec   openspec/specs/klachtenregistratie/spec.md#Backend-SLA-Deadline-Service
	 */
	public function isOverdue(array $complaint, ?DateTimeInterface $now = null): bool {
		$deadline = $complaint['slaDeadline'] ?? null;

		if ($deadline === null || $deadline === '') {
			return false;
		}

		$status = $complaint['status'] ?? '';

		if ($this->isOpenStatus(status: $status) === false) {
			return false;
		}

		try {
			$deadlineDate = new DateTimeImmutable((string)$deadline);
		} catch (Exception $e) {
			return false;
		}

		$reference = $now ?? new DateTimeImmutable();

		return $deadlineDate < $reference;
	}//end isOverdue()

	/**
	 * Check whether a complaint status is considered open (not yet resolved).
	 *
	 * @param string $status The complaint status value.
	 *
	 * @return bool True when the status is open.
	 * @spec   openspec/specs/klachtenregistratie/spec.md#Backend-SLA-Deadline-Service
	 */
	public function isOpenStatus(string $status): bool {
		return in_array($status, ['new', 'in_progress'], true);
	}//end isOpenStatus()
}//end class
