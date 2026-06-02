<?php

/**
 * Pipelinq AnalyticsService.
 *
 * Cross-module KPI aggregation for the Klantbeeld 360 analytics dashboard.
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
 * @spec openspec/changes/klantbeeld-360/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Aggregates cross-module CRM KPIs from OpenRegister leads, requests and
 * contactmomenten for the analytics dashboard.
 *
 * All reads are scoped to this app's configured register and schemas so the
 * aggregation never leaks data from other registers. The OpenRegister
 * ObjectService is resolved lazily so the app degrades gracefully when
 * OpenRegister is not installed.
 *
 * @spec openspec/changes/klantbeeld-360/tasks.md#task-1.1
 */
class AnalyticsService
{
    /**
     * Lead and request statuses that count as "closed" / non-open.
     *
     * @var array<string>
     */
    private const CLOSED_REQUEST_STATUSES = ['closed', 'rejected', 'cancelled'];

    /**
     * Supported reporting periods mapped to a day window.
     *
     * @var array<string, int>
     */
    private const PERIOD_DAYS = [
        'week'    => 7,
        'month'   => 30,
        'quarter' => 90,
    ];

    /**
     * Constructor.
     *
     * @param IAppConfig         $appConfig App config (register/schema IDs).
     * @param ContainerInterface $container Container for ObjectService lookup.
     * @param LoggerInterface    $logger    Logger.
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether a period string is a supported reporting period.
     *
     * @param string $period The candidate period.
     *
     * @return bool True when the period is one of week/month/quarter.
     *
     * @spec openspec/changes/klantbeeld-360/tasks.md#task-1.1
     */
    public function isValidPeriod(string $period): bool
    {
        return array_key_exists($period, self::PERIOD_DAYS);
    }//end isValidPeriod()

    /**
     * Compute the inclusive lower boundary datetime for a reporting period.
     *
     * The boundary is the start of the day `n` days before today, where `n`
     * is the period window. Returns a UTC DateTimeImmutable.
     *
     * @param string $period The reporting period (week/month/quarter).
     *
     * @return DateTimeImmutable The period start boundary.
     *
     * @spec openspec/changes/klantbeeld-360/tasks.md#task-1.1
     */
    public function getPeriodBoundary(string $period): DateTimeImmutable
    {
        $days = self::PERIOD_DAYS[$period] ?? self::PERIOD_DAYS['month'];
        $now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $now->sub(new DateInterval('P'.$days.'D'))
            ->setTime(0, 0, 0);
    }//end getPeriodBoundary()

    /**
     * Build the cross-module KPI summary for a reporting period.
     *
     * Returns open pipeline value (sum of active lead values), open request
     * count, contactmomenten volume within the period, and the active lead
     * count. All figures are aggregated in PHP from register/schema-scoped
     * OpenRegister reads.
     *
     * @param string $period The reporting period (week/month/quarter).
     *
     * @return array{openPipelineValue: float, openRequests: int, contactmomentenCount: int, activeLeads: int, period: string} The KPI summary.
     *
     * @throws RuntimeException When the underlying data store cannot be read.
     *
     * @spec openspec/changes/klantbeeld-360/tasks.md#task-1.1
     */
    public function getSummary(string $period): array
    {
        if ($this->isValidPeriod(period: $period) === false) {
            $period = 'month';
        }

        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        if ($register === '') {
            // OpenRegister not configured yet: return an all-zero summary
            // rather than an error so the dashboard renders empty states.
            return $this->emptySummary(period: $period);
        }

        $leadSchema    = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
        $requestSchema = $this->appConfig->getValueString(Application::APP_ID, 'request_schema', '');
        $cmSchema      = $this->appConfig->getValueString(Application::APP_ID, 'contactmoment_schema', '');

        $boundary = $this->getPeriodBoundary(period: $period);

        try {
            $leads           = $this->fetchScoped(register: $register, schema: $leadSchema);
            $requests        = $this->fetchScoped(register: $register, schema: $requestSchema);
            $contactmomenten = $this->fetchScoped(register: $register, schema: $cmSchema);
        } catch (Throwable $e) {
            $this->logger->error(
                '[AnalyticsService] Failed to aggregate summary',
                ['exception' => $e]
            );
            throw new RuntimeException('Analytics aggregation failed');
        }

        $activeLeads = $this->filterByField(objects: $leads, field: 'status', value: 'active');

        return [
            'openPipelineValue'    => $this->sumValues(objects: $activeLeads),
            'openRequests'         => count($this->openRequests(requests: $requests)),
            'contactmomentenCount' => count($this->withinPeriod(objects: $contactmomenten, field: 'contactedAt', boundary: $boundary)),
            'activeLeads'          => count($activeLeads),
            'period'               => $period,
        ];
    }//end getSummary()

    /**
     * Build an all-zero summary envelope for the given period.
     *
     * @param string $period The reporting period.
     *
     * @return array{openPipelineValue: float, openRequests: int, contactmomentenCount: int, activeLeads: int, period: string} Empty summary.
     */
    private function emptySummary(string $period): array
    {
        return [
            'openPipelineValue'    => 0.0,
            'openRequests'         => 0,
            'contactmomentenCount' => 0,
            'activeLeads'          => 0,
            'period'               => $period,
        ];
    }//end emptySummary()

    /**
     * Fetch all objects for a register/schema scope as plain arrays.
     *
     * Returns an empty list when the schema is unconfigured so a missing
     * related entity does not break the whole aggregation.
     *
     * @param string $register The configured register ID.
     * @param string $schema   The schema ID to read (may be empty).
     *
     * @return array<int, array<string, mixed>> The scoped objects.
     */
    private function fetchScoped(string $register, string $schema): array
    {
        if ($schema === '') {
            return [];
        }

        $results = $this->getObjectService()->findAll(
            [
                'filters' => [
                    'register' => $register,
                    'schema'   => $schema,
                ],
            ]
        );

        $objects = [];
        foreach ($results as $result) {
            $objects[] = $this->normalizeToArray(object: $result);
        }

        return $objects;
    }//end fetchScoped()

    /**
     * Filter a list of objects to those whose field equals a value.
     *
     * @param array<int, array<string, mixed>> $objects The objects.
     * @param string                           $field   The field name.
     * @param string                           $value   The expected value.
     *
     * @return array<int, array<string, mixed>> The matching objects.
     */
    private function filterByField(array $objects, string $field, string $value): array
    {
        return array_values(
            array_filter(
                $objects,
                static fn (array $object): bool => (string) ($object[$field] ?? '') === $value
            )
        );
    }//end filterByField()

    /**
     * Filter requests to those that are still open (not closed/rejected).
     *
     * @param array<int, array<string, mixed>> $requests The request objects.
     *
     * @return array<int, array<string, mixed>> The open requests.
     */
    private function openRequests(array $requests): array
    {
        return array_values(
            array_filter(
                $requests,
                static fn (array $request): bool => in_array(
                    (string) ($request['status'] ?? ''),
                    self::CLOSED_REQUEST_STATUSES,
                    true
                ) === false
            )
        );
    }//end openRequests()

    /**
     * Filter objects to those whose date field is on/after a boundary.
     *
     * Objects with a missing or unparseable date field are excluded.
     *
     * @param array<int, array<string, mixed>> $objects  The objects.
     * @param string                           $field    The date field name.
     * @param DateTimeImmutable                $boundary The inclusive lower bound.
     *
     * @return array<int, array<string, mixed>> The objects within the period.
     */
    private function withinPeriod(array $objects, string $field, DateTimeImmutable $boundary): array
    {
        $matched = [];
        foreach ($objects as $object) {
            $raw = (string) ($object[$field] ?? '');
            if ($raw === '') {
                continue;
            }

            try {
                $date = new DateTimeImmutable($raw);
            } catch (Throwable $e) {
                continue;
            }

            if ($date >= $boundary) {
                $matched[] = $object;
            }
        }

        return $matched;
    }//end withinPeriod()

    /**
     * Sum the numeric `value` field across a list of objects.
     *
     * @param array<int, array<string, mixed>> $objects The objects.
     *
     * @return float The summed value.
     */
    private function sumValues(array $objects): float
    {
        $total = 0.0;
        foreach ($objects as $object) {
            $total += (float) ($object['value'] ?? 0);
        }

        return $total;
    }//end sumValues()

    /**
     * Lazy ObjectService resolver to avoid a hard dependency on OpenRegister.
     *
     * @return object The OpenRegister ObjectService instance.
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()

    /**
     * Normalise an ObjectEntity-or-array to a plain associative array.
     *
     * @param mixed $object The raw result from ObjectService.
     *
     * @return array<string, mixed> The serialised array form.
     */
    private function normalizeToArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true) {
            if (method_exists($object, 'jsonSerialize') === true) {
                $serialised = $object->jsonSerialize();
                if (is_array($serialised) === true) {
                    return $serialised;
                }
            }

            if (method_exists($object, 'toArray') === true) {
                $array = $object->toArray();
                if (is_array($array) === true) {
                    return $array;
                }
            }
        }

        return [];
    }//end normalizeToArray()
}//end class
