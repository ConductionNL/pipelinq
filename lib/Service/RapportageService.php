<?php

/**
 * Pipelinq RapportageService.
 *
 * Server-side aggregation of lead/pipeline analytics: stage value summary,
 * lead-source performance, lead aging distribution and win/loss analysis.
 * Reads leads from OpenRegister through the ObjectService and computes
 * read-only summaries in PHP. No object mutations are performed here.
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
 * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-006
 * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-007
 * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Aggregation service for lead pipeline analytics.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class aggregates four
 *                                                   cohesive analytics readouts
 *                                                   (stage value, source
 *                                                   performance, aging, win/loss)
 *                                                   over the same lead dataset;
 *                                                   each method is individually
 *                                                   under the per-method
 *                                                   thresholds.
 *
 * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-006
 */
class RapportageService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (OpenRegister lookup).
     * @param IAppConfig         $appConfig The app configuration.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Aggregate all analytics into a single response payload.
     *
     * @param string|null $pipelineId Optional pipeline filter.
     * @param string|null $dateFrom   Optional closed-from date (ISO 8601).
     * @param string|null $dateTo     Optional closed-to date (ISO 8601).
     *
     * @return array<string, mixed> The combined analytics payload.
     *
     * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-006
     */
    public function getPipelineStats(
        ?string $pipelineId=null,
        ?string $dateFrom=null,
        ?string $dateTo=null
    ): array {
        return [
            'stageValues'       => $this->getStageValues(pipelineId: $pipelineId),
            'sourcePerformance' => $this->getSourcePerformance(dateFrom: $dateFrom, dateTo: $dateTo),
            'agingBuckets'      => $this->getAgingBuckets(),
            'winLoss'           => $this->getWinLossAnalysis(dateFrom: $dateFrom, dateTo: $dateTo),
        ];
    }//end getPipelineStats()

    /**
     * Total and probability-weighted lead value grouped by stage.
     *
     * @param string|null $pipelineId Optional pipeline filter.
     *
     * @return array<int, array<string, mixed>> One entry per stage.
     *
     * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-006
     */
    public function getStageValues(?string $pipelineId=null): array
    {
        $leads = $this->fetchOpenLeads();

        $byStage = [];
        foreach ($leads as $lead) {
            if ($pipelineId !== null && $pipelineId !== '' && (string) ($lead['pipeline'] ?? '') !== $pipelineId) {
                continue;
            }

            $stage = (string) ($lead['stage'] ?? '');
            if ($stage === '') {
                $stage = 'Onbekend';
            }

            if (isset($byStage[$stage]) === false) {
                $byStage[$stage] = [
                    'stage'         => $stage,
                    'count'         => 0,
                    'totalValue'    => 0.0,
                    'weightedValue' => 0.0,
                ];
            }

            $value       = (float) ($lead['value'] ?? 0);
            $probability = (float) ($lead['probability'] ?? 0);

            $byStage[$stage]['count']         += 1;
            $byStage[$stage]['totalValue']    += $value;
            $byStage[$stage]['weightedValue'] += ($value * ($probability / 100));
        }//end foreach

        return array_values($byStage);
    }//end getStageValues()

    /**
     * Conversion metrics grouped by lead source.
     *
     * @param string|null $dateFrom Optional closed-from date (ISO 8601).
     * @param string|null $dateTo   Optional closed-to date (ISO 8601).
     *
     * @return array<int, array<string, mixed>> One entry per source.
     *
     * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-007
     */
    public function getSourcePerformance(?string $dateFrom=null, ?string $dateTo=null): array
    {
        $leads = $this->fetchAllLeads();

        $bySource = [];
        foreach ($leads as $lead) {
            $status = (string) ($lead['status'] ?? '');
            if ($this->withinRange(lead: $lead, status: $status, dateFrom: $dateFrom, dateTo: $dateTo) === false) {
                continue;
            }

            $source = (string) ($lead['source'] ?? '');
            if ($source === '') {
                $source = 'onbekend';
            }

            if (isset($bySource[$source]) === false) {
                $bySource[$source] = [
                    'source'         => $source,
                    'total'          => 0,
                    'won'            => 0,
                    'conversionRate' => 0.0,
                    'avgWonValue'    => null,
                    '_wonValueSum'   => 0.0,
                ];
            }

            $bySource[$source]['total'] += 1;
            if ($status === 'won') {
                $bySource[$source]['won']          += 1;
                $bySource[$source]['_wonValueSum'] += (float) ($lead['value'] ?? 0);
            }
        }//end foreach

        $result = [];
        foreach ($bySource as $row) {
            $total = (int) $row['total'];
            $won   = (int) $row['won'];

            $conversionRate = 0.0;
            if ($total > 0) {
                $conversionRate = round((($won / $total) * 100), 1);
            }

            $avgWonValue = null;
            if ($won > 0) {
                $avgWonValue = round(($row['_wonValueSum'] / $won), 2);
            }

            $row['conversionRate'] = $conversionRate;
            $row['avgWonValue']    = $avgWonValue;
            unset($row['_wonValueSum']);

            $result[] = $row;
        }

        return $result;
    }//end getSourcePerformance()

    /**
     * Distribution of open leads across age buckets, by `@self.updated`.
     *
     * @return array<int, array<string, mixed>> The four aging buckets.
     *
     * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-006
     */
    public function getAgingBuckets(): array
    {
        $buckets = [
            '0-7d'   => ['bucket' => '0-7d', 'count' => 0, 'totalValue' => 0.0],
            '8-14d'  => ['bucket' => '8-14d', 'count' => 0, 'totalValue' => 0.0],
            '15-30d' => ['bucket' => '15-30d', 'count' => 0, 'totalValue' => 0.0],
            '30d+'   => ['bucket' => '30d+', 'count' => 0, 'totalValue' => 0.0],
        ];

        foreach ($this->fetchOpenLeads() as $lead) {
            $age   = $this->daysSince(epoch: $this->modifiedAt(lead: $lead));
            $value = (float) ($lead['value'] ?? 0);
            $key   = $this->bucketKey(age: $age);

            $buckets[$key]['count']      += 1;
            $buckets[$key]['totalValue'] += $value;
        }

        return array_values($buckets);
    }//end getAgingBuckets()

    /**
     * Map an age in days to its aging-bucket key.
     *
     * @param int $age Age in whole days.
     *
     * @return string The bucket key.
     */
    private function bucketKey(int $age): string
    {
        if ($age <= 7) {
            return '0-7d';
        }

        if ($age <= 14) {
            return '8-14d';
        }

        if ($age <= 30) {
            return '15-30d';
        }

        return '30d+';
    }//end bucketKey()

    /**
     * Win/loss summary across closed leads in the optional date range.
     *
     * @param string|null $dateFrom Optional closed-from date (ISO 8601).
     * @param string|null $dateTo   Optional closed-to date (ISO 8601).
     *
     * @return array<string, mixed> Win rate, counts and averages.
     *
     * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-008
     */
    public function getWinLossAnalysis(?string $dateFrom=null, ?string $dateTo=null): array
    {
        $leads = $this->fetchAllLeads();

        $acc = [
            'wonCount'     => 0,
            'lostCount'    => 0,
            'wonValueSum'  => 0.0,
            'lostValueSum' => 0.0,
            'daysToClose'  => [],
        ];

        foreach ($leads as $lead) {
            $status = (string) ($lead['status'] ?? '');
            if ($status !== 'won' && $status !== 'lost') {
                continue;
            }

            if ($this->withinRange(lead: $lead, status: $status, dateFrom: $dateFrom, dateTo: $dateTo) === false) {
                continue;
            }

            $acc = $this->tallyClosedLead(acc: $acc, lead: $lead, status: $status);
        }

        $wonCount     = $acc['wonCount'];
        $lostCount    = $acc['lostCount'];
        $wonValueSum  = $acc['wonValueSum'];
        $lostValueSum = $acc['lostValueSum'];
        $daysToClose  = $acc['daysToClose'];

        $total = ($wonCount + $lostCount);

        $winRate = 0.0;
        if ($total > 0) {
            $winRate = round((($wonCount / $total) * 100), 1);
        }

        $avgWonValue = 0.0;
        if ($wonCount > 0) {
            $avgWonValue = round(($wonValueSum / $wonCount), 2);
        }

        $avgLostValue = 0.0;
        if ($lostCount > 0) {
            $avgLostValue = round(($lostValueSum / $lostCount), 2);
        }

        $avgDaysToClose = 0;
        if (count($daysToClose) > 0) {
            $avgDaysToClose = (int) round(array_sum($daysToClose) / count($daysToClose));
        }

        return [
            'wonCount'       => $wonCount,
            'lostCount'      => $lostCount,
            'winRate'        => $winRate,
            'avgWonValue'    => $avgWonValue,
            'avgLostValue'   => $avgLostValue,
            'avgDaysToClose' => $avgDaysToClose,
        ];
    }//end getWinLossAnalysis()

    /**
     * Add a single closed lead's contribution to the win/loss accumulator.
     *
     * @param array<string, mixed> $acc    The running accumulator.
     * @param array<string, mixed> $lead   The closed lead.
     * @param string               $status The lead status (won|lost).
     *
     * @return array<string, mixed> The updated accumulator.
     */
    private function tallyClosedLead(array $acc, array $lead, string $status): array
    {
        $value = (float) ($lead['value'] ?? 0);

        if ($status === 'lost') {
            $acc['lostCount']    += 1;
            $acc['lostValueSum'] += $value;
            return $acc;
        }

        $acc['wonCount']    += 1;
        $acc['wonValueSum'] += $value;

        $days = $this->daysToClose(lead: $lead);
        if ($days !== null) {
            $acc['daysToClose'][] = $days;
        }

        return $acc;
    }//end tallyClosedLead()

    /**
     * Whole days between a won lead's creation and its closing.
     *
     * @param array<string, mixed> $lead The won lead.
     *
     * @return int|null Days to close, or null when timestamps are unknown.
     */
    private function daysToClose(array $lead): ?int
    {
        $created = $this->createdAt(lead: $lead);
        $closed  = $this->modifiedAt(lead: $lead);
        if ($created === null || $closed === null) {
            return null;
        }

        $span = ($closed - $created);
        if ($span < 0) {
            return null;
        }

        return (int) floor($span / 86400);
    }//end daysToClose()

    /**
     * Fetch every lead in the configured register/schema.
     *
     * @return array<int, array<string, mixed>> The leads as plain arrays.
     */
    private function fetchAllLeads(): array
    {
        [$register, $schema] = $this->config();

        try {
            $results = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register' => $register,
                        'schema'   => $schema,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq: failed to fetch leads for analytics', ['exception' => $e->getMessage()]);
            return [];
        }

        $leads = [];
        foreach (($results ?? []) as $result) {
            $leads[] = $this->toArray(object: $result);
        }

        return $leads;
    }//end fetchAllLeads()

    /**
     * Fetch leads that are not closed (status not won/lost).
     *
     * @return array<int, array<string, mixed>> The open leads.
     */
    private function fetchOpenLeads(): array
    {
        $open = [];
        foreach ($this->fetchAllLeads() as $lead) {
            $status = (string) ($lead['status'] ?? '');
            if ($status !== 'won' && $status !== 'lost') {
                $open[] = $lead;
            }
        }

        return $open;
    }//end fetchOpenLeads()

    /**
     * Whether a lead's close date falls within the optional range. Open
     * leads (no close) always pass; closed leads are bounded by the dates.
     *
     * @param array<string, mixed> $lead     The lead.
     * @param string               $status   The lead status.
     * @param string|null          $dateFrom Optional ISO 8601 from date.
     * @param string|null          $dateTo   Optional ISO 8601 to date.
     *
     * @return bool True when the lead is in range.
     */
    private function withinRange(array $lead, string $status, ?string $dateFrom, ?string $dateTo): bool
    {
        if (($dateFrom === null || $dateFrom === '') && ($dateTo === null || $dateTo === '')) {
            return true;
        }

        if ($status !== 'won' && $status !== 'lost') {
            return true;
        }

        $closed = $this->modifiedAt(lead: $lead);
        if ($closed === null) {
            return true;
        }

        if ($this->afterBound(closed: $closed, bound: $dateFrom, isLower: true) === false) {
            return false;
        }

        return $this->afterBound(closed: $closed, bound: $dateTo, isLower: false);
    }//end withinRange()

    /**
     * Check a closing timestamp against a single optional date boundary.
     *
     * @param int         $closed  The closing epoch seconds.
     * @param string|null $bound   The boundary date (ISO 8601) or null.
     * @param bool        $isLower True for the lower bound, false for upper.
     *
     * @return bool True when the timestamp satisfies the boundary.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) — $isLower selects bound side.
     */
    private function afterBound(int $closed, ?string $bound, bool $isLower): bool
    {
        if ($bound === null || $bound === '') {
            return true;
        }

        $boundary = strtotime($bound);
        if ($boundary === false) {
            return true;
        }

        if ($isLower === true) {
            return ($closed >= $boundary);
        }

        return ($closed <= $boundary);
    }//end afterBound()

    /**
     * Resolve the `@self.updated` timestamp (epoch seconds) of a lead.
     *
     * @param array<string, mixed> $lead The lead.
     *
     * @return int|null Epoch seconds, or null when unknown.
     */
    private function modifiedAt(array $lead): ?int
    {
        $self = [];
        if (isset($lead['@self']) === true && is_array($lead['@self']) === true) {
            $self = $lead['@self'];
        }

        $raw = ($self['updated'] ?? ($self['dateModified'] ?? ($lead['_dateModified'] ?? null)));
        if ($raw === null || $raw === '') {
            return null;
        }

        $stamp = strtotime((string) $raw);
        if ($stamp === false) {
            return null;
        }

        return $stamp;
    }//end modifiedAt()

    /**
     * Resolve the `@self.created` timestamp (epoch seconds) of a lead.
     *
     * @param array<string, mixed> $lead The lead.
     *
     * @return int|null Epoch seconds, or null when unknown.
     */
    private function createdAt(array $lead): ?int
    {
        $self = [];
        if (isset($lead['@self']) === true && is_array($lead['@self']) === true) {
            $self = $lead['@self'];
        }

        $raw = ($self['created'] ?? ($lead['_dateCreated'] ?? null));
        if ($raw === null || $raw === '') {
            return null;
        }

        $stamp = strtotime((string) $raw);
        if ($stamp === false) {
            return null;
        }

        return $stamp;
    }//end createdAt()

    /**
     * Whole days between the given epoch and now.
     *
     * @param int|null $epoch Epoch seconds, or null.
     *
     * @return int Days elapsed (0 when unknown).
     */
    private function daysSince(?int $epoch): int
    {
        if ($epoch === null) {
            return 0;
        }

        return (int) max(0, floor((time() - $epoch) / 86400));
    }//end daysSince()

    /**
     * Resolve the configured register and lead schema identifiers.
     *
     * @return array{0: string, 1: string} [register, schema].
     *
     * @throws RuntimeException When the register or lead schema is unconfigured.
     */
    private function config(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');

        if ($register === '' || $schema === '') {
            throw new RuntimeException('Lead register or schema is not configured.');
        }

        return [$register, $schema];
    }//end config()

    /**
     * Resolve the OpenRegister ObjectService from the container.
     *
     * @return object The ObjectService.
     *
     * @throws RuntimeException When OpenRegister is unavailable.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()

    /**
     * Normalise an OR object (entity or array) into a plain array.
     *
     * @param mixed $object The OR object.
     *
     * @return array<string, mixed> The object as an array.
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

        return (array) $object;
    }//end toArray()
}//end class
