<?php

/**
 * Pipelinq SlaAttainmentService.
 *
 * Aggregates SLA breach-event records to compute attainment ratios
 * broken down by policy, customer-tier, target-kind or assignee-team
 * for a configurable time bucket (day / week / month / quarter).
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
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SlaEngineService;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Compute SLA attainment aggregations from `slaBreachEvent` records.
 *
 * The service queries breach events for a time-bucket and pairs them
 * with currently-tracked objects (request / complaint / callback) to
 * compute per-target attainment ratios. Per-target accounting: a
 * tracked object that met `acknowledgement` but breached `resolution`
 * counts as 1.0 met for acknowledgement and 0.0 met for resolution
 * (REQ-006).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SlaAttainmentService
{
    public const VALID_BUCKETS = ['day', 'week', 'month', 'quarter'];

    public const VALID_GROUPS = ['policy', 'customer', 'tier', 'team', 'target'];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container DI container.
     * @param IAppConfig         $appConfig App config.
     * @param LoggerInterface    $logger    PSR logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Compute attainment for the requested filter.
     *
     * @param array<string, mixed> $params Filter parameters: bucket, date,
     *                                     week, month, quarter, groupBy, policy.
     *
     * @return array<string, mixed> Attainment payload.
     *
     * @throws InvalidArgumentException When required params are invalid.
     */
    public function compute(array $params): array
    {
        $bucket = (string) ($params['bucket'] ?? 'month');
        if (in_array($bucket, self::VALID_BUCKETS, true) === false) {
            throw new InvalidArgumentException('invalidBucket');
        }

        $groupBy = (string) ($params['groupBy'] ?? 'policy');
        if (in_array($groupBy, self::VALID_GROUPS, true) === false) {
            throw new InvalidArgumentException('invalidGroupBy');
        }

        [$start, $end] = $this->resolveBucketRange(bucket: $bucket, params: $params);
        $events        = $this->loadBreachEventsInRange(start: $start, end: $end);
        $policyFilter  = (string) ($params['policy'] ?? '');

        $total      = 0;
        $met        = 0;
        $breached   = 0;
        $inFlight   = 0;
        $closed     = 0;
        $byTarget   = [];
        $groupAccum = [];

        foreach ($events as $event) {
            if ($policyFilter !== '' && (string) ($event['policyId'] ?? '') !== $policyFilter) {
                continue;
            }

            $total++;
            $kind     = (string) ($event['targetKind'] ?? 'resolution');
            $resolved = isset($event['resolvedAt']) === true && $event['resolvedAt'] !== '';
            if ($resolved === true) {
                $closed++;
            } else {
                $inFlight++;
            }

            $breached++;
            $byTarget[$kind] = ($byTarget[$kind] ?? ['breached' => 0, 'met' => 0]);
            $byTarget[$kind]['breached']++;

            $groupKey  = $this->groupKey(groupBy: $groupBy, event: $event);
            $groupName = $this->groupName(groupBy: $groupBy, event: $event, key: $groupKey);
            $groupAccum[$groupKey] = ($groupAccum[$groupKey] ?? ['name' => $groupName, 'total' => 0, 'breached' => 0]);
            $groupAccum[$groupKey]['total']++;
            $groupAccum[$groupKey]['breached']++;
        }//end foreach

        // For attainment we need the closed-met denominator too — query
        // tracked objects with all targets met in the period.
        $metCounts = $this->countMetObjectsInRange(start: $start, end: $end, policyFilter: $policyFilter);
        foreach ($metCounts['byTarget'] as $kind => $count) {
            $byTarget[$kind]        = ($byTarget[$kind] ?? ['breached' => 0, 'met' => 0]);
            $byTarget[$kind]['met'] = $count;
        }

        $met   += $metCounts['total'];
        $total += $metCounts['total'];

        foreach ($metCounts['byGroup'] as $key => $entry) {
            $groupAccum[$key]          = ($groupAccum[$key] ?? ['name' => $entry['name'], 'total' => 0, 'breached' => 0, 'met' => 0]);
            $groupAccum[$key]['total'] = ($groupAccum[$key]['total'] ?? 0) + $entry['total'];
            $groupAccum[$key]['met']   = ($groupAccum[$key]['met'] ?? 0) + $entry['total'];
        }

        $byTargetOut = [];
        foreach ($byTarget as $kind => $counts) {
            $metCount = (int) $counts['met'];
            $denom    = ($counts['breached'] + $metCount);
            if ($denom > 0) {
                $attainment = round(($metCount / $denom), 4);
            } else {
                $attainment = 0.0;
            }

            $byTargetOut[$kind] = [
                'attainment' => $attainment,
                'breached'   => $counts['breached'],
                'met'        => $metCount,
            ];
        }

        $byGroup = [];
        foreach ($groupAccum as $key => $entry) {
            $denom = (int) $entry['total'];
            $metE  = (int) ($entry['met'] ?? 0);
            if ($denom > 0) {
                $groupAttainment = round(($metE / $denom), 4);
            } else {
                $groupAttainment = 0.0;
            }

            $byGroup[] = [
                'groupKey'   => (string) $key,
                'groupName'  => (string) ($entry['name'] ?? $key),
                'attainment' => $groupAttainment,
                'total'      => $denom,
                'met'        => $metE,
                'breached'   => (int) $entry['breached'],
            ];
        }

        if ($total > 0) {
            $overallAttainment = round(($met / $total), 4);
        } else {
            $overallAttainment = 0.0;
        }

        return [
            'attainment'        => $overallAttainment,
            // Same value scaled to a literal percent (0–100) so a declarative
            // dashboard stat widget can render it with format.style "percent".
            'attainmentPercent' => round(($overallAttainment * 100), 1),
            'total'             => $total,
            'met'               => $met,
            'breached'          => $breached,
            'inFlightBreached'  => $inFlight,
            'closedBreached'    => $closed,
            'range'             => [
                'start' => $start->format(DateTimeInterface::ATOM),
                'end'   => $end->format(DateTimeInterface::ATOM),
            ],
            'details'           => [
                'byTarget' => $byTargetOut,
                'byGroup'  => $byGroup,
            ],
        ];
    }//end compute()

    /**
     * Resolve the (start, end) instant pair for the requested bucket.
     *
     * @param string               $bucket Bucket identifier.
     * @param array<string, mixed> $params Filter parameters.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} Range.
     *
     * @throws InvalidArgumentException On missing/invalid bucket value.
     */
    public function resolveBucketRange(string $bucket, array $params): array
    {
        $tz  = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $tz);
        switch ($bucket) {
            case 'day':
                // An empty/missing date defaults to today so a declarative
                // dashboard can drive the bucket select alone (no client-side
                // date math); an explicitly supplied value must still be valid.
                $date = (string) ($params['date'] ?? '');
                if ($date === '') {
                    $date = $now->format('Y-m-d');
                } else if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                    throw new InvalidArgumentException('invalidDate');
                }

                $start = new DateTimeImmutable($date.' 00:00:00', $tz);
                return [$start, $start->modify('+1 day')];

            case 'week':
                $week = (string) ($params['week'] ?? '');
                if ($week === '') {
                    $week = $now->format('o-\WW');
                }

                if (preg_match('/^(\d{4})-W(\d{1,2})$/', $week, $matches) !== 1) {
                    throw new InvalidArgumentException('invalidWeek');
                }

                $start = (new DateTimeImmutable('now', $tz))
                    ->setISODate((int) $matches[1], (int) $matches[2])
                    ->setTime(0, 0);
                return [$start, $start->modify('+7 days')];

            case 'month':
                $month = (string) ($params['month'] ?? '');
                if ($month === '') {
                    $month = $now->format('Y-m');
                }

                if (preg_match('/^(\d{4})-(\d{2})$/', $month, $matches) !== 1) {
                    throw new InvalidArgumentException('invalidMonth');
                }

                $start = new DateTimeImmutable($month.'-01 00:00:00', $tz);
                return [$start, $start->modify('+1 month')];

            case 'quarter':
                $quarter = (string) ($params['quarter'] ?? '');
                if ($quarter === '') {
                    $quarter = sprintf('%s-Q%d', $now->format('Y'), (int) ceil(((int) $now->format('n')) / 3));
                }

                if (preg_match('/^(\d{4})-Q([1-4])$/', $quarter, $matches) !== 1) {
                    throw new InvalidArgumentException('invalidQuarter');
                }

                $month = (((int) $matches[2] - 1) * 3) + 1;
                $start = new DateTimeImmutable(sprintf('%s-%02d-01 00:00:00', $matches[1], $month), $tz);
                return [$start, $start->modify('+3 months')];

            default:
                throw new InvalidArgumentException('invalidBucket');
        }//end switch
    }//end resolveBucketRange()

    /**
     * Load breach-event records whose breachedAt is in the time range.
     *
     * @param DateTimeInterface $start Start instant.
     * @param DateTimeInterface $end   End instant (exclusive).
     *
     * @return array<int, array<string, mixed>> Events.
     */
    private function loadBreachEventsInRange(DateTimeInterface $start, DateTimeInterface $end): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'sla_register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'sla_breach_event_schema', '');
        if ($register === '' || $schema === '') {
            return [];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Throwable $e) {
            $this->logger->warning(
                'SlaAttainmentService: ObjectService not available',
                ['error' => $e->getMessage()]
            );
            return [];
        }

        try {
            $rows = $objectService->findAll(
                config: [
                    'register' => $register,
                    'schema'   => $schema,
                    'limit'    => 5000,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'SlaAttainmentService: findAll failed',
                ['error' => $e->getMessage()]
            );
            return [];
        }

        $events = [];
        foreach ((array) $rows as $row) {
            $array = $this->normalise(row: $row);
            $when  = $array['breachedAt'] ?? '';
            if ($when === '') {
                continue;
            }

            try {
                $instant = new DateTimeImmutable((string) $when);
            } catch (Throwable $e) {
                continue;
            }

            if ($instant < $start || $instant >= $end) {
                continue;
            }

            $events[] = $array;
        }

        return $events;
    }//end loadBreachEventsInRange()

    /**
     * Count tracked objects that met all targets in the time range.
     *
     * Walks request/complaint/callback schemas, picks the ones with
     * slaStatus.targets[*].metAt in range and no breached/at-risk
     * targets remaining.
     *
     * @param DateTimeInterface $start        Start instant.
     * @param DateTimeInterface $end          End instant.
     * @param string            $policyFilter Optional policy identity filter.
     *
     * @return array{total: int, byTarget: array<string, int>, byGroup: array<string, array<string, mixed>>} Counts.
     */
    private function countMetObjectsInRange(
        DateTimeInterface $start,
        DateTimeInterface $end,
        string $policyFilter,
    ): array {
        $total    = 0;
        $byTarget = [];
        $byGroup  = [];

        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        if ($register === '') {
            return ['total' => 0, 'byTarget' => [], 'byGroup' => []];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Throwable $e) {
            return ['total' => 0, 'byTarget' => [], 'byGroup' => []];
        }

        foreach (['request_schema', 'complaint_schema', 'callback_schema'] as $configKey) {
            $schemaId = $this->appConfig->getValueString(Application::APP_ID, $configKey, '');
            if ($schemaId === '') {
                continue;
            }

            try {
                $rows = $objectService->findAll(
                    config: [
                        'register' => $register,
                        'schema'   => $schemaId,
                        'limit'    => 5000,
                    ]
                );
            } catch (Throwable $e) {
                continue;
            }

            foreach ((array) $rows as $row) {
                $array     = $this->normalise(row: $row);
                $slaStatus = $array['slaStatus'] ?? null;
                if (is_array($slaStatus) === false) {
                    continue;
                }

                if ($policyFilter !== '' && (string) ($slaStatus['policyId'] ?? '') !== $policyFilter) {
                    continue;
                }

                $allMet     = true;
                $touchedKey = false;
                foreach (($slaStatus['targets'] ?? []) as $target) {
                    $status = (string) ($target['status'] ?? '');
                    if ($status !== SlaEngineService::STATUS_MET) {
                        $allMet = false;
                        continue;
                    }

                    $metAt = $target['metAt'] ?? '';
                    if ($metAt === '') {
                        continue;
                    }

                    try {
                        $metInstant = new DateTimeImmutable((string) $metAt);
                    } catch (Throwable $e) {
                        continue;
                    }

                    if ($metInstant >= $start && $metInstant < $end) {
                        $touchedKey      = true;
                        $kind            = (string) ($target['kind'] ?? 'resolution');
                        $byTarget[$kind] = ($byTarget[$kind] ?? 0) + 1;
                    }
                }//end foreach

                if ($allMet === true && $touchedKey === true) {
                    $total++;
                    $key           = (string) ($slaStatus['policyId'] ?? 'unknown');
                    $byGroup[$key] = ($byGroup[$key] ?? ['name' => $key, 'total' => 0]);
                    $byGroup[$key]['total']++;
                }
            }//end foreach
        }//end foreach

        return ['total' => $total, 'byTarget' => $byTarget, 'byGroup' => $byGroup];
    }//end countMetObjectsInRange()

    /**
     * Derive a group key for an event according to the requested grouping.
     *
     * @param string               $groupBy Grouping mode.
     * @param array<string, mixed> $event   Breach event.
     *
     * @return string Group key.
     */
    private function groupKey(string $groupBy, array $event): string
    {
        return match ($groupBy) {
            'policy'   => (string) ($event['policyId'] ?? 'unknown'),
            'tier'     => (string) ($event['customerTier'] ?? 'unspecified'),
            'team'     => (string) ($event['team'] ?? 'unspecified'),
            'customer' => (string) ($event['organisationId'] ?? 'unspecified'),
            'target'   => (string) ($event['targetKind'] ?? 'resolution'),
            default    => 'all',
        };
    }//end groupKey()

    /**
     * Derive a human-readable name for a group key.
     *
     * @param string               $groupBy Grouping mode.
     * @param array<string, mixed> $event   Breach event.
     * @param string               $key     Resolved group key.
     *
     * @return string Group display name.
     */
    private function groupName(string $groupBy, array $event, string $key): string
    {
        unset($groupBy, $event);
        return $key;
    }//end groupName()

    /**
     * Normalise OR row/entity to a plain associative array.
     *
     * @param mixed $row Raw row.
     *
     * @return array<string, mixed> Normalised array.
     */
    private function normalise($row): array
    {
        if (is_array($row) === true) {
            return $row;
        }

        if (is_object($row) === true && method_exists($row, 'getObject') === true) {
            $object = $row->getObject();
            if (is_array($object) === true) {
                return $object;
            }
        }

        if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
            $json = $row->jsonSerialize();
            if (is_array($json) === true) {
                return $json;
            }

            return [];
        }

        return [];
    }//end normalise()
}//end class
