<?php

/**
 * Pipelinq PosStaffReportService.
 *
 * Aggregates POS transactions by their attributed staff member to produce the
 * per-staff sales report and the shillinq commission feed. Only settled /
 * confirmed (non-draft, non-cancelled) transactions count toward sales totals.
 * The commission feed deliberately EXCLUDES transactions without a
 * staffMemberId (legacy / unattributed) and records each exclusion in the audit
 * log with its transaction id, per REQ-PSP-009.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for per-staff POS sales reporting and the shillinq commission feed.
 *
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10
 */
class PosStaffReportService
{
    /**
     * Transaction statuses that count toward sales totals.
     *
     * @var string[]
     */
    private const SALES_STATUSES = ['confirmed', 'settled'];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container   The DI container.
     * @param IAppConfig         $appConfig   The app config.
     * @param PosStaffService    $staffService The POS staff service.
     * @param LoggerInterface    $logger      The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private PosStaffService $staffService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Build the per-staff sales report.
     *
     * Groups confirmed / settled transactions by staffMemberId and totals the
     * transaction count and gross amount per staff member, resolving the staff
     * display name where available. Transactions without a staffMemberId are
     * grouped under an explicit "unattributed" bucket so the figures still
     * reconcile to the till.
     *
     * @return array<int, array{staffMemberId: string, displayName: string, transactionCount: int, total: float}>
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10
     */
    public function staffSalesReport(): array
    {
        $transactions = $this->fetchSalesTransactions();

        $rows = [];
        foreach ($transactions as $transaction) {
            $staffId = (string) ($transaction['staffMemberId'] ?? '');
            $key     = ($staffId !== '' ? $staffId : 'unattributed');

            if (isset($rows[$key]) === false) {
                $rows[$key] = [
                    'staffMemberId'    => $staffId,
                    'displayName'      => $this->resolveName(staffId: $staffId),
                    'transactionCount' => 0,
                    'total'            => 0.0,
                ];
            }

            $rows[$key]['transactionCount']++;
            $rows[$key]['total'] = round(($rows[$key]['total'] + (float) ($transaction['total'] ?? 0)), 2);
        }

        return array_values($rows);
    }//end staffSalesReport()

    /**
     * Build the shillinq commission feed from attributed transactions.
     *
     * Each feed record carries the staffMemberId, transaction amount and line
     * items. Transactions WITHOUT a staffMemberId are excluded and each exclusion
     * is recorded in the audit log with its transaction id (REQ-PSP-009).
     *
     * @return array<int, array{staffMemberId: string, transactionId: string, total: float, lines: array<int, array<string, mixed>>}>
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10
     */
    public function commissionFeed(): array
    {
        $transactions = $this->fetchSalesTransactions();

        $feed = [];
        foreach ($transactions as $transaction) {
            $transactionId = (string) ($transaction['id'] ?? $transaction['uuid'] ?? '');
            $staffId       = (string) ($transaction['staffMemberId'] ?? '');

            if ($staffId === '') {
                $this->logger->info(
                    'Pipelinq: transaction excluded from commission feed (no staff attribution)',
                    ['transactionId' => $transactionId]
                );
                continue;
            }

            $feed[] = [
                'staffMemberId' => $staffId,
                'transactionId' => $transactionId,
                'total'         => (float) ($transaction['total'] ?? 0),
                'lines'         => $this->fetchLines(transactionId: $transactionId),
            ];
        }

        return $feed;
    }//end commissionFeed()

    /**
     * Resolve a staff member's display name, falling back to the id / a label.
     *
     * @param string $staffId The staff UUID.
     *
     * @return string The display name.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10
     */
    private function resolveName(string $staffId): string
    {
        if ($staffId === '') {
            return 'Niet toegewezen';
        }

        try {
            $staff = $this->staffService->getStaff(id: $staffId);
            $name  = trim((string) ($staff['displayName'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: could not resolve staff name for sales report',
                ['staffId' => $staffId, 'exception' => $e->getMessage()]
            );
        }

        return $staffId;
    }//end resolveName()

    /**
     * Fetch the confirmed / settled transactions in this app's schema.
     *
     * @return array<int, array<string, mixed>> The transactions.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10
     */
    private function fetchSalesTransactions(): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

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
            $this->logger->warning('Pipelinq: failed to fetch transactions for staff report', ['exception' => $e->getMessage()]);
            return [];
        }

        $transactions = [];
        foreach (($results ?? []) as $result) {
            $transaction = $this->toArray(object: $result);
            if (in_array((string) ($transaction['status'] ?? ''), self::SALES_STATUSES, true) === true) {
                $transactions[] = $transaction;
            }
        }

        return $transactions;
    }//end fetchSalesTransactions()

    /**
     * Fetch the lines belonging to a transaction.
     *
     * @param string $transactionId The parent transaction UUID.
     *
     * @return array<int, array<string, mixed>> The transaction lines.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10
     */
    private function fetchLines(string $transactionId): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posTransactionLine_schema');

        try {
            $results = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register'    => $register,
                        'schema'      => $schema,
                        'transaction' => $transactionId,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq: failed to fetch lines for commission feed', ['exception' => $e->getMessage()]);
            return [];
        }

        $lines = [];
        foreach (($results ?? []) as $result) {
            $lines[] = $this->toArray(object: $result);
        }

        return $lines;
    }//end fetchLines()

    /**
     * Resolve the register + a schema config key into their stored IDs.
     *
     * @param string $schemaKey The app-config schema key.
     *
     * @return array{0: string, 1: string} The [register, schema] IDs.
     *
     * @throws OCSNotFoundException If the register or schema is not configured.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10
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
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10
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
