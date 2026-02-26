<?php

/**
 * Pipelinq ContactSyncService.
 *
 * Service for searching and importing Nextcloud contacts into Pipelinq.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\Contacts\IManager as IContactsManager;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for searching and importing Nextcloud contacts into Pipelinq.
 */
class ContactSyncService
{
    /**
     * Constructor.
     *
     * @param IContactsManager         $contactsManager      The contacts manager.
     * @param ContactImportService     $contactImportService The contact import service.
     * @param ContactVcardService      $contactVcardService  The vCard sync service.
     * @param ContactLinkedUidsService $linkedUidsService    The linked UIDs service.
     * @param LoggerInterface          $logger               The logger.
     */
    public function __construct(
        private IContactsManager $contactsManager,
        private ContactImportService $contactImportService,
        private ContactVcardService $contactVcardService,
        private ContactLinkedUidsService $linkedUidsService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Search Nextcloud addressbooks for contacts matching a query.
     * Returns results with an `alreadyLinked` flag if a Pipelinq object has the same contactsUid.
     *
     * @param string $query The search query.
     *
     * @return array The matching contacts.
     */
    public function searchContacts(string $query): array
    {
        if ($this->contactsManager->isEnabled() === false) {
            return [];
        }

        $results = $this->contactsManager->search($query, ['FN', 'EMAIL', 'TEL', 'ORG'], ['limit' => 50]);

        $linkedUids = $this->linkedUidsService->getLinkedContactsUids();

        return $this->buildContactResults(
            results: $results,
            linkedUids: $linkedUids
        );
    }//end searchContacts()

    /**
     * Import a Nextcloud contact into Pipelinq as a client or contact.
     *
     * @param string  $uid            The contact UID.
     * @param string  $addressBookKey The addressbook key.
     * @param string  $type           The import type (client or contact).
     * @param ?string $clientId       The optional client ID for contact imports.
     *
     * @return array The created object data.
     */
    public function importContact(string $uid, string $addressBookKey, string $type='client', ?string $clientId=null): array
    {
        if ($this->contactsManager->isEnabled() === false) {
            throw new RuntimeException('Nextcloud Contacts is not available');
        }

        $ncContact = $this->findContactByUid(uid: $uid);

        if ($ncContact === null) {
            throw new RuntimeException('Contact not found in Nextcloud addressbook');
        }

        if ($type === 'client') {
            return $this->contactImportService->importAsClient(
                ncContact: $ncContact,
                uid: $uid
            );
        }

        return $this->contactImportService->importAsContact(
            ncContact: $ncContact,
            uid: $uid,
            clientId: $clientId
        );
    }//end importContact()

    /**
     * Sync a Pipelinq client or contact to Nextcloud Contacts.
     * Delegates to the ContactVcardService.
     *
     * @param string $objectType The object type (client or contact).
     * @param string $objectId   The object ID.
     *
     * @return ?string The contacts UID or null.
     */
    public function syncToContacts(string $objectType, string $objectId): ?string
    {
        return $this->contactVcardService->syncToContacts(
            objectType: $objectType,
            objectId: $objectId
        );
    }//end syncToContacts()

    /**
     * Build contact result entries from raw search results.
     *
     * @param array $results    The raw contact search results.
     * @param array $linkedUids The already-linked UIDs.
     *
     * @return array The formatted contact results.
     */
    private function buildContactResults(array $results, array $linkedUids): array
    {
        $contacts = [];
        foreach ($results as $result) {
            $uid = $result['UID'] ?? null;
            if ($uid === null) {
                continue;
            }

            $contacts[] = $this->formatContactResult(
                result: $result,
                uid: $uid,
                linkedUids: $linkedUids
            );
        }

        return $contacts;
    }//end buildContactResults()

    /**
     * Format a single contact result entry.
     *
     * @param array  $result     The raw contact result.
     * @param string $uid        The contact UID.
     * @param array  $linkedUids The linked UIDs set.
     *
     * @return array The formatted contact entry.
     */
    private function formatContactResult(array $result, string $uid, array $linkedUids): array
    {
        return [
            'uid'            => $uid,
            'name'           => $this->extractFirstValue(value: ($result['FN'] ?? '')),
            'email'          => $this->extractFirstValue(value: ($result['EMAIL'] ?? '')),
            'phone'          => $this->extractFirstValue(value: ($result['TEL'] ?? '')),
            'org'            => $this->extractFirstValue(value: ($result['ORG'] ?? '')),
            'addressBookKey' => $result['addressbook-key'] ?? '',
            'alreadyLinked'  => in_array($uid, $linkedUids, true),
        ];
    }//end formatContactResult()

    /**
     * Find a Nextcloud contact by its UID.
     *
     * @param string $uid The contact UID to find.
     *
     * @return ?array The contact data or null if not found.
     */
    private function findContactByUid(string $uid): ?array
    {
        $results = $this->contactsManager->search($uid, ['UID'], ['limit' => 1]);

        foreach ($results as $r) {
            if (($r['UID'] ?? '') === $uid) {
                return $r;
            }
        }

        return null;
    }//end findContactByUid()

    /**
     * Extract first value from a vCard property that may be an array or string.
     *
     * @param mixed $value The value to extract from.
     *
     * @return string The extracted string value.
     */
    private function extractFirstValue(mixed $value): string
    {
        if (is_array($value) === true) {
            return (string) ($value[0] ?? '');
        }

        return (string) $value;
    }//end extractFirstValue()
}//end class
