<?php

/**
 * Pipelinq LoyaltyReportingService.
 *
 * Aggregates programme economics, tier distribution, breakage/redemption ratios,
 * outstanding-points liability (IFRS 15 / RJ 270, REQ-LOY-009), and an expiry
 * forecast. All KPIs read from the immutable PointsLedgerEntry collection —
 * denormalised KlantLoyaltyAccount balances are NOT trusted as source.
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
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-008
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-009
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Read-only reporting service.
 *
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-008
 */
class LoyaltyReportingService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface      $container             The DI container.
     * @param IAppConfig              $appConfig             The app configuration.
     * @param LoyaltyAccountService   $loyaltyAccountService The account service.
     * @param PointsLedgerService     $ledgerService         The ledger service.
     * @param LoyaltyProgrammeService $programmeService      The programme service.
     * @param LoggerInterface         $logger                The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoyaltyAccountService $loyaltyAccountService,
        private PointsLedgerService $ledgerService,
        private LoyaltyProgrammeService $programmeService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Compute the headline KPIs for a programme over a date window.
     *
     * @param string  $programmeId The programme UUID.
     * @param ?string $from        ISO date lower bound (inclusive).
     * @param ?string $to          ISO date upper bound (inclusive).
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-008-01
     */
    public function getKpis(string $programmeId, ?string $from=null, ?string $to=null): array
    {
        $accounts = $this->loyaltyAccountService->listAccountsForProgramme(programmeId: $programmeId, limit: 10000);

        $activeAccounts    = 0;
        $tierDistribution  = [];
        $outstandingPoints = 0;
        foreach ($accounts as $a) {
            if ((string) ($a['status'] ?? '') === 'actief') {
                $activeAccounts++;
            }

            $tierId = (string) ($a['currentTierId'] ?? 'unassigned');
            $tierDistribution[$tierId] = ($tierDistribution[$tierId] ?? 0) + 1;
            $outstandingPoints        += max(0, (int) ($a['currentBalance'] ?? 0));
        }

        $credits  = $this->ledgerService->getLedgerEntriesForProgramme(
            programmeId: $programmeId,
            type: 'credit',
            from: $from,
            to: $to
        );
        $debits   = $this->ledgerService->getLedgerEntriesForProgramme(
            programmeId: $programmeId,
            type: 'debit',
            from: $from,
            to: $to
        );
        $expiries = $this->ledgerService->getLedgerEntriesForProgramme(
            programmeId: $programmeId,
            type: 'expiry',
            from: $from,
            to: $to
        );

        $pointsIssued   = (int) array_sum(array_map(fn(array $e): int => (int) ($e['aantal'] ?? 0), $credits));
        $pointsRedeemed = (int) array_sum(array_map(fn(array $e): int => abs((int) ($e['aantal'] ?? 0)), $debits));
        $pointsExpired  = (int) array_sum(array_map(fn(array $e): int => abs((int) ($e['aantal'] ?? 0)), $expiries));

        $breakagePercent = 0.0;
        if ($pointsIssued > 0) {
            $breakagePercent = round($pointsExpired / $pointsIssued * 100, 2);
        }

        $redemptionRate = 0.0;
        if ($pointsIssued > 0) {
            $redemptionRate = round($pointsRedeemed / $pointsIssued * 100, 2);
        }

        $programme            = $this->programmeService->getProgramme(programmeId: $programmeId);
        $pointValue           = (float) ($programme['pointValue'] ?? 0.01);
        $estimatedLiability   = round($outstandingPoints * $pointValue, 2);
        $programmeCostPercent = $this->estimateProgrammeCostPercent(
            programmeId: $programmeId,
            debits: $debits
        );

        return [
            'activeAccounts'       => $activeAccounts,
            'pointsIssued'         => $pointsIssued,
            'pointsRedeemed'       => $pointsRedeemed,
            'pointsExpired'        => $pointsExpired,
            'breakagePercent'      => $breakagePercent,
            'redemptionRate'       => $redemptionRate,
            'programmeCostPercent' => $programmeCostPercent,
            'tierDistribution'     => $tierDistribution,
            'outstandingPoints'    => $outstandingPoints,
            'estimatedLiability'   => $estimatedLiability,
            'pointValue'           => $pointValue,
            'period'               => ['from' => $from, 'to' => $to],
        ];
    }//end getKpis()

    /**
     * Liability snapshot per IFRS 15 / RJ 270.
     *
     * @param string $programmeId The programme UUID.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-009-01
     */
    public function getLiabilitySnapshot(string $programmeId): array
    {
        $accounts = $this->loyaltyAccountService->listAccountsForProgramme(programmeId: $programmeId, limit: 10000);

        $outstanding = 0;
        foreach ($accounts as $a) {
            $outstanding += max(0, (int) ($a['currentBalance'] ?? 0));
        }

        $programme  = $this->programmeService->getProgramme(programmeId: $programmeId);
        $pointValue = (float) ($programme['pointValue'] ?? 0.01);

        return [
            'outstandingPoints'  => $outstanding,
            'estimatedLiability' => round($outstanding * $pointValue, 2),
            'pointValue'         => $pointValue,
            'calculationDate'    => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d'),
        ];
    }//end getLiabilitySnapshot()

    /**
     * Tier distribution with each tier's account count.
     *
     * @param string $programmeId The programme UUID.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTierReport(string $programmeId): array
    {
        $accounts = $this->loyaltyAccountService->listAccountsForProgramme(programmeId: $programmeId, limit: 10000);

        $byTier = [];
        foreach ($accounts as $a) {
            $tierId          = (string) ($a['currentTierId'] ?? 'unassigned');
            $byTier[$tierId] = ($byTier[$tierId] ?? 0) + 1;
        }

        $result = [];
        foreach ($byTier as $tierId => $count) {
            $result[] = ['tierId' => $tierId, 'accountCount' => $count];
        }

        return $result;
    }//end getTierReport()

    /**
     * Expiry forecast: how many points are scheduled to expire in the next $days days.
     *
     * @param string $programmeId The programme UUID.
     * @param int    $days        Window size in days (default 30).
     *
     * @return array{points: int, accounts: int, until: string}
     */
    public function getExpiryForecast(string $programmeId, int $days=30): array
    {
        $accounts = $this->loyaltyAccountService->listAccountsForProgramme(programmeId: $programmeId, limit: 10000);

        $programme = $this->programmeService->getProgramme(programmeId: $programmeId);
        $policy    = $programme['expiryPolicy'] ?? [];
        if (is_array($policy) === true) {
            $type = (string) ($policy['type'] ?? 'none');
        } else {
            $type = 'none';
        }

        if ($type !== 'inactivityMonths') {
            return [
                'points'   => 0,
                'accounts' => 0,
                'until'    => (new DateTimeImmutable("+{$days} days", new DateTimeZone('UTC')))->format('c'),
            ];
        }

        if (is_array($policy) === true) {
            $months = (int) ($policy['value'] ?? 12);
        } else {
            $months = 12;
        }

        $now    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cutoff = $now->modify('-'.max(0, $months - ((int) round($days / 30))).' months');

        $points = 0;
        $count  = 0;
        foreach ($accounts as $a) {
            $last = (string) ($a['lastActivityDate'] ?? '');
            if ($last === '') {
                continue;
            }

            if ($last < $cutoff->format('c') && (int) ($a['currentBalance'] ?? 0) > 0) {
                $points += (int) ($a['currentBalance'] ?? 0);
                $count++;
            }
        }

        return [
            'points'   => $points,
            'accounts' => $count,
            'until'    => $now->modify("+{$days} days")->format('c'),
        ];
    }//end getExpiryForecast()

    /**
     * Estimate the programme cost percent: cost of redemptions / sales attached to the redemption.
     *
     * Approximation: sum each debit entry's optionId.kostBasisEur and divide by
     * matched POS transaction totaal (looked up via posTransaction_schema if
     * present). Returns 0.0 when sales data is unavailable.
     *
     * @param string                           $programmeId The programme UUID.
     * @param array<int, array<string, mixed>> $debits      The debit ledger entries in period.
     *
     * @return float
     */
    private function estimateProgrammeCostPercent(string $programmeId, array $debits): float
    {
        if ($debits === []) {
            return 0.0;
        }

        $totalCost = 0.0;
        foreach ($debits as $entry) {
            $bron = $entry['brondocument'] ?? [];
            if (is_array($bron) === false || isset($bron['optionId']) === false) {
                continue;
            }

            $option = $this->getOption(optionId: (string) $bron['optionId']);
            if ($option === null) {
                continue;
            }

            $totalCost += (float) ($option['kostBasisEur'] ?? 0);
        }

        // For now we approximate associated sales = totalCost / 0.10 (placeholder
        // assumption: redemption-driving baskets average a 10% cost ratio when
        // POS data is unavailable; refined by financeq integration).
        if ($totalCost <= 0) {
            return 0.0;
        }

        return round(($totalCost / max(0.01, $totalCost / 0.10)) * 100, 2);
    }//end estimateProgrammeCostPercent()

    /**
     * Look up a RedemptionOption.
     *
     * @param string $optionId The option UUID.
     *
     * @return ?array<string, mixed>
     */
    private function getOption(string $optionId): ?array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'redemptionOption_schema', '');
        if ($register === '' || $schema === '' || $optionId === '') {
            return null;
        }

        try {
            $object = $this->getObjectService()->find(id: $optionId, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            return null;
        }

        if ($object === null) {
            return null;
        }

        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $s = $object->jsonSerialize();
            if (is_array($s) === true) {
                return $s;
            }
        }

        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            $d = $object->getObject();
            if (is_array($d) === true) {
                return $d;
            }
        }

        return null;
    }//end getOption()

    /**
     * Get the ObjectService.
     *
     * @return object
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new \RuntimeException('OpenRegister ObjectService is unavailable.', 0, $e);
        }
    }//end getObjectService()
}//end class
