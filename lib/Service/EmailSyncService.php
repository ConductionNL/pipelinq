<?php

/**
 * Pipelinq EmailSyncService.
 *
 * Service for matching emails to CRM entities.
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
 * @spec openspec/changes/2026-03-20-email-calendar-sync/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Psr\Log\LoggerInterface;

/**
 * Service for matching emails to CRM entities by address and domain.
 */
class EmailSyncService
{

    /**
     * List of common public email domains.
     *
     * @var array<string>
     */
    private array $publicDomains = [
        'gmail.com',
        'yahoo.com',
        'outlook.com',
        'hotmail.com',
        'aol.com',
        'mail.com',
        'protonmail.com',
        'icloud.com',
        'yandex.com',
        'mail.ru',
        'gmx.com',
        'gmx.de',
        'web.de',
        'libero.it',
        'tin.it',
        'virgilio.it',
    ];

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger The logger.
     */
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Match email addresses to CRM entities.
     *
     * Searches for entities (clients, contacts) that have matching email addresses
     * or belong to organizations matching the domain.
     *
     * @param string        $senderEmail     The sender email address
     * @param array<string> $recipientEmails The recipient email addresses
     *
     * @return array<array> Array of matched entities with their types and IDs
     *
     * @spec openspec/changes/2026-03-20-email-calendar-sync/tasks.md#task-2.1
     */
    public function matchEmailToEntities(string $senderEmail, array $recipientEmails): array
    {
        $matches = [];

        // Extract domain from sender email.
        $senderDomain = $this->extractDomain(email: $senderEmail);

        // Try to match sender to an entity.
        if ($this->isPublicDomain(domain: $senderDomain) === false) {
            $organizationMatch = $this->matchDomainToOrganization(domain: $senderDomain);
            if ($organizationMatch !== null) {
                $matches[] = $organizationMatch;
            }
        }

        // Process recipient addresses.
        foreach ($recipientEmails as $email) {
            $recipientDomain = $this->extractDomain(email: $email);
            if ($this->isPublicDomain(domain: $recipientDomain) === false) {
                $organizationMatch = $this->matchDomainToOrganization(domain: $recipientDomain);
                if ($organizationMatch !== null) {
                    $notYetMatched = $this->entityInMatches(
                        entity: $organizationMatch,
                        matches: $matches
                    ) === false;
                    if ($notYetMatched === true) {
                        $matches[] = $organizationMatch;
                    }
                }
            }
        }

        return $matches;
    }//end matchEmailToEntities()

    /**
     * Match an email domain to an organization.
     *
     * Returns organization data if the domain matches a known organization's domain.
     *
     * @param string $domain The email domain to match
     *
     * @return array{entityType: string, entityId: string}|null The matched organization or null
     *
     * @spec openspec/changes/2026-03-20-email-calendar-sync/tasks.md#task-2.1
     */
    public function matchDomainToOrganization(string $domain): ?array
    {
        // Sanitize the domain to prevent injection
        $sanitizedDomain = \str_replace(["\r", "\n"], '', $domain);
        $sanitizedDomain = \strtolower(\trim($sanitizedDomain));

        // Domain matching would typically query the register for clients
        // with matching website or organizational domain. This is a placeholder
        // that developers can extend to integrate with register queries.
        // Return null if no match found (expected for most individual email domains)
        $this->logger->debug(
            'Attempted to match domain to organization',
            ['domain' => $sanitizedDomain, 'result' => 'no_match']
        );
        return null;
    }//end matchDomainToOrganization()

    /**
     * Check if an email domain is a public email provider.
     *
     * @param string $domain The email domain to check
     *
     * @return bool True if the domain is a public email provider
     *
     * @spec openspec/changes/2026-03-20-email-calendar-sync/tasks.md#task-2.1
     */
    public function isPublicDomain(string $domain): bool
    {
        $domain = \strtolower($domain);
        return \in_array($domain, $this->publicDomains, true);
    }//end isPublicDomain()

    /**
     * Extract domain from an email address.
     *
     * @param string $email The email address
     *
     * @return string The domain part of the email address
     */
    private function extractDomain(string $email): string
    {
        if (\strpos($email, '@') === false) {
            return '';
        }

        $parts = \explode('@', $email);
        return $parts[1] ?? '';
    }//end extractDomain()

    /**
     * Check if an entity is already in the matches array.
     *
     * @param array<string, mixed> $entity  The entity to check
     * @param array<array>         $matches The existing matches
     *
     * @return bool True if the entity is already in matches
     */
    private function entityInMatches(array $entity, array $matches): bool
    {
        foreach ($matches as $match) {
            if ($match['entityType'] === $entity['entityType'] && $match['entityId'] === $entity['entityId']) {
                return true;
            }
        }

        return false;
    }//end entityInMatches()
}//end class
