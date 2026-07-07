<?php

/**
 * Pipelinq ContactmomentService.
 *
 * Service for contactmoment business operations including permission-checked deletion.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-39
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-40
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\NotPermittedException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for contactmoment business operations.
 *
 * Handles permission-checked deletion (only the creating agent or a Nextcloud
 * admin may delete a contactmoment) and the unified outbound-send audit write.
 *
 * @spec openspec/changes/outbound-messaging-provider-wiring/specs/omnichannel-registratie/spec.md#requirement-outbound-messages-registered-as-contactmomenten
 */
class ContactmomentService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container    The DI container.
     * @param IAppConfig         $appConfig    The app config.
     * @param IGroupManager      $groupManager The group manager.
     * @param LoggerInterface    $logger       The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return \OCA\OpenRegister\Service\ObjectService The object service.
     *
     * @throws \RuntimeException If OpenRegister is not available.
     * @spec   openspec/changes/reverse-2026-05-26-be-contact-comms/tasks.md#task-2
     */
    public function getObjectService(): \OCA\OpenRegister\Service\ObjectService
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            throw new \RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()

    /**
     * Get the configured register and schema for contactmomenten.
     *
     * @return array{register: string, schema: string} The register and schema IDs.
     *
     * @throws \RuntimeException If configuration is missing.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-40
     */
    public function getConfig(): array
    {
        $register = $this->appConfig->getValueString(
            Application::APP_ID,
            'register',
            ''
        );
        $schema   = $this->appConfig->getValueString(
            Application::APP_ID,
            'contactmoment_schema',
            ''
        );

        if ($register === '' || $schema === '') {
            throw new \RuntimeException('Contactmoment register or schema not configured.');
        }

        return [
            'register' => $register,
            'schema'   => $schema,
        ];
    }//end getConfig()

    /**
     * Record an outbound messaging send as a contactmoment (audit).
     *
     * The single write path for REQ-OM-006: every successful outbound
     * WhatsApp / SMS send (agent composer or SLA escalation) is registered
     * as a contactmoment linked to the client. WhatsApp is stored as
     * `channel: chat` with `channelMetadata.platform = whatsapp`; SMS uses
     * the `channel: sms` enum value. The write is log-and-continue: any
     * failure (OpenRegister absent, schema unconfigured, save error) returns
     * null and MUST never block, fail, or roll back the send itself.
     *
     * @param string               $channel         `sms` or `chat` (WhatsApp).
     * @param string               $subject         Human-readable subject line.
     * @param string               $summary         Message body / summary text.
     * @param array<string, mixed> $channelMetadata Metadata envelope
     *                                              (platform, direction,
     *                                              messageId, conversationId,
     *                                              contactId).
     * @param string               $clientId        Linked client UUID, or empty.
     * @param string               $agent           Acting agent id
     *                                              (`system:sla-engine` for
     *                                              SLA escalations), or empty.
     *
     * @return string|null The contactmoment UUID, or null when skipped/failed.
     *
     * @spec openspec/changes/outbound-messaging-provider-wiring/specs/omnichannel-registratie/spec.md#requirement-outbound-messages-registered-as-contactmomenten
     */
    public function recordOutboundMessage(
        string $channel,
        string $subject,
        string $summary,
        array $channelMetadata,
        string $clientId='',
        string $agent=''
    ): ?string {
        try {
            $config = $this->getConfig();
        } catch (\Throwable $e) {
            $this->logger->info(
                'ContactmomentService.recordOutboundMessage: audit skipped (contactmoment schema unconfigured)',
                ['exception' => $e->getMessage()]
            );
            return null;
        }

        // Resolve the object service loosely (duck-typed) so the audit path
        // never depends on the concrete OpenRegister class being loadable —
        // it is a best-effort side-channel, not a hard dependency.
        $objectService = $this->objectServiceLoose();
        if ($objectService === null) {
            $this->logger->info('ContactmomentService.recordOutboundMessage: audit skipped (OpenRegister unavailable)');
            return null;
        }

        $payload = [
            'subject'         => $subject,
            'summary'         => $summary,
            'channel'         => $channel,
            'contactedAt'     => gmdate('Y-m-d\TH:i:s\Z'),
            'channelMetadata' => $channelMetadata,
        ];
        if ($clientId !== '') {
            $payload['client'] = $clientId;
        }

        if ($agent !== '') {
            $payload['agent'] = $agent;
        }

        try {
            $saved = $objectService->saveObject(
                object: $payload,
                register: $config['register'],
                schema: $config['schema'],
                uuid: null,
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ContactmomentService.recordOutboundMessage: save failed',
                ['channel' => $channel, 'exception' => $e->getMessage()]
            );
            return null;
        }

        if (is_object($saved) === true && method_exists($saved, 'getUuid') === true) {
            return (string) $saved->getUuid();
        }

        if (is_array($saved) === true) {
            return (string) ($saved['uuid'] ?? ($saved['id'] ?? ''));
        }

        return null;
    }//end recordOutboundMessage()

    /**
     * Resolve the OpenRegister ObjectService without a strict return type.
     *
     * Used only by the best-effort outbound audit path so it degrades quietly
     * (returns null) when OpenRegister is absent, rather than throwing.
     *
     * @return object|null The object service, or null.
     */
    private function objectServiceLoose(): ?object
    {
        try {
            $service = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            return null;
        }

        if (is_object($service) === false || method_exists($service, 'saveObject') === false) {
            return null;
        }

        return $service;
    }//end objectServiceLoose()

    /**
     * Delete a contactmoment with permission checking.
     *
     * Only the creating agent or a Nextcloud admin may delete.
     *
     * @param string $id            The contactmoment object UUID.
     * @param string $currentUserId The ID of the user requesting deletion.
     *
     * @return bool True if deleted successfully.
     *
     * @throws DoesNotExistException  If contactmoment not found.
     * @throws NotPermittedException  If user lacks permission.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-39
     */
    public function delete(string $id, string $currentUserId): bool
    {
        $objectService = $this->getObjectService();
        $config        = $this->getConfig();

        // Fetch the object to check ownership.
        $object = $objectService->find(
            id: $id,
            register: $config['register'],
            schema: $config['schema']
        );

        if ($object === null) {
            throw new DoesNotExistException(
                'Contactmoment not found: '.$id
            );
        }

        // Use the OR-immutable createdBy field (set by the platform on every
        // saveObject/createObject call) rather than the user-mutable `agent` field.
        // Any caller with OR write-access can stamp `agent: victim_uid`; `createdBy`
        // is protected by the platform and cannot be overwritten via the public API.
        $createdBy = '';
        if (is_array($object) === true) {
            $createdBy = ($object['createdBy'] ?? '');
        }

        if (is_array($object) === false) {
            $createdBy = ($object->getCreatedBy() ?? '');
        }

        $isCreator = ($createdBy !== '' && $createdBy === $currentUserId);
        $isAdmin   = $this->groupManager->isAdmin($currentUserId);

        if ($isCreator === false && $isAdmin === false) {
            throw new NotPermittedException(
                'Only the creating agent or an admin can delete this contactmoment.'
            );
        }

        $objectService
            ->setRegister($config['register'])
            ->setSchema($config['schema'])
            ->deleteObject(uuid: $id);

        $this->logger->info(
            'Contactmoment deleted',
            [
                'id'     => $id,
                'userId' => $currentUserId,
            ]
        );

        return true;
    }//end delete()
}//end class
