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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Service for email synchronization with CRM entities.
 *
 * Matches emails to contacts, organizations, and other entities
 * based on email address and domain. This service owns the pipelinq
 * CRM matching rules; link storage is delegated to the OpenRegister
 * email leaf API.
 *
 * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
 */
class EmailSyncService
{
    /**
     * Public email provider domains that MUST NOT trigger domain-to-organization matching.
     *
     * @var array<string>
     */
    private const PUBLIC_DOMAINS = [
        'gmail.com',
        'googlemail.com',
        'outlook.com',
        'hotmail.com',
        'hotmail.nl',
        'yahoo.com',
        'yahoo.nl',
        'live.com',
        'live.nl',
        'icloud.com',
        'me.com',
        'mac.com',
        'protonmail.com',
        'proton.me',
        'tutanota.com',
        'gmx.com',
        'gmx.net',
        'web.de',
        'msn.com',
    ];

    /**
     * Constructor.
     *
     * @param IConfig         $config The user config.
     * @param LoggerInterface $logger The logger.
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
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
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
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
     * Match an email address to CRM entities (contact or client).
     *
     * Checks the contact.email and client.email fields in OpenRegister.
     * Returns a list of [entityType, entityId] pairs for all matches.
     * This method defines the pipelinq CRM matching rule; the caller
     * is responsible for calling the email leaf link API on each match.
     *
     * @param string                                  $address       The sender or recipient email address.
     * @param \OCA\OpenRegister\Service\ObjectService $objectService OpenRegister ObjectService.
     *
     * @return array<array{entityType: string, entityId: string}> Matched entity references.
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
     */
    public function matchEmailToEntities(string $address, object $objectService): array
    {
        $address = strtolower(trim($address));
        $matches = [];

        // Search contacts by email field.
        try {
            $contacts = $objectService->findObjects(
                'pipelinq',
                'contact',
                ['email' => $address, '_limit' => 50]
            );

            if (is_array($contacts) === true) {
                foreach ($contacts as $contact) {
                    $id = $contact['uuid'] ?? $contact['id'] ?? null;
                    if ($id !== null) {
                        $matches[] = [
                            'entityType' => 'contact',
                            'entityId'   => (string) $id,
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('EmailSyncService: contact lookup failed', ['exception' => $e]);
        }//end try

        // Search clients by email field.
        try {
            $clients = $objectService->findObjects(
                'pipelinq',
                'client',
                ['email' => $address, '_limit' => 50]
            );

            if (is_array($clients) === true) {
                foreach ($clients as $client) {
                    $id = $client['uuid'] ?? $client['id'] ?? null;
                    if ($id !== null) {
                        $matches[] = [
                            'entityType' => 'client',
                            'entityId'   => (string) $id,
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('EmailSyncService: client email lookup failed', ['exception' => $e]);
        }//end try

        return $matches;
    }//end matchEmailToEntities()

    /**
     * Match an email domain to a CRM organization (client with that domain).
     *
     * Returns null for public provider domains (gmail, outlook, etc.) to
     * prevent mass-matching unrelated contacts.
     *
     * @param string                                  $domain        The email domain (e.g. gemeente-utrecht.nl).
     * @param \OCA\OpenRegister\Service\ObjectService $objectService OpenRegister ObjectService.
     *
     * @return array{entityType: string, entityId: string}|null Match or null if not found.
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
     */
    public function matchDomainToOrganization(string $domain, object $objectService): ?array
    {
        if ($this->isPublicDomain(domain: $domain) === true) {
            return null;
        }

        try {
            $clients = $objectService->findObjects(
                'pipelinq',
                'client',
                ['domains' => $domain, '_limit' => 1]
            );

            if (is_array($clients) === true && count($clients) > 0) {
                $client = $clients[0];
                $id     = $client['uuid'] ?? $client['id'] ?? null;
                if ($id !== null) {
                    return [
                        'entityType' => 'client',
                        'entityId'   => (string) $id,
                    ];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('EmailSyncService: domain lookup failed', ['domain' => $domain, 'exception' => $e]);
        }

        return null;
    }//end matchDomainToOrganization()

    /**
     * Check whether a domain belongs to a public email provider.
     *
     * Public domains (gmail.com, outlook.com, etc.) MUST NOT be used
     * for domain-to-organization matching to avoid erroneous CRM links.
     *
     * @param string $domain The domain to check.
     *
     * @return bool True if the domain is a public email provider.
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
     */
    public function isPublicDomain(string $domain): bool
    {
        return in_array(strtolower($domain), self::PUBLIC_DOMAINS, strict: true);
    }//end isPublicDomain()

    /**
     * Get the mail accounts configured for sync by a user.
     *
     * @param string $userId The user ID.
     *
     * @return array<int> Array of mail account IDs.
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-3.1
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
     * Set email sync enabled/disabled for a user.
     *
     * @param string $userId  The user ID.
     * @param bool   $enabled Whether sync is enabled.
     *
     * @return void
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-3.1
     */
    public function setSyncEnabled(string $userId, bool $enabled): void
    {
        $value = 'false';
        if ($enabled === true) {
            $value = 'true';
        }

        $this->config->setUserValue(
            $userId,
            'pipelinq',
            'email_sync_enabled',
            $value,
        );
    }//end setSyncEnabled()

    /**
     * Get whether email sync is enabled for a user.
     *
     * @param string $userId The user ID.
     *
     * @return bool True if sync is enabled.
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-3.1
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
     * Set the mail accounts to sync for a user.
     *
     * @param string     $userId   The user ID.
     * @param array<int> $accounts Array of mail account IDs.
     *
     * @return void
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-3.1
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
     * Get the list of excluded email addresses for a user.
     *
     * @param string $userId The user ID.
     *
     * @return array<string> Array of excluded email addresses.
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-3.1
     */
    public function getExcludedAddresses(string $userId): array
    {
        $value = $this->config->getUserValue(
            $userId,
            'pipelinq',
            'email_sync_excluded',
            '[]',
        );

        $addresses = json_decode($value, true);

        if (is_array($addresses) === true) {
            return $addresses;
        }

        return [];
    }//end getExcludedAddresses()

    /**
     * Set the excluded email addresses for a user.
     *
     * @param string        $userId    The user ID.
     * @param array<string> $addresses Array of excluded email addresses.
     *
     * @return void
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-3.1
     */
    public function setExcludedAddresses(string $userId, array $addresses): void
    {
        $this->config->setUserValue(
            $userId,
            'pipelinq',
            'email_sync_excluded',
            json_encode($addresses),
        );
    }//end setExcludedAddresses()

    /**
     * Get the last sync timestamp for a user.
     *
     * @param string $userId The user ID.
     *
     * @return string|null ISO 8601 timestamp of last sync, or null.
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-3.1
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
     * Get the count of emails linked during the last sync for a user.
     *
     * @param string $userId The user ID.
     *
     * @return int Count of emails linked.
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-3.1
     */
    public function getLastSyncCount(string $userId): int
    {
        return (int) $this->config->getUserValue(
            $userId,
            'pipelinq',
            'email_sync_last_count',
            '0',
        );
    }//end getLastSyncCount()

    /**
     * Get the last error message for a user's sync run, if any.
     *
     * @param string $userId The user ID.
     *
     * @return string|null Error message or null.
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-3.1
     */
    public function getLastSyncError(string $userId): ?string
    {
        $value = $this->config->getUserValue(
            $userId,
            'pipelinq',
            'email_sync_last_error',
            '',
        );

        if ($value !== '') {
            return $value;
        }

        return null;
    }//end getLastSyncError()

    /**
     * Update the last sync status for a user.
     *
     * @param string      $userId The user ID.
     * @param int         $count  Number of emails linked in this run.
     * @param string|null $error  Error message if the run failed, null otherwise.
     *
     * @return void
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
     */
    public function updateLastSyncTime(string $userId, int $count=0, ?string $error=null): void
    {
        $this->config->setUserValue(
            $userId,
            'pipelinq',
            'email_sync_last',
            (new \DateTime())->format(\DateTime::ATOM),
        );

        $this->config->setUserValue(
            $userId,
            'pipelinq',
            'email_sync_last_count',
            (string) $count,
        );

        if ($error !== null) {
            $this->config->setUserValue(
                $userId,
                'pipelinq',
                'email_sync_last_error',
                $error,
            );
        } else {
            $this->config->deleteUserValue($userId, 'pipelinq', 'email_sync_last_error');
        }
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
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
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
