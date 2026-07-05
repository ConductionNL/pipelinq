<?php

/**
 * Pipelinq RenewalEngineService.
 *
 * Drives the contract renewal lifecycle:
 *   - detects when an `active` contract enters its renewal window and flips it
 *     to `expiring` (the only path to that status — guarded by ContractService)
 *   - creates exactly one renewal lead in the existing pipeline (lead-management),
 *     linked bidirectionally via renewalLeadRef
 *   - reconciles won/lost renewal leads and silent expiry into renewed/churned
 *   - drafts a successor contract on a won renewal
 *   - creates a notice-deadline My Work entry for the owner
 *
 * Idempotent: a contract that already carries a renewalLeadRef is never
 * re-transitioned or re-leaded; the notice-reminder entry is created once.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Renewal-window detection + renewal-lead automation engine.
 *
 * @spec openspec/changes/contract-renewal-tracking/specs/contract-renewal-tracking/spec.md#requirement-renewal-window-detection
 * @spec openspec/changes/contract-renewal-tracking/specs/contract-renewal-tracking/spec.md#requirement-renewal-lead-automation
 */
class RenewalEngineService
{
    /**
     * Hard fallback renewal lead time (days) when nothing is configured.
     *
     * @var int
     */
    private const FALLBACK_LEAD_TIME_DAYS = 60;

    /**
     * Constructor.
     *
     * @param IAppConfig         $appConfig       The app config.
     * @param ContainerInterface $container       The DI container (ObjectService lookup).
     * @param ContractService    $contractService The contract lifecycle service.
     * @param LoggerInterface    $logger          The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private ContractService $contractService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * The admin-configured default renewal lead time, floored at the fallback.
     *
     * @return int Days.
     *
     * @spec openspec/changes/contract-renewal-tracking/specs/contract-renewal-tracking/spec.md#requirement-renewal-window-detection
     */
    public function getDefaultLeadTimeDays(): int
    {
        $configured = (int) $this->appConfig->getValueString(
            Application::APP_ID,
            'renewal_default_lead_time_days',
            (string) self::FALLBACK_LEAD_TIME_DAYS
        );

        return max($configured, self::FALLBACK_LEAD_TIME_DAYS);
    }//end getDefaultLeadTimeDays()

    /**
     * The renewal window width for a contract (the larger of noticePeriodDays
     * and the configured default lead time, fallback 60).
     *
     * @param array<string,mixed> $contract The contract.
     *
     * @return int The window width in days.
     *
     * @spec openspec/changes/contract-renewal-tracking/specs/contract-renewal-tracking/spec.md#requirement-renewal-window-detection
     */
    public function renewalWindowDays(array $contract): int
    {
        $notice = (int) ($contract['noticePeriodDays'] ?? 0);
        return max($notice, $this->getDefaultLeadTimeDays());
    }//end renewalWindowDays()

    /**
     * Whether a contract has entered its renewal window on a given date.
     *
     * True when status === 'active', there is an endDate, and
     * today >= endDate − renewalWindowDays.
     *
     * @param array<string,mixed> $contract The contract.
     * @param string              $today    ISO date (Y-m-d) of evaluation.
     *
     * @return bool
     *
     * @spec openspec/changes/contract-renewal-tracking/specs/contract-renewal-tracking/spec.md#requirement-renewal-window-detection
     */
    public function isInRenewalWindow(array $contract, string $today): bool
    {
        if ((string) ($contract['status'] ?? '') !== 'active') {
            return false;
        }

        $endDate = (string) ($contract['endDate'] ?? '');
        if ($endDate === '') {
            return false;
        }

        $windowOpen = $this->dateMinusDays(
            isoDate: $endDate,
            days: $this->renewalWindowDays(contract: $contract)
        );
        return ($windowOpen !== '' && $today >= $windowOpen);
    }//end isInRenewalWindow()

    /**
     * Annualize a contract's recurring value (for the renewal-lead value).
     *
     * @param array<string,mixed> $contract The contract.
     *
     * @return float The annualized value.
     *
     * @spec openspec/changes/contract-renewal-tracking/specs/contract-renewal-tracking/spec.md#requirement-renewal-lead-automation
     */
    public function annualizedValue(array $contract): float
    {
        $value = (float) ($contract['valuePerInterval'] ?? 0);
        switch ((string) ($contract['billingInterval'] ?? '')) {
            case 'monthly':
                return round(($value * 12.0), 2);
            case 'quarterly':
                return round(($value * 4.0), 2);
            case 'annual':
            case 'one-off':
            default:
                return round($value, 2);
        }
    }//end annualizedValue()

    /**
     * Build the renewal-lead payload for a contract.
     *
     * @param array<string,mixed> $contract The contract entering its window.
     *
     * @return array<string,mixed> The lead payload.
     *
     * @spec openspec/changes/contract-renewal-tracking/specs/contract-renewal-tracking/spec.md#requirement-renewal-lead-automation
     */
    public function buildRenewalLead(array $contract): array
    {
        return [
            'title'       => sprintf('Renewal: %s', (string) ($contract['title'] ?? '')),
            'client'      => (string) ($contract['clientRef'] ?? ''),
            'value'       => $this->annualizedValue(contract: $contract),
            'assignee'    => (string) ($contract['ownerId'] ?? ''),
            'status'      => 'open',
            'tags'        => ['renewal'],
            'source'      => 'contract-renewal',
            'contractRef' => (string) ($contract['id'] ?? ($contract['uuid'] ?? '')),
        ];
    }//end buildRenewalLead()

    /**
     * Process a single contract for the nightly job. Pure orchestration over
     * the injected services; safe to call repeatedly (idempotent).
     *
     * @param array<string,mixed> $contract The contract object.
     * @param string              $today    ISO date (Y-m-d) of evaluation.
     *
     * @return string A short action code: 'expiring', 'churned-silent', 'noticed', or 'noop'.
     *
     * @spec openspec/changes/contract-renewal-tracking/specs/contract-renewal-tracking/spec.md#requirement-renewal-window-detection
     * @spec openspec/changes/contract-renewal-tracking/specs/contract-renewal-tracking/spec.md#requirement-renewal-lead-automation
     */
    public function processContract(array $contract, string $today): string
    {
        $uuid = (string) ($contract['id'] ?? ($contract['uuid'] ?? ''));

        // Silent expiry: an expiring contract whose endDate has passed and whose
        // renewal lead never resolved -> churned.
        if ((string) ($contract['status'] ?? '') === 'expiring') {
            return $this->processExpiringContract(contract: $contract, today: $today, uuid: $uuid);
        }

        // Window detection: flip active -> expiring and create the renewal lead.
        if ($this->isInRenewalWindow(contract: $contract, today: $today) === true
            && ((string) ($contract['renewalLeadRef'] ?? '')) === ''
        ) {
            $leadUuid           = $this->createRenewalLead(contract: $contract);
            $contract['status'] = 'expiring';
            $contract['renewalLeadRef'] = $leadUuid;
            // Guarded transition (engine path).
            $this->contractService->assertTransitionAllowed($contract, 'expiring', true);
            $this->contractService->save($contract, $uuid);
            return 'expiring';
        }

        return 'noop';
    }//end processContract()

    /**
     * Handle an already-`expiring` contract: silent-churn detection and the
     * idempotent notice-deadline My Work entry.
     *
     * @param array<string,mixed> $contract The contract object.
     * @param string              $today    ISO date (Y-m-d) of evaluation.
     * @param string              $uuid     The contract UUID.
     *
     * @return string A short action code: 'churned-silent', 'noticed', or 'noop'.
     */
    private function processExpiringContract(array $contract, string $today, string $uuid): string
    {
        $endDate = (string) ($contract['endDate'] ?? '');
        $outcome = (string) ($contract['renewalLeadOutcome'] ?? '');
        if ($endDate !== '' && $today > $endDate && $outcome === '') {
            $this->transition(contract: $contract, newStatus: 'churned', uuid: $uuid);
            return 'churned-silent';
        }

        // Notice-deadline My Work entry (idempotent).
        if ((bool) ($contract['noticeReminderSent'] ?? false) === false) {
            $deadline = $this->dateMinusDays(
                isoDate: $endDate,
                days: (int) ($contract['noticePeriodDays'] ?? 0)
            );
            if ($deadline !== '' && $today >= $deadline) {
                $this->createNoticeMyWorkEntry(contract: $contract);
                $contract['noticeReminderSent'] = true;
                $this->contractService->save($contract, $uuid);
                return 'noticed';
            }
        }

        return 'noop';
    }//end processExpiringContract()

    /**
     * Reconcile a contract against the outcome of its renewal lead.
     *
     * Won  -> renewed + drafted successor.
     * Lost -> churned.
     *
     * @param array<string,mixed> $contract The expiring contract (renewalLeadOutcome set).
     *
     * @return string 'renewed', 'churned', or 'noop'.
     *
     * @spec openspec/changes/contract-renewal-tracking/specs/contract-renewal-tracking/spec.md#requirement-renewal-lead-automation
     */
    public function reconcile(array $contract): string
    {
        $uuid    = (string) ($contract['id'] ?? ($contract['uuid'] ?? ''));
        $outcome = (string) ($contract['renewalLeadOutcome'] ?? '');

        if ($outcome === 'won') {
            $this->transition(contract: $contract, newStatus: 'renewed', uuid: $uuid);
            $successor = $this->contractService->buildSuccessorDraft($contract);
            $this->contractService->save($successor, null);
            return 'renewed';
        }

        if ($outcome === 'lost') {
            $this->transition(contract: $contract, newStatus: 'churned', uuid: $uuid);
            return 'churned';
        }

        return 'noop';
    }//end reconcile()

    /**
     * Apply a guarded status transition and persist it.
     *
     * @param array<string,mixed> $contract  The contract.
     * @param string              $newStatus The target status.
     * @param string              $uuid      The contract UUID.
     *
     * @return void
     */
    private function transition(array $contract, string $newStatus, string $uuid): void
    {
        $this->contractService->assertTransitionAllowed($contract, $newStatus, true);
        $contract['status'] = $newStatus;
        $this->contractService->save($contract, $uuid);
    }//end transition()

    /**
     * Create the renewal lead in the pipeline (lead-management) and return its UUID.
     *
     * @param array<string,mixed> $contract The contract.
     *
     * @return string The created lead UUID ('' on failure).
     */
    private function createRenewalLead(array $contract): string
    {
        $registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $leadSchema = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
        if ($registerId === '' || $leadSchema === '') {
            return '';
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $saved         = $objectService->saveObject(
                $this->buildRenewalLead(contract: $contract),
                [],
                $registerId,
                $leadSchema,
                null
            );
            $arr           = (array) $saved;
            if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
                $arr = $saved->jsonSerialize();
            }

            return (string) ($arr['id'] ?? ($arr['uuid'] ?? ''));
        } catch (Throwable $e) {
            $this->logger->error('RenewalEngineService: renewal-lead creation failed', ['error' => $e->getMessage()]);
            return '';
        }//end try
    }//end createRenewalLead()

    /**
     * Create the notice-deadline My Work (task) entry for the owner.
     *
     * @param array<string,mixed> $contract The contract.
     *
     * @return void
     */
    private function createNoticeMyWorkEntry(array $contract): void
    {
        $registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $taskSchema = $this->appConfig->getValueString(Application::APP_ID, 'task_schema', '');
        if ($registerId === '' || $taskSchema === '') {
            return;
        }

        $autoRenew = (bool) ($contract['autoRenew'] ?? false);
        $title     = sprintf(
            'Renewal decision due for contract %s',
            (string) ($contract['contractNumber'] ?? '')
        );
        if ($autoRenew === true) {
            $title = sprintf(
                'Contract %s renews automatically unless cancelled by the notice deadline',
                (string) ($contract['contractNumber'] ?? '')
            );
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $objectService->saveObject(
                [
                    'type'              => 'follow_up',
                    'subject'           => $title,
                    'status'            => 'open',
                    'assigneeUserId'    => (string) ($contract['ownerId'] ?? ''),
                    'relatedEntityType' => 'contract',
                    'relatedEntityId'   => (string) ($contract['id'] ?? ($contract['uuid'] ?? '')),
                ],
                [],
                $registerId,
                $taskSchema,
                null
            );
        } catch (Throwable $e) {
            $this->logger->error('RenewalEngineService: notice My Work entry failed', ['error' => $e->getMessage()]);
        }//end try
    }//end createNoticeMyWorkEntry()

    /**
     * Subtract a number of days from an ISO date, returning '' on parse failure.
     *
     * @param string $isoDate The ISO date (Y-m-d).
     * @param int    $days    The number of days to subtract.
     *
     * @return string The resulting ISO date, or '' if $isoDate is unparseable.
     */
    private function dateMinusDays(string $isoDate, int $days): string
    {
        if ($isoDate === '') {
            return '';
        }

        $timestamp = strtotime(sprintf('%s -%d days', $isoDate, $days));
        if ($timestamp === false) {
            return '';
        }

        return date('Y-m-d', $timestamp);
    }//end dateMinusDays()
}//end class
