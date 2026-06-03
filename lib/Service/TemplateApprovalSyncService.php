<?php

/**
 * Pipelinq TemplateApprovalSyncService.
 *
 * Syncs WhatsApp template approval state from the provider and reflects status
 * changes locally, alerting administrators on rejections/disables.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.8
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Messaging\Provider\MetaWhatsAppClient;
use OCA\Pipelinq\Service\Messaging\ProviderConfigService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reconciles local template status against a provider-reported status map
 * (REQ-009).
 *
 * The provider call is performed by the background job and the resulting
 * status map (externalId => status) is passed into {@see reconcile()}, keeping
 * this service free of live HTTP and therefore fully unit-testable. Each local
 * `messageTemplate` whose status differs is updated and, when newly
 * `rejected`/`disabled`, an admin notification fires.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — coordinates OR objects, config and notifications
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)   — per-template reconcile compares + guards each row
 * @spec                                           openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.8
 */
class TemplateApprovalSyncService
{
    /**
     * Statuses that trigger an administrator alert when newly entered.
     *
     * @var string[]
     */
    private const ALERTING_STATUSES = ['rejected', 'disabled'];

    /**
     * Constructor.
     *
     * @param TemplateService       $templateService     The template repository.
     * @param ProviderConfigService $providerConfig      Provider resolution + client building.
     * @param ContainerInterface    $container           The DI container (resolves OpenRegister).
     * @param IAppConfig            $appConfig           The app config (register/schema ids).
     * @param NotificationService   $notificationService The notification service.
     * @param IGroupManager         $groupManager        The group manager (resolves admins).
     * @param LoggerInterface       $logger              The logger.
     */
    public function __construct(
        private TemplateService $templateService,
        private ProviderConfigService $providerConfig,
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private NotificationService $notificationService,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Fetch the provider template-status map across all WhatsApp providers.
     *
     * @return array<string, string> The aggregated externalId => status map.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.8
     */
    public function fetchProviderStatuses(): array
    {
        $statuses = [];
        foreach ($this->providerConfig->activeProviders(kinds: ['whatsapp-cloud-api']) as $provider) {
            $client = $this->providerConfig->buildClient(provider: $provider);
            if (($client instanceof MetaWhatsAppClient) === false) {
                continue;
            }

            foreach ($client->fetchTemplateStatuses() as $name => $status) {
                $statuses[$name] = $status;
            }
        }

        return $statuses;
    }//end fetchProviderStatuses()

    /**
     * Fetch provider statuses and reconcile local templates in one call.
     *
     * @return array{updated: int, alerted: int} The reconciliation summary.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-7.1
     */
    public function sync(): array
    {
        $statuses = $this->fetchProviderStatuses();
        if ($statuses === []) {
            return ['updated' => 0, 'alerted' => 0];
        }

        return $this->reconcile(providerStatuses: $statuses);
    }//end sync()

    /**
     * Reconcile local templates against a provider status map.
     *
     * @param array<string, string> $providerStatuses Map of externalId => provider status.
     *
     * @return array{updated: int, alerted: int} The reconciliation summary.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.8
     */
    public function reconcile(array $providerStatuses): array
    {
        [$register, $schema] = $this->registerSchema();
        $objectService       = $this->objectService();
        if ($objectService === null || $register === '' || $schema === '') {
            return ['updated' => 0, 'alerted' => 0];
        }

        $updated = 0;
        $alerted = 0;
        foreach ($this->templateService->allTemplates() as $template) {
            $externalId = (string) ($template['externalId'] ?? '');
            if ($externalId === '' || array_key_exists($externalId, $providerStatuses) === false) {
                continue;
            }

            $newStatus = $this->normaliseStatus(status: $providerStatuses[$externalId]);
            $oldStatus = (string) ($template['status'] ?? '');
            if ($newStatus === $oldStatus) {
                continue;
            }

            if ($this->apply(template: $template, status: $newStatus, register: $register, schema: $schema) === false) {
                continue;
            }

            $updated++;
            if (in_array($newStatus, self::ALERTING_STATUSES, true) === true) {
                $this->notify(externalId: $externalId, status: $newStatus);
                $alerted++;
            }
        }//end foreach

        return ['updated' => $updated, 'alerted' => $alerted];
    }//end reconcile()

    /**
     * Apply a new status (and lastSyncedAt) to a template object.
     *
     * @param array<string, mixed> $template The template object.
     * @param string               $status   The new status.
     * @param string               $register The register id.
     * @param string               $schema   The schema id.
     *
     * @return bool True when persisted.
     */
    private function apply(array $template, string $status, string $register, string $schema): bool
    {
        $id = $this->templateId(template: $template);
        if ($id === '') {
            return false;
        }

        $merged           = $template;
        $merged['status'] = $status;
        $merged['lastSyncedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
        unset($merged['@self']);

        try {
            $this->objectService()->saveObject($merged, [], $register, $schema, $id);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Template status update failed', ['exception' => $e->getMessage()]);
            return false;
        }
    }//end apply()

    /**
     * Normalise a provider status string to the local status enum.
     *
     * @param string $status The provider status.
     *
     * @return string The normalised status.
     */
    private function normaliseStatus(string $status): string
    {
        $map = [
            'APPROVED'  => 'approved',
            'PENDING'   => 'pending',
            'IN_APPEAL' => 'pending',
            'REJECTED'  => 'rejected',
            'DISABLED'  => 'disabled',
            'PAUSED'    => 'disabled',
        ];

        return ($map[strtoupper(trim($status))] ?? 'pending');
    }//end normaliseStatus()

    /**
     * Notify administrators of a rejected/disabled template.
     *
     * @param string $externalId The template external id.
     * @param string $status     The new status.
     *
     * @return void
     */
    private function notify(string $externalId, string $status): void
    {
        try {
            $adminGroup = $this->groupManager->get('admin');
            if ($adminGroup === null) {
                return;
            }

            foreach ($adminGroup->getUsers() as $admin) {
                $this->notificationService->sendNotification(
                    $admin->getUID(),
                    'template_'.$status,
                    ['externalId' => $externalId],
                    'messageTemplate',
                    $externalId
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Template notification failed', ['exception' => $e->getMessage()]);
        }
    }//end notify()

    /**
     * Resolve the configured register + messageTemplate schema ids.
     *
     * @return array{0: string, 1: string} The [register, schema] pair.
     */
    private function registerSchema(): array
    {
        return [
            $this->appConfig->getValueString(Application::APP_ID, 'register', ''),
            $this->appConfig->getValueString(Application::APP_ID, 'messageTemplate_schema', ''),
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
     * Derive the id of a template (uuid, else @self.slug).
     *
     * @param array<string, mixed> $template The template object.
     *
     * @return string The template id.
     */
    private function templateId(array $template): string
    {
        $self = ($template['@self'] ?? []);
        if (is_array($self) === true) {
            $id = (string) ($self['id'] ?? ($self['uuid'] ?? ($self['slug'] ?? '')));
            if ($id !== '') {
                return $id;
            }
        }

        return (string) ($template['id'] ?? '');
    }//end templateId()
}//end class
