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
 */
class ComplaintSlaService
{
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
     * Statuses that indicate a complaint is still open (not terminal).
     *
     * @var array<string>
     */
    private const OPEN_STATUSES = [
        'new',
        'in_progress',
    ];

    /**
     * Constructor.
     *
     * @param IAppConfig      $appConfig The app configuration service.
     * @param LoggerInterface $logger    The logger.
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
     */
    public function getSlaHoursForCategory(string $category): int
    {
        if (in_array($category, self::VALID_CATEGORIES, true) === false) {
            $this->logger->warning(
                'ComplaintSlaService: Unknown category "{category}"',
                ['category' => $category],
            );
            return 0;
        }

        $key   = 'complaint_sla_'.$category;
        $value = $this->appConfig->getValueString(
            Application::APP_ID,
            $key,
            '',
        );

        if ($value === '' || is_numeric($value) === false) {
            return 0;
        }

        return (int) $value;
    }//end getSlaHoursForCategory()

    /**
     * Calculate the SLA deadline for a complaint based on its category.
     *
     * Returns null if no SLA is configured for the given category.
     *
     * @param string                 $category The complaint category.
     * @param DateTimeInterface|null $from     The starting point (defaults to now).
     *
     * @return DateTimeImmutable|null The deadline, or null if no SLA configured.
     */
    public function calculateDeadline(
        string $category,
        ?DateTimeInterface $from=null,
    ): ?DateTimeImmutable {
        $hours = $this->getSlaHoursForCategory(category: $category);

        if ($hours <= 0) {
            return null;
        }

        if ($from !== null) {
            $start = new DateTimeImmutable($from->format('Y-m-d\TH:i:sP'));
        } else {
            $start = new DateTimeImmutable();
        }

        return $start->modify('+'.$hours.' hours');
    }//end calculateDeadline()

    /**
     * Check whether a complaint is overdue based on its SLA deadline.
     *
     * A complaint is overdue when:
     * 1. It has an slaDeadline set
     * 2. Its status is open (new or in_progress)
     * 3. The current time is past the deadline
     *
     * @param array<string, mixed>   $complaint The complaint data array.
     * @param DateTimeInterface|null $now       The current time (defaults to now).
     *
     * @return bool True if the complaint is overdue.
     */
    public function isOverdue(
        array $complaint,
        ?DateTimeInterface $now=null,
    ): bool {
        $deadline = $complaint['slaDeadline'] ?? null;
        $status   = $complaint['status'] ?? 'new';

        if ($deadline === null || $deadline === '') {
            return false;
        }

        if (in_array($status, self::OPEN_STATUSES, true) === false) {
            return false;
        }

        try {
            $deadlineDate = new DateTimeImmutable($deadline);
        } catch (Exception $e) {
            $this->logger->warning(
                'ComplaintSlaService: Invalid deadline format "{deadline}"',
                [
                    'deadline'  => $deadline,
                    'exception' => $e->getMessage(),
                ],
            );
            return false;
        }

        if ($now !== null) {
            $currentTime = new DateTimeImmutable($now->format('Y-m-d\TH:i:sP'));
        } else {
            $currentTime = new DateTimeImmutable();
        }

        return $currentTime > $deadlineDate;
    }//end isOverdue()

    /**
     * Check if a complaint status is open (non-terminal).
     *
     * @param string $status The complaint status.
     *
     * @return bool True if the status is open.
     */
    public function isOpenStatus(string $status): bool
    {
        return in_array($status, self::OPEN_STATUSES, true);
    }//end isOpenStatus()
}//end class
