<?php

/**
 * Pipelinq ShillinqApService.
 *
 * Maps approved pipelinq expense payloads to the Shillinq AP CloudEvents
 * envelope and dispatches them, one-way, through OpenRegister's
 * WebhookService. Delivery is fire-and-forget: a missing consumer or an
 * unavailable OpenRegister must never fail the originating expense write.
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
 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds and dispatches Shillinq AP voucher CloudEvents.
 *
 * The shillinq_ap_webhook_url app-config value is the integration toggle: an
 * empty or non-HTTPS value disables dispatch ({@see self::shouldDispatch()}).
 * When configured, expense approvals are emitted as CloudEvents 1.0 envelopes
 * through OpenRegister's WebhookService.
 *
 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003
 */
class ShillinqApService
{
    /**
     * CloudEvents type for an approved expense.
     *
     * @var string
     */
    public const EVENT_EXPENSE_APPROVED = 'nl.conduction.pipelinq.expense.approved';

    /**
     * CloudEvents source for AP events.
     *
     * @var string
     */
    private const EVENT_SOURCE = '/apps/pipelinq/expenses';

    /**
     * Constructor.
     *
     * @param IAppConfig         $appConfig The app configuration.
     * @param ContainerInterface $container The DI container (OpenRegister WebhookService lookup).
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether AP dispatch is enabled.
     *
     * Returns true only when shillinq_ap_webhook_url is a non-empty,
     * well-formed HTTPS URL. An unconfigured or malformed value disables the
     * integration so listeners no-op silently (REQ-AP-002 Scenario 6).
     *
     * @return bool True when a valid HTTPS webhook URL is configured.
     *
     * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003
     */
    public function shouldDispatch(): bool
    {
        $url = $this->webhookUrl();
        if ($url === '') {
            return false;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return str_starts_with($url, 'https://');
    }//end shouldDispatch()

    /**
     * Dispatch an expense approval event to the Shillinq AP webhook.
     *
     * @param array<string, mixed> $expense    The expense object data (must contain uuid + amount).
     * @param string               $approvedBy The approving user id.
     * @param string               $approvedAt The ISO 8601 approval timestamp.
     *
     * @return bool True on successful dispatch, false on failure or when unconfigured.
     *
     * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003
     */
    public function dispatchApEvent(array $expense, string $approvedBy, string $approvedAt): bool
    {
        if ($this->shouldDispatch() === false) {
            return false;
        }

        $payload = $this->buildApPayload(
            expense: $expense,
            approvedBy: $approvedBy,
            approvedAt: $approvedAt
        );

        return $this->dispatch(eventName: self::EVENT_EXPENSE_APPROVED, payload: $payload);
    }//end dispatchApEvent()

    /**
     * Build the CloudEvents 1.0 envelope for an approved expense.
     *
     * The payload shape matches REQ-AP-003 Scenario 7 exactly so Shillinq
     * consumers can be conformance-tested independently of pipelinq.
     *
     * @param array<string, mixed> $expense    The expense object data.
     * @param string               $approvedBy The approving user id.
     * @param string               $approvedAt The ISO 8601 approval timestamp.
     *
     * @return array<string, mixed> The CloudEvent payload.
     */
    private function buildApPayload(array $expense, string $approvedBy, string $approvedAt): array
    {
        $expenseId = (string) ($expense['uuid'] ?? $expense['id'] ?? '');
        $time      = $this->now();
        if ($approvedAt !== '') {
            $time = $approvedAt;
        }

        $projectId = null;
        if (isset($expense['project']) === true && $expense['project'] !== '') {
            $projectId = (string) $expense['project'];
        }

        return [
            'specversion'     => '1.0',
            'type'            => self::EVENT_EXPENSE_APPROVED,
            'source'          => self::EVENT_SOURCE,
            'id'              => $expenseId,
            'time'            => $time,
            'datacontenttype' => 'application/json',
            'data'            => [
                'expenseId'  => $expenseId,
                'amount'     => (float) ($expense['amount'] ?? 0),
                'currency'   => (string) ($expense['currency'] ?? 'EUR'),
                'categoryId' => (string) ($expense['category'] ?? ''),
                'clientId'   => (string) ($expense['client'] ?? ''),
                'projectId'  => $projectId,
                'billable'   => (bool) ($expense['billable'] ?? false),
                'approvedBy' => $approvedBy,
                'approvedAt' => $approvedAt,
            ],
        ];
    }//end buildApPayload()

    /**
     * Dispatch a CloudEvent through OpenRegister's WebhookService.
     *
     * Fire-and-forget: any failure to resolve or invoke the WebhookService is
     * logged and reported as a false return so the caller can mark the
     * expense sync as failed, but never throws (REQ-AP-002 — listener MUST
     * NOT block other handlers).
     *
     * @param string               $eventName The webhook event name.
     * @param array<string, mixed> $payload   The CloudEvent payload.
     *
     * @return bool True on successful dispatch, false on failure.
     */
    private function dispatch(string $eventName, array $payload): bool
    {
        try {
            $webhookService = $this->container->get('OCA\OpenRegister\Service\WebhookService');
            $event          = new Event();
            $webhookService->dispatchEvent(
                _event: $event,
                eventName: $eventName,
                payload: $payload
            );
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: Shillinq AP CloudEvent not dispatched (no consumer or OpenRegister unavailable)',
                ['exception' => $e->getMessage(), 'eventName' => $eventName]
            );
            return false;
        }//end try
    }//end dispatch()

    /**
     * Read the configured Shillinq AP webhook URL.
     *
     * @return string The configured URL, or an empty string when unset.
     */
    private function webhookUrl(): string
    {
        return trim($this->appConfig->getValueString(Application::APP_ID, 'shillinq_ap_webhook_url', ''));
    }//end webhookUrl()

    /**
     * Current UTC timestamp in ISO 8601 format.
     *
     * Public so listeners and the retry controller can stamp apSyncedAt
     * consistently without each coupling directly to the date-time classes.
     *
     * @return string The ISO 8601 timestamp.
     */
    public function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }//end now()
}//end class
