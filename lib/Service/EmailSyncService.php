<?php

/**
 * Pipelinq EmailSyncService.
 *
 * Service for email-to-entity matching and sync operations.
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
 * @spec openspec/specs/email-calendar-sync/spec.md#requirement-emails-must-be-automatically-linked-to-crm-contacts
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Service for email synchronization with CRM entities.
 *
 * Matches emails to contacts, organizations, and other entities
 * based on email address and domain.
 *
 * @spec openspec/changes/reverse-2026-05-26-be-contact-comms/tasks.md#task-3
 */
class EmailSyncService
{
    /**
     * Constructor.
     *
     * @param IConfig         $config The user config.
     * @param LoggerInterface $logger The logger.
     */
    public function __construct(
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Extract the domain part from an email address.
     *
     * @param string $email The email address.
     *
     * @return string|null The domain, or null if invalid.
     * @spec   openspec/changes/reverse-2026-05-26-be-contact-comms/tasks.md#task-4
     */
    public function extractDomain(string $email): ?string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return null;
        }

        return strtolower(trim($parts[1]));
    }//end extractDomain()

    /**
     * Get the mail accounts configured for sync by a user.
     *
     * @param string $userId The user ID.
     *
     * @return array<int> Array of mail account IDs.
     * @spec   openspec/changes/reverse-2026-05-26-be-contact-comms/tasks.md#task-6
     */
    public function getSyncAccounts(string $userId): array
    {
        $value = $this->config->getUserValue(
            $userId,
            'pipelinq',
            'email_sync_accounts',
            '[]',
        );

        $accounts = json_decode($value, true);

        if (is_array($accounts) === true) {
            return $accounts;
        }

        return [];
    }//end getSyncAccounts()

    /**
     * Check whether email sync is enabled for a user.
     *
     * @param string $userId The user ID.
     *
     * @return bool True if sync is enabled.
     * @spec   openspec/changes/reverse-2026-05-26-be-contact-comms/tasks.md#task-7
     */
    public function isSyncEnabled(string $userId): bool
    {
        $value = $this->config->getUserValue(
            $userId,
            'pipelinq',
            'email_sync_enabled',
            'false',
        );

        return $value === 'true';
    }//end isSyncEnabled()

    /**
     * Set email sync enabled/disabled for a user.
     *
     * @param string $userId  The user ID.
     * @param bool   $enabled Whether sync is enabled.
     *
     * @return void
     * @spec   openspec/changes/reverse-2026-05-26-be-contact-comms/tasks.md#task-9
     */
    public function setSyncEnabled(string $userId, bool $enabled): void
    {
        if ($enabled === true) {
            $value = 'true';
        } else {
            $value = 'false';
        }

        $this->config->setUserValue(
            $userId,
            'pipelinq',
            'email_sync_enabled',
            $value,
        );
    }//end setSyncEnabled()

    /**
     * Set the mail accounts to sync for a user.
     *
     * @param string     $userId   The user ID.
     * @param array<int> $accounts Array of mail account IDs.
     *
     * @return void
     * @spec   openspec/changes/reverse-2026-05-26-be-contact-comms/tasks.md#task-8
     */
    public function setSyncAccounts(string $userId, array $accounts): void
    {
        $this->config->setUserValue(
            $userId,
            'pipelinq',
            'email_sync_accounts',
            json_encode($accounts),
        );
    }//end setSyncAccounts()

    /**
     * Get the last sync timestamp for a user.
     *
     * @param string $userId The user ID.
     *
     * @return string|null ISO 8601 timestamp of last sync, or null.
     * @spec   openspec/changes/reverse-2026-05-26-be-contact-comms/tasks.md#task-5
     */
    public function getLastSyncTime(string $userId): ?string
    {
        $value = $this->config->getUserValue(
            $userId,
            'pipelinq',
            'email_sync_last',
            '',
        );

        if ($value !== '') {
            return $value;
        }

        return null;
    }//end getLastSyncTime()

    /**
     * Update the last sync timestamp for a user.
     *
     * @param string $userId The user ID.
     *
     * @return void
     * @spec   openspec/changes/reverse-2026-05-26-be-contact-comms/tasks.md#task-10
     */
    public function updateLastSyncTime(string $userId): void
    {
        $this->config->setUserValue(
            $userId,
            'pipelinq',
            'email_sync_last',
            (new \DateTime())->format(\DateTime::ATOM),
        );
    }//end updateLastSyncTime()

    /**
     * Build an EmailLink data array for OpenRegister storage.
     *
     * @param string      $messageId        The email message ID.
     * @param string      $subject          The email subject.
     * @param string      $sender           The sender address.
     * @param array       $recipients       The recipient addresses.
     * @param string      $date             The email date.
     * @param string      $linkedEntityType The entity type.
     * @param string      $linkedEntityId   The entity UUID.
     * @param string      $direction        Inbound or outbound.
     * @param string|null $threadId         The thread ID.
     * @param string|null $syncSource       The mail account ID.
     *
     * @return array<string, mixed> The EmailLink data.
     * @spec   openspec/changes/reverse-2026-05-26-be-contact-comms/tasks.md#task-3
     */
    public function buildEmailLinkData(
        string $messageId,
        string $subject,
        string $sender,
        array $recipients,
        string $date,
        string $linkedEntityType,
        string $linkedEntityId,
        string $direction,
        ?string $threadId=null,
        ?string $syncSource=null,
    ): array {
        return [
            'messageId'        => $messageId,
            'subject'          => $subject,
            'sender'           => $sender,
            'recipients'       => $recipients,
            'date'             => $date,
            'threadId'         => $threadId,
            'linkedEntityType' => $linkedEntityType,
            'linkedEntityId'   => $linkedEntityId,
            'direction'        => $direction,
            'syncSource'       => $syncSource,
            'excluded'         => false,
            'deleted'          => false,
        ];
    }//end buildEmailLinkData()
}//end class
