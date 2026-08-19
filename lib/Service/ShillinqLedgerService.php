<?php

/**
 * Pipelinq ShillinqLedgerService.
 *
 * Maps pipelinq project and projectPhase data to Shillinq project-ledger
 * CloudEvents and dispatches them, one-way, through OpenRegister's
 * WebhookService. Delivery is fire-and-forget: a missing consumer or an
 * unavailable OpenRegister must never fail the originating project write.
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
 * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-003
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
 * Builds and dispatches Shillinq project-ledger CloudEvents.
 *
 * The shillinq_ledger_webhook_url app-config value is the integration toggle:
 * an empty or non-HTTPS value disables dispatch ({@see self::shouldDispatch()}).
 * When configured, project creation and status-change events are emitted as
 * CloudEvents 1.0 envelopes through OpenRegister's WebhookService.
 *
 * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-003
 */
class ShillinqLedgerService
{
    /**
     * CloudEvents type for a newly created project.
     *
     * @var string
     */
    public const EVENT_PROJECT_CREATED = 'nl.conduction.pipelinq.project.created';

    /**
     * CloudEvents type for a project status change.
     *
     * @var string
     */
    public const EVENT_PROJECT_STATUS_CHANGED = 'nl.conduction.pipelinq.project.status-changed';

    /**
     * CloudEvents source for project ledger events.
     *
     * @var string
     */
    private const EVENT_SOURCE = '/apps/pipelinq/projects';

    /**
     * Map of pipelinq project status values to Shillinq ledger phase names.
     *
     * @var array<string, string>
     */
    private const STATUS_PHASE_MAP = [
        'open'        => 'initial',
        'in_progress' => 'active',
        'completed'   => 'closed',
        'cancelled'   => 'cancelled',
    ];

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
     * Whether ledger dispatch is enabled.
     *
     * Returns true only when shillinq_ledger_webhook_url is a non-empty,
     * well-formed HTTPS URL. An unconfigured or malformed value disables the
     * integration so listeners no-op silently.
     *
     * @return bool True when a valid HTTPS webhook URL is configured.
     *
     * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-001-04
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
     * Dispatch a project lifecycle event (e.g. creation) to the Shillinq ledger.
     *
     * @param array<string, mixed> $project   The project object data.
     * @param string               $eventType The lifecycle event type (e.g. 'created').
     *
     * @return bool True on successful dispatch, false on failure.
     *
     * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-001-01
     */
    public function dispatchProjectEvent(array $project, string $eventType): bool
    {
        if ($this->shouldDispatch() === false) {
            return false;
        }

        $payload = $this->buildProjectPayload(project: $project, eventType: $eventType);

        return $this->dispatch(eventName: self::EVENT_PROJECT_CREATED, payload: $payload);
    }//end dispatchProjectEvent()

    /**
     * Dispatch a project status-change event to the Shillinq ledger.
     *
     * @param array<string, mixed> $project   The project object data.
     * @param string               $oldStatus The previous pipelinq status value.
     * @param string               $newStatus The new pipelinq status value.
     *
     * @return bool True on successful dispatch, false on failure.
     *
     * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-002-01
     */
    public function dispatchPhaseChangeEvent(array $project, string $oldStatus, string $newStatus): bool
    {
        if ($this->shouldDispatch() === false) {
            return false;
        }

        $payload = $this->buildStatusChangePayload(
            project: $project,
            oldStatus: $oldStatus,
            newStatus: $newStatus
        );

        return $this->dispatch(eventName: self::EVENT_PROJECT_STATUS_CHANGED, payload: $payload);
    }//end dispatchPhaseChangeEvent()

    /**
     * Map a pipelinq project status to a Shillinq ledger phase name.
     *
     * Unknown statuses fall through to their own value so the consumer can
     * decide how to treat them rather than silently losing the signal.
     *
     * @param string $status The pipelinq status value.
     *
     * @return string The mapped Shillinq phase name.
     *
     * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-002-02
     */
    public function mapStatusToPhase(string $status): string
    {
        return self::STATUS_PHASE_MAP[$status] ?? $status;
    }//end mapStatusToPhase()

    /**
     * Build the CloudEvents 1.0 envelope for a project creation event.
     *
     * @param array<string, mixed> $project   The project object data.
     * @param string               $eventType The lifecycle event type.
     *
     * @return array<string, mixed> The CloudEvent payload.
     */
    private function buildProjectPayload(array $project, string $eventType): array
    {
        $projectId = (string) ($project['id'] ?? $project['uuid'] ?? '');

        return [
            'specversion'     => '1.0',
            'type'            => self::EVENT_PROJECT_CREATED,
            'source'          => self::EVENT_SOURCE,
            'id'              => $projectId,
            'time'            => (string) ($project['createdAt'] ?? $this->now()),
            'datacontenttype' => 'application/json',
            'data'            => [
                'eventType'    => $eventType,
                'projectId'    => $projectId,
                'projectName'  => (string) ($project['name'] ?? ''),
                'clientId'     => (string) ($project['client'] ?? ''),
                'phase'        => $this->mapStatusToPhase(status: (string) ($project['status'] ?? 'open')),
                'status'       => (string) ($project['status'] ?? ''),
                'billable'     => (bool) ($project['billable'] ?? false),
                'budgetAmount' => (float) ($project['budgetAmount'] ?? 0),
                'budgetHours'  => (float) ($project['budgetHours'] ?? 0),
                'startDate'    => (string) ($project['startDate'] ?? ''),
                'endDate'      => (string) ($project['endDate'] ?? ''),
                'createdBy'    => (string) ($project['owner'] ?? ''),
                'createdAt'    => (string) ($project['createdAt'] ?? ''),
            ],
        ];
    }//end buildProjectPayload()

    /**
     * Build the CloudEvents 1.0 envelope for a project status-change event.
     *
     * @param array<string, mixed> $project   The project object data.
     * @param string               $oldStatus The previous pipelinq status value.
     * @param string               $newStatus The new pipelinq status value.
     *
     * @return array<string, mixed> The CloudEvent payload.
     */
    private function buildStatusChangePayload(array $project, string $oldStatus, string $newStatus): array
    {
        $projectId = (string) ($project['id'] ?? $project['uuid'] ?? '');
        $now       = $this->now();

        return [
            'specversion'     => '1.0',
            'type'            => self::EVENT_PROJECT_STATUS_CHANGED,
            'source'          => self::EVENT_SOURCE,
            'id'              => $projectId.'-'.$now,
            'time'            => $now,
            'datacontenttype' => 'application/json',
            'data'            => [
                'projectId'    => $projectId,
                'projectName'  => (string) ($project['name'] ?? ''),
                'clientId'     => (string) ($project['client'] ?? ''),
                'oldStatus'    => $oldStatus,
                'newStatus'    => $newStatus,
                'phase'        => $this->mapStatusToPhase(status: $newStatus),
                'billable'     => (bool) ($project['billable'] ?? false),
                'budgetAmount' => (float) ($project['budgetAmount'] ?? 0),
                'updatedAt'    => $now,
            ],
        ];
    }//end buildStatusChangePayload()

    /**
     * Dispatch a CloudEvent through OpenRegister's WebhookService.
     *
     * Fire-and-forget: any failure to resolve or invoke the WebhookService is
     * logged and reported as a false return so the caller can mark the project
     * sync as failed, but never throws.
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
                'Pipelinq: Shillinq ledger CloudEvent not dispatched (no consumer or OpenRegister unavailable)',
                ['exception' => $e->getMessage(), 'eventName' => $eventName]
            );
            return false;
        }//end try
    }//end dispatch()

    /**
     * Read the configured Shillinq ledger webhook URL.
     *
     * @return string The configured URL, or an empty string when unset.
     */
    private function webhookUrl(): string
    {
        return trim($this->appConfig->getValueString(Application::APP_ID, 'shillinq_ledger_webhook_url', ''));
    }//end webhookUrl()

    /**
     * Current UTC timestamp in ISO 8601 format.
     *
     * Public so the project listeners can stamp ledgerSyncedAt consistently
     * without each coupling directly to the date-time classes.
     *
     * @return string The ISO 8601 timestamp.
     */
    public function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }//end now()
}//end class
