<?php

/**
 * Pipelinq PosStaffReportService.
 *
 * Server-side aggregator for the per-staff sales report
 * (pos-staff-pin-permissions REQ-PSP-008). Groups fiscally-final
 * posTransaction objects (status in confirmed / settled / refunded) by
 * staffMemberId and sums the persisted server-authoritative totals.
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
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use RuntimeException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service that aggregates posTransaction objects per staff member.
 *
 * Reads the persisted server-authoritative `total` / `totalTax` fields on
 * each posTransaction (these were written by PosTransactionService on
 * confirm), so the report is always consistent with the receipts and the
 * BTW report. Refunded transactions are netted out (negative contribution
 * via the persisted refund flag).
 *
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10.2
 */
class PosStaffReportService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container       The DI container.
     * @param IAppConfig         $appConfig       The app config.
     * @param PosStaffService    $posStaffService The POS staff service (for name lookup).
     * @param LoggerInterface    $logger          The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private PosStaffService $posStaffService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Build the per-staff sales aggregation report.
     *
     * @return array<int, array{staffMemberId: string, displayName: string, transactionCount: int, total: float, totalTax: float}> Per-staff rows.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10.2
     */
    public function staffSalesReport(): array
    {
        $rows = $this->fetchFinalTransactions();

        $byStaff = [];
        foreach ($rows as $tx) {
            $staffId = (string) ($tx['staffMemberId'] ?? '');
            if ($staffId === '') {
                continue;
            }

            if (isset($byStaff[$staffId]) === false) {
                $byStaff[$staffId] = [
                    'staffMemberId'    => $staffId,
                    'displayName'      => '',
                    'transactionCount' => 0,
                    'total'            => 0.0,
                    'totalTax'         => 0.0,
                ];
            }

            $sign = 1.0;
            if (($tx['status'] ?? '') === 'refunded') {
                $sign = -1.0;
            }

            $byStaff[$staffId]['transactionCount']++;
            $byStaff[$staffId]['total']    += ($sign * (float) ($tx['total'] ?? 0));
            $byStaff[$staffId]['totalTax'] += ($sign * (float) ($tx['totalTax'] ?? 0));
        }//end foreach

        // Resolve display names from posStaff. Failures fall back to the UUID.
        foreach ($byStaff as $staffId => $_row) {
            try {
                $staff = $this->posStaffService->getStaff(id: $staffId);
                $byStaff[$staffId]['displayName'] = (string) ($staff['displayName'] ?? $staffId);
            } catch (\Throwable $e) {
                $byStaff[$staffId]['displayName'] = $staffId;
            }
        }

        // Round monetary values to cents (server-authoritative).
        foreach ($byStaff as $staffId => $_row) {
            $byStaff[$staffId]['total']    = round($byStaff[$staffId]['total'], 2);
            $byStaff[$staffId]['totalTax'] = round($byStaff[$staffId]['totalTax'], 2);
        }

        return array_values($byStaff);
    }//end staffSalesReport()

    /**
     * Read posTransaction objects that count toward the report (final statuses).
     *
     * @return array<int, array<string, mixed>> The transactions as flat arrays.
     */
    private function fetchFinalTransactions(): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

        try {
            $results = $this->getObjectService()->findAll(
                register: $register,
                schema: $schema,
                limit: 5000
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: staff-sales report failed to load transactions',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        $allowed = ['confirmed', 'settled', 'refunded'];
        $out     = [];
        foreach ($results as $result) {
            $tx = $this->toArray(object: $result);
            if (in_array((string) ($tx['status'] ?? ''), $allowed, true) === false) {
                continue;
            }

            $out[] = $tx;
        }

        return $out;
    }//end fetchFinalTransactions()

    /**
     * Resolve the register + schema config key into stored IDs.
     *
     * @param string $schemaKey The app-config schema key.
     *
     * @return array{0: string, 1: string} The [register, schema] IDs.
     *
     * @throws OCSNotFoundException If the register or schema is not configured.
     */
    private function config(string $schemaKey): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

        if ($register === '' || $schema === '') {
            throw new OCSNotFoundException('POS register of schema is niet geconfigureerd.');
        }

        return [$register, $schema];
    }//end config()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object The object service.
     *
     * @throws RuntimeException If OpenRegister is not available.
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
     * Normalise an OR object into a plain array.
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
