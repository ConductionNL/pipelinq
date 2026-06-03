<?php

/**
 * Pipelinq SlaAttainmentService.
 *
 * Aggregates SLA attainment from breach events and tracked objects, broken down
 * by policy / customer / tier / team and time bucket (REQ-006).
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
 * @link https://codeberg.org/Conduction/pipelinq
 *
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use Exception;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Computes SLA attainment statistics (REQ-006).
 *
 * Attainment is per-target: a tracked object that met acknowledgement but
 * breached resolution counts as met for acknowledgement and breached for
 * resolution. In-flight breaches (object still open) are distinguished from
 * closed breaches (resolved after deadline).
 *
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-006
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) — the per-target × per-group ×
 * per-time-bucket accounting is irreducibly broad; methods are individually small.
 */
class SlaAttainmentService
{
    /**
     * Constructor.
     *
     * @param IAppConfig         $appConfig The app configuration.
     * @param ContainerInterface $container Container for ObjectService lookup.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Compute the attainment report for a time bucket and grouping (REQ-006).
     *
     * @param array<string, mixed> $params Query params: bucket, quarter/month/week/date, groupBy, policy.
     *
     * @return array<string, mixed> The attainment report.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-006
     */
    public function report(array $params): array
    {
        [$from, $to] = $this->resolveWindow(params: $params);
        $groupBy     = (string) ($params['groupBy'] ?? 'policy');
        $policyId    = (string) ($params['policy'] ?? '');

        $objects = $this->loadTrackedObjects();
        $rows    = [];
        foreach ($objects as $object) {
            $rows = array_merge($rows, $this->expandObjectTargets(object: $object, from: $from, to: $to, policyFilter: $policyId));
        }

        return $this->aggregate(rows: $rows, groupBy: $groupBy);
    }//end report()

    /**
     * Expand one tracked object into per-target attainment rows within a window.
     *
     * @param array<string, mixed> $object       The tracked object.
     * @param DateTimeImmutable    $from         The window start.
     * @param DateTimeImmutable    $to           The window end.
     * @param string               $policyFilter Optional policy id filter.
     *
     * @return array<int, array<string, mixed>> The per-target rows.
     */
    private function expandObjectTargets(array $object, DateTimeImmutable $from, DateTimeImmutable $to, string $policyFilter): array
    {
        $sla = ($object['slaStatus'] ?? null);
        if (is_array($sla) === false || $this->isInWindow(sla: $sla, from: $from, to: $to) === false) {
            return [];
        }

        $policyId = (string) ($sla['policyId'] ?? '');
        if ($policyFilter !== '' && $policyFilter !== $policyId) {
            return [];
        }

        $rows = [];
        foreach (($sla['targets'] ?? []) as $target) {
            $status = (string) ($target['status'] ?? '');
            if ($status === 'on-track' || $status === 'at-risk') {
                // Not yet decided; excluded from attainment denominators.
                continue;
            }

            $rows[] = $this->buildTargetRow(object: $object, policyId: $policyId, target: $target, status: $status);
        }//end foreach

        return $rows;
    }//end expandObjectTargets()

    /**
     * Whether an slaStatus started within the reporting window.
     *
     * @param array<string, mixed> $sla  The slaStatus sub-object.
     * @param DateTimeImmutable    $from The window start.
     * @param DateTimeImmutable    $to   The window end.
     *
     * @return bool True when the start instant lies in [from, to].
     */
    private function isInWindow(array $sla, DateTimeImmutable $from, DateTimeImmutable $to): bool
    {
        $started = $this->toDate(value: ($sla['startedAt'] ?? null));
        return $started !== null && $started >= $from && $started <= $to;
    }//end isInWindow()

    /**
     * Build a single per-target attainment row.
     *
     * @param array<string, mixed> $object   The tracked object.
     * @param string               $policyId The resolved policy id.
     * @param array<string, mixed> $target   The target sub-object.
     * @param string               $status   The decided target status.
     *
     * @return array<string, mixed> The attainment row.
     */
    private function buildTargetRow(array $object, string $policyId, array $target, string $status): array
    {
        $isMet  = ($status === 'met');
        $isOpen = ($this->isObjectOpen(object: $object) === true);

        return [
            'policyId'       => $policyId,
            'kind'           => (string) ($target['kind'] ?? ''),
            'customer'       => (string) ($object['client'] ?? ''),
            'tier'           => $this->normaliseTier(tier: (string) ($object['slaTier'] ?? '')),
            'team'           => (string) ($object['assignee'] ?? $object['assignedTo'] ?? ''),
            'met'            => $isMet,
            'breached'       => ($isMet === false),
            'inFlightBreach' => ($isMet === false && $isOpen === true),
            'closedBreach'   => ($isMet === false && $isOpen === false),
        ];
    }//end buildTargetRow()

    /**
     * Aggregate per-target rows into the report envelope.
     *
     * @param array<int, array<string, mixed>> $rows    The per-target rows.
     * @param string                           $groupBy The grouping dimension.
     *
     * @return array<string, mixed> The report.
     */
    private function aggregate(array $rows, string $groupBy): array
    {
        $total    = count($rows);
        $met      = 0;
        $inFlight = 0;
        $closed   = 0;
        $byTarget = [];
        $byGroup  = [];

        foreach ($rows as $row) {
            if ($row['met'] === true) {
                $met++;
            }

            if ($row['inFlightBreach'] === true) {
                $inFlight++;
            }

            if ($row['closedBreach'] === true) {
                $closed++;
            }

            $this->accumulate(bucket: $byTarget, key: $row['kind'], row: $row);
            $this->accumulate(bucket: $byGroup, key: (string) $row[$this->groupField(groupBy: $groupBy)], row: $row);
        }//end foreach

        $attainment = 0.0;
        if ($total > 0) {
            $attainment = round($met / $total, 4);
        }

        return [
            'attainment'       => $attainment,
            'total'            => $total,
            'met'              => $met,
            'breached'         => ($total - $met),
            'inFlightBreached' => $inFlight,
            'closedBreached'   => $closed,
            'details'          => [
                'byTarget' => $this->finaliseBuckets(buckets: $byTarget),
                'byGroup'  => array_values($this->finaliseBuckets(buckets: $byGroup)),
            ],
        ];
    }//end aggregate()

    /**
     * Accumulate a row into a keyed bucket.
     *
     * @param array<string, array<string, mixed>> $bucket The bucket map (by reference).
     * @param string                              $key    The bucket key.
     * @param array<string, mixed>                $row    The row.
     *
     * @return void
     */
    private function accumulate(array &$bucket, string $key, array $row): void
    {
        if (isset($bucket[$key]) === false) {
            $bucket[$key] = ['groupKey' => $key, 'groupName' => $key, 'total' => 0, 'met' => 0, 'breached' => 0];
        }

        $bucket[$key]['total']++;
        if ($row['met'] === true) {
            $bucket[$key]['met']++;
            return;
        }

        $bucket[$key]['breached']++;
    }//end accumulate()

    /**
     * Finalise buckets by computing per-bucket attainment.
     *
     * @param array<string, array<string, mixed>> $buckets The raw buckets.
     *
     * @return array<string, array<string, mixed>> The finalised buckets.
     */
    private function finaliseBuckets(array $buckets): array
    {
        foreach ($buckets as $key => $bucket) {
            $total      = (int) $bucket['total'];
            $attainment = 0.0;
            if ($total > 0) {
                $attainment = round(((int) $bucket['met']) / $total, 4);
            }

            $buckets[$key]['attainment'] = $attainment;
        }

        return $buckets;
    }//end finaliseBuckets()

    /**
     * Map a groupBy dimension to its row field.
     *
     * @param string $groupBy The grouping dimension.
     *
     * @return string The row field name.
     */
    private function groupField(string $groupBy): string
    {
        $map = ['policy' => 'policyId', 'customer' => 'customer', 'tier' => 'tier', 'team' => 'team'];
        return ($map[$groupBy] ?? 'policyId');
    }//end groupField()

    /**
     * Resolve the time window for a bucket spec.
     *
     * @param array<string, mixed> $params The query params.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} [from, to].
     */
    private function resolveWindow(array $params): array
    {
        try {
            $window = $this->windowForBucket(params: $params);
            if ($window !== null) {
                return $window;
            }
        } catch (Exception $e) {
            $this->logger->warning('SlaAttainmentService: invalid bucket spec, using wide window', ['params' => $params]);
        }

        // Default: a very wide window so the report is non-empty without a spec.
        return [new DateTimeImmutable('2000-01-01T00:00:00+00:00'), new DateTimeImmutable('2100-01-01T00:00:00+00:00')];
    }//end resolveWindow()

    /**
     * Resolve the explicit [from, to) window for a recognised bucket spec.
     *
     * @param array<string, mixed> $params The query params.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}|null The window, or null when unspecified.
     *
     * @throws Exception On a malformed date/quarter spec.
     */
    private function windowForBucket(array $params): ?array
    {
        $bucket = (string) ($params['bucket'] ?? 'quarter');

        if ($bucket === 'day' && isset($params['date']) === true) {
            $from = new DateTimeImmutable($params['date'].'T00:00:00+00:00');
            return [$from, $from->modify('+1 day')];
        }

        if ($bucket === 'month' && isset($params['month']) === true) {
            $from = new DateTimeImmutable($params['month'].'-01T00:00:00+00:00');
            return [$from, $from->modify('+1 month')];
        }

        if ($bucket === 'week' && isset($params['week']) === true) {
            $from = (new DateTimeImmutable())->setISODate(
                (int) substr($params['week'], 0, 4),
                (int) substr($params['week'], 6)
            )->setTime(0, 0);
            return [$from, $from->modify('+1 week')];
        }

        if ($bucket === 'quarter' && isset($params['quarter']) === true) {
            return $this->quarterWindow(spec: (string) $params['quarter']);
        }

        return null;
    }//end windowForBucket()

    /**
     * Resolve a YYYY-Qn quarter spec to a [from, to) window.
     *
     * @param string $spec The quarter spec (e.g. 2026-Q2).
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} [from, to].
     *
     * @throws Exception On an invalid spec.
     */
    private function quarterWindow(string $spec): array
    {
        $year    = (int) substr($spec, 0, 4);
        $quarter = (int) substr($spec, 6, 1);
        $month   = ((($quarter - 1) * 3) + 1);
        $from    = new DateTimeImmutable(sprintf('%04d-%02d-01T00:00:00+00:00', $year, $month));
        return [$from, $from->modify('+3 months')];
    }//end quarterWindow()

    /**
     * Load all SLA-tracked objects across configured types.
     *
     * @return array<int, array<string, mixed>> The tracked objects.
     */
    private function loadTrackedObjects(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        if ($register === '') {
            return [];
        }

        $types  = array_values(
            array_filter(
                array_map('trim', explode(',', $this->appConfig->getValueString(Application::APP_ID, 'sla_tracked_types', 'request,complaint')))
            )
        );
        $result = [];

        foreach ($types as $type) {
            $schemaKey = (['request' => 'request_schema', 'complaint' => 'complaint_schema'][$type] ?? ($type.'_schema'));
            $schema    = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
            if ($schema === '') {
                continue;
            }

            try {
                $items = $this->objectService()->findAll(['filters' => ['register' => $register, 'schema' => $schema]]);
            } catch (Throwable $e) {
                $this->logger->error('SlaAttainmentService: load failed', ['type' => $type, 'exception' => $e->getMessage()]);
                continue;
            }

            foreach ($items as $item) {
                $result[] = $this->toArray(object: $item);
            }
        }//end foreach

        return $result;
    }//end loadTrackedObjects()

    /**
     * Whether the tracked object is still open (not in a terminal status).
     *
     * @param array<string, mixed> $object The tracked object.
     *
     * @return bool True when open.
     */
    private function isObjectOpen(array $object): bool
    {
        $terminal = ['completed', 'converted', 'rejected', 'resolved', 'afgerond'];
        return (in_array((string) ($object['status'] ?? ''), $terminal, true) === false);
    }//end isObjectOpen()

    /**
     * Normalise a tier value (empty => bronze).
     *
     * @param string $tier The raw tier.
     *
     * @return string The normalised tier.
     */
    private function normaliseTier(string $tier): string
    {
        $tier = strtolower(trim($tier));
        if (in_array($tier, ['bronze', 'silver', 'gold', 'platinum'], true) === true) {
            return $tier;
        }

        return 'bronze';
    }//end normaliseTier()

    /**
     * Convert a value to a DateTimeImmutable or null.
     *
     * @param mixed $value The candidate value.
     *
     * @return DateTimeImmutable|null The parsed date.
     */
    private function toDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable((string) $value);
        } catch (Exception $e) {
            return null;
        }
    }//end toDate()

    /**
     * Lazily resolve the OpenRegister ObjectService.
     *
     * @return object The ObjectService instance.
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
    }//end objectService()

    /**
     * Normalise an ObjectEntity-or-array to a plain array.
     *
     * @param mixed $object The raw object.
     *
     * @return array<string, mixed> The array form.
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialised = $object->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        return [];
    }//end toArray()
}//end class
