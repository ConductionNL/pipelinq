<?php

/**
 * Pipelinq AnalyticsService.
 *
 * Server-side aggregation of cross-module KPIs (open pipeline value,
 * open requests, contactmomenten count, active leads) for the
 * Klantbeeld 360 analytics dashboard. Avoids fetching full collections
 * client-side on large installations.
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/klantbeeld-360/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Cross-module analytics summary service.
 *
 * Stateless. Reads leads, requests and contactmomenten via the
 * OpenRegister ObjectService, aggregates counts/sums in PHP and
 * returns the four headline KPIs for the Analytics dashboard.
 *
 * @spec openspec/changes/klantbeeld-360/tasks.md#task-1.1
 */
class AnalyticsService
{
    /**
     * Allowed period selectors (ADR-018, REQ-KB360-021).
     *
     * @var array<int, string>
     */
    public const ALLOWED_PERIODS = ['week', 'month', 'quarter'];

    /**
     * Default period when caller supplies none.
     *
     * @var string
     */
    public const DEFAULT_PERIOD = 'month';

    /**
     * Statuses we consider "closed" for service requests.
     *
     * Anything NOT in this set counts as an open request
     * (REQ-KB360-020 / Feature 3 KPI 2).
     *
     * @var array<int, string>
     */
    private const REQUEST_CLOSED_STATUSES = ['closed', 'rejected'];

    /**
     * Statuses considered "active" (open) for leads.
     *
     * The spec wording uses `active` as the shorthand for "still in
     * the pipeline"; the actual lead schema enum is
     * `open|won|lost` (lib/Settings/pipelinq_register.json -> lead.status).
     * `open` is the canonical match; we accept both for resilience.
     *
     * @var array<int, string>
     */
    private const LEAD_ACTIVE_STATUSES = ['open', 'active'];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (OpenRegister lookup).
     * @param IAppConfig         $appConfig App configuration (register/schema IDs).
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Compute the cross-module summary for the given period.
     *
     * Returns:
     *   - openPipelineValue:    sum of `value` over active leads (all-time)
     *   - openRequests:         count of requests with status NOT in {closed, rejected}
     *   - contactmomentenCount: count of contactmomenten within the period window
     *   - activeLeads:          count of leads with status = 'active'
     *
     * @param string $period One of `ALLOWED_PERIODS`.
     *
     * @return array{
     *   openPipelineValue: float,
     *   openRequests: int,
     *   contactmomentenCount: int,
     *   activeLeads: int,
     *   period: string
     * }
     *
     * @throws InvalidArgumentException When the period is not recognised.
     * @throws RuntimeException         When OpenRegister is unavailable.
     *
     * @spec openspec/changes/klantbeeld-360/tasks.md#task-1.1
     */
    public function getSummary(string $period=self::DEFAULT_PERIOD): array
    {
        if (in_array($period, self::ALLOWED_PERIODS, true) === false) {
            throw new InvalidArgumentException(message: 'Invalid period');
        }

        $boundary = $this->getPeriodBoundary(period: $period);

        $leads           = $this->findObjects(schemaKey: 'lead_schema');
        $requests        = $this->findObjects(schemaKey: 'request_schema');
        $contactmomenten = $this->findObjects(schemaKey: 'contactmoment_schema');

        $openPipelineValue = 0.0;
        $activeLeads       = 0;
        foreach ($leads as $lead) {
            $status = (string) ($lead['status'] ?? '');
            if (in_array($status, self::LEAD_ACTIVE_STATUSES, true) === true) {
                $activeLeads++;
                $openPipelineValue += (float) ($lead['value'] ?? 0);
            }
        }

        $openRequests = 0;
        foreach ($requests as $request) {
            $status = (string) ($request['status'] ?? '');
            if (in_array($status, self::REQUEST_CLOSED_STATUSES, true) === false) {
                $openRequests++;
            }
        }

        $contactmomentenCount = 0;
        $boundaryTimestamp    = $boundary->getTimestamp();
        foreach ($contactmomenten as $cm) {
            $contactedAt = (string) ($cm['contactedAt'] ?? '');
            if ($contactedAt === '') {
                continue;
            }

            $ts = strtotime($contactedAt);
            if ($ts === false) {
                continue;
            }

            if ($ts >= $boundaryTimestamp) {
                $contactmomentenCount++;
            }
        }

        return [
            'openPipelineValue'    => round($openPipelineValue, 2),
            'openRequests'         => $openRequests,
            'contactmomentenCount' => $contactmomentenCount,
            'activeLeads'          => $activeLeads,
            'period'               => $period,
        ];
    }//end getSummary()

    /**
     * Compute the inclusive lower boundary for a period selector.
     *
     * - `week`    = now minus 7 days
     * - `month`   = now minus 30 days
     * - `quarter` = now minus 90 days
     *
     * Implementation note: trailing windows (rather than calendar week /
     * calendar month) are used so the KPI is meaningful regardless of
     * the day a user opens the dashboard.
     *
     * @param string $period One of `ALLOWED_PERIODS`.
     *
     * @return DateTimeInterface Inclusive lower bound.
     *
     * @throws InvalidArgumentException When the period is not recognised.
     *
     * @spec openspec/changes/klantbeeld-360/tasks.md#task-1.1
     */
    public function getPeriodBoundary(string $period): DateTimeInterface
    {
        $now = new DateTimeImmutable();

        return match ($period) {
            'week'    => $now->modify('-7 days'),
            'month'   => $now->modify('-30 days'),
            'quarter' => $now->modify('-90 days'),
            default   => throw new InvalidArgumentException(message: 'Invalid period'),
        };
    }//end getPeriodBoundary()

    /**
     * Query objects of a configured schema via OpenRegister ObjectService.
     *
     * Returns a list of plain arrays. Empty register/schema config or
     * unavailable OpenRegister is treated as "no data" (logged); other
     * failures are surfaced via RuntimeException to the controller.
     *
     * @param string $schemaKey The app-config key holding the schema ID.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws RuntimeException When OpenRegister is unreachable mid-query.
     */
    private function findObjects(string $schemaKey): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
        if ($register === '' || $schema === '') {
            $this->logger->info(
                message: '[AnalyticsService] register or schema not configured',
                context: ['schemaKey' => $schemaKey]
            );
            return [];
        }

        try {
            $objectService = $this->getObjectService();
            $results       = $objectService->findAll(
                config: ['filters' => ['register' => $register, 'schema' => $schema]]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[AnalyticsService] findAll failed',
                context: ['schemaKey' => $schemaKey, 'error' => $e->getMessage()]
            );
            throw new RuntimeException(message: 'Analytics query failed', code: 0, previous: $e);
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
     * @param mixed $object Entity / array / unknown payload.
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $object): array
    {
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
     * Resolve the OpenRegister ObjectService via DI container.
     *
     * @return object The ObjectService instance.
     *
     * @throws RuntimeException When OpenRegister is not available.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException(message: 'OpenRegister ObjectService is unavailable.', code: 0, previous: $e);
        }
    }//end getObjectService()
}//end class
