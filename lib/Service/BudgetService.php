<?php

/**
 * Pipelinq BudgetService.
 *
 * Per-tenant, per-provider message-send budget enforcement and tracking.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Messaging\OrSerializeTrait;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Enforces and tracks message-send budgets (REQ-006).
 *
 * A `messageSendBudget` caps message volume and/or EUR cost per provider per
 * period. Hard-stop budgets refuse sends once a cap is reached; soft-limit
 * budgets allow the send but fire a single alert per period once the
 * alert-threshold fraction is crossed.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   — coordinates OR objects, config and notifications
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) — cap evaluation + period reset are inherently branchy
 * @spec                                             openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-5.1
 */
class BudgetService
{
    use OrSerializeTrait;

    /**
     * Constructor.
     *
     * @param ContainerInterface  $container           The DI container (resolves OpenRegister).
     * @param IAppConfig          $appConfig           The app config (register/schema ids).
     * @param NotificationService $notificationService The notification service for alerts.
     * @param IGroupManager       $groupManager        The group manager (resolves admins).
     * @param LoggerInterface     $logger              The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private NotificationService $notificationService,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether a send is permitted under the provider's budget.
     *
     * Only `hardStop` budgets can refuse a send. When no budget exists for the
     * provider, the send is permitted (REQ-006).
     *
     * @param string $providerId       The provider id.
     * @param float  $estimatedCostEur The estimated EUR cost of this send.
     *
     * @return bool True when the send is within budget (or unbudgeted/soft).
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-5.1
     */
    public function canSend(string $providerId, float $estimatedCostEur=0.0): bool
    {
        $budget = $this->budgetFor(providerId: $providerId);
        if ($budget === null) {
            return true;
        }

        if (($budget['hardStop'] ?? false) !== true) {
            return true;
        }

        return $this->withinCaps(budget: $budget, additionalCostEur: $estimatedCostEur);
    }//end canSend()

    /**
     * Record a successful send against the provider's budget counters.
     *
     * Increments the period message count and cost, then fires a soft-limit
     * alert exactly once per period when the threshold is crossed (REQ-006).
     *
     * @param string $providerId The provider id.
     * @param float  $costEur    The EUR cost to add (0 when unknown/estimated-later).
     *
     * @return void
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-5.1
     */
    public function recordSend(string $providerId, float $costEur=0.0): void
    {
        $budget = $this->budgetFor(providerId: $providerId);
        if ($budget === null) {
            return;
        }

        $messages = ((int) ($budget['currentPeriodMessages'] ?? 0) + 1);
        $cost     = ((float) ($budget['currentPeriodCostEur'] ?? 0) + $costEur);

        $patch = [
            'currentPeriodMessages' => $messages,
            'currentPeriodCostEur'  => $cost,
        ];

        $shouldAlert = ($this->crossedThreshold(budget: $budget, messages: $messages, cost: $cost) === true
            && ($budget['alertedAt'] ?? '') === '');
        if ($shouldAlert === true) {
            $patch['alertedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
        }

        $this->persist(budget: $budget, patch: $patch);

        if ($shouldAlert === true) {
            $this->notify(event: 'budget_alert', providerId: $providerId);
        }
    }//end recordSend()

    /**
     * Notify the administrator that a hard-stop budget refused a send.
     *
     * @param string $providerId The provider id whose budget was exceeded.
     *
     * @return void
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-5.2
     */
    public function notifyExceeded(string $providerId): void
    {
        $this->notify(event: 'budget_exceeded', providerId: $providerId);
    }//end notifyExceeded()

    /**
     * Reset period counters for budgets whose period has elapsed.
     *
     * @param \DateTimeImmutable $now The current time.
     *
     * @return int The number of budgets reset.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-5.4
     */
    public function resetElapsedPeriods(\DateTimeImmutable $now): int
    {
        $reset = 0;
        foreach ($this->allBudgets() as $budget) {
            $resetAt = (string) ($budget['periodResetAt'] ?? '');
            if ($resetAt === '') {
                continue;
            }

            try {
                $resetMoment = new DateTimeImmutable($resetAt);
            } catch (\Exception $e) {
                continue;
            }

            if ($resetMoment > $now) {
                continue;
            }

            $this->persist(
                budget: $budget,
                patch: [
                    'currentPeriodMessages' => 0,
                    'currentPeriodCostEur'  => 0,
                    'alertedAt'             => '',
                    'periodResetAt'         => $this->advance(from: $resetMoment, period: (string) ($budget['period'] ?? 'monthly'))->format('c'),
                ]
            );
            $reset++;
        }//end foreach

        return $reset;
    }//end resetElapsedPeriods()

    /**
     * Whether adding a send keeps the budget within both caps.
     *
     * @param array<string, mixed> $budget            The budget object.
     * @param float                $additionalCostEur The cost the new send would add.
     *
     * @return bool True when both caps still hold.
     */
    private function withinCaps(array $budget, float $additionalCostEur): bool
    {
        $maxMessages = (int) ($budget['maxMessages'] ?? 0);
        $messages    = (int) ($budget['currentPeriodMessages'] ?? 0);
        if ($maxMessages > 0 && ($messages + 1) > $maxMessages) {
            return false;
        }

        $maxCost = (float) ($budget['maxCostEur'] ?? 0);
        $cost    = (float) ($budget['currentPeriodCostEur'] ?? 0);
        if ($maxCost > 0 && ($cost + $additionalCostEur) > $maxCost) {
            return false;
        }

        return true;
    }//end withinCaps()

    /**
     * Whether the running totals have crossed the soft-alert threshold.
     *
     * @param array<string, mixed> $budget   The budget object.
     * @param int                  $messages The post-increment message count.
     * @param float                $cost     The post-increment cost.
     *
     * @return bool True when either cap's threshold fraction is reached.
     */
    private function crossedThreshold(array $budget, int $messages, float $cost): bool
    {
        $pct = (float) ($budget['alertThresholdPct'] ?? 0);
        if ($pct <= 0) {
            return false;
        }

        $maxMessages = (int) ($budget['maxMessages'] ?? 0);
        if ($maxMessages > 0 && $messages >= ($pct * $maxMessages)) {
            return true;
        }

        $maxCost = (float) ($budget['maxCostEur'] ?? 0);
        if ($maxCost > 0 && $cost >= ($pct * $maxCost)) {
            return true;
        }

        return false;
    }//end crossedThreshold()

    /**
     * Advance a reset moment by one budget period.
     *
     * @param \DateTimeImmutable $from   The current reset moment.
     * @param string             $period The period granularity.
     *
     * @return \DateTimeImmutable The next reset moment.
     */
    private function advance(\DateTimeImmutable $from, string $period): \DateTimeImmutable
    {
        $interval = match ($period) {
            'daily'  => 'P1D',
            'weekly' => 'P1W',
            default  => 'P1M',
        };

        return $from->add(new DateInterval($interval));
    }//end advance()

    /**
     * The active budget for a provider, or null when none exists.
     *
     * @param string $providerId The provider id.
     *
     * @return array<string, mixed>|null The budget object, or null.
     */
    private function budgetFor(string $providerId): ?array
    {
        foreach ($this->allBudgets() as $budget) {
            if ((string) ($budget['providerId'] ?? '') === $providerId) {
                return $budget;
            }
        }

        return null;
    }//end budgetFor()

    /**
     * All `messageSendBudget` objects.
     *
     * @return array<int, array<string, mixed>> The budget objects.
     */
    private function allBudgets(): array
    {
        [$register, $schema] = $this->registerSchema();
        if ($register === '' || $schema === '') {
            return [];
        }

        $objectService = $this->objectService();
        if ($objectService === null) {
            return [];
        }

        try {
            $results = $objectService->findAll(
                ['filters' => ['register' => $register, 'schema' => $schema], 'limit' => 1000]
            );
        } catch (\Exception $e) {
            $this->logger->warning('Budget query failed', ['exception' => $e->getMessage()]);
            return [];
        }

        $budgets = [];
        foreach ($results as $result) {
            $budgets[] = $this->serialize(result: $result);
        }

        return $budgets;
    }//end allBudgets()

    /**
     * Persist a patch onto a budget object via the OpenRegister save API.
     *
     * @param array<string, mixed> $budget The budget object.
     * @param array<string, mixed> $patch  The fields to update.
     *
     * @return void
     */
    private function persist(array $budget, array $patch): void
    {
        [$register, $schema] = $this->registerSchema();
        $objectService       = $this->objectService();
        if ($objectService === null || $register === '' || $schema === '') {
            return;
        }

        $id = $this->budgetId(budget: $budget);
        if ($id === '') {
            return;
        }

        $merged = array_merge($budget, $patch);
        unset($merged['@self']);

        try {
            $objectService->saveObject($merged, [], $register, $schema, $id);
        } catch (\Exception $e) {
            $this->logger->error('Budget persist failed', ['exception' => $e->getMessage()]);
        }
    }//end persist()

    /**
     * Fire a budget notification to administrators.
     *
     * @param string $event      The notification event type.
     * @param string $providerId The provider id.
     *
     * @return void
     */
    private function notify(string $event, string $providerId): void
    {
        try {
            $adminGroup = $this->groupManager->get('admin');
            if ($adminGroup === null) {
                return;
            }

            foreach ($adminGroup->getUsers() as $admin) {
                $this->notificationService->sendNotification(
                    $admin->getUID(),
                    $event,
                    ['providerId' => $providerId],
                    'messageSendBudget',
                    $providerId
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Budget notification failed', ['exception' => $e->getMessage()]);
        }
    }//end notify()

    /**
     * Resolve the configured register + messageSendBudget schema ids.
     *
     * @return array{0: string, 1: string} The [register, schema] pair.
     */
    private function registerSchema(): array
    {
        return [
            $this->appConfig->getValueString(Application::APP_ID, 'register', ''),
            $this->appConfig->getValueString(Application::APP_ID, 'messageSendBudget_schema', ''),
        ];
    }//end registerSchema()

    /**
     * Resolve the OpenRegister ObjectService, or null when unavailable.
     *
     * @return object|null The ObjectService, or null.
     */
    private function objectService(): ?object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->warning('OpenRegister ObjectService unavailable', ['exception' => $e->getMessage()]);
            return null;
        }
    }//end objectService()

    /**
     * Derive the OR object id for a budget.
     *
     * @param array<string, mixed> $budget The budget object.
     *
     * @return string The object id, or empty string.
     */
    private function budgetId(array $budget): string
    {
        $self = ($budget['@self'] ?? []);
        if (is_array($self) === true) {
            return (string) ($self['id'] ?? ($self['uuid'] ?? ''));
        }

        return (string) ($budget['id'] ?? '');
    }//end budgetId()
}//end class
