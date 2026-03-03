<?php

/**
 * Pipelinq ContactDataBuilder.
 *
 * Service for building data arrays from Nextcloud contact data for import.
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

/**
 * Service for building import data arrays from Nextcloud contact data.
 */
class ContactDataBuilder
{
    /**
     * Build client data from a Nextcloud contact.
     *
     * @param array  $ncContact The Nextcloud contact data.
     * @param string $uid       The contact UID.
     *
     * @return array The client data ready for saving.
     */
    public function buildClientImportData(array $ncContact, string $uid): array
    {
        $name = $this->extractFirstValue(value: ($ncContact['FN'] ?? 'Unknown'));
        $org  = $this->extractFirstValue(value: ($ncContact['ORG'] ?? ''));

        $clientType = $this->determineClientType(name: $name, org: $org);

        if ($name === '' && $org !== '') {
            $name = $org;
        }

        if ($clientType === 'person') {
            $industry = $org;
        } else {
            $industry = '';
        }

        $data = [
            'name'        => $name,
            'type'        => $clientType,
            'email'       => $this->extractFirstValue(value: ($ncContact['EMAIL'] ?? '')),
            'phone'       => $this->extractFirstValue(value: ($ncContact['TEL'] ?? '')),
            'website'     => $this->extractFirstValue(value: ($ncContact['URL'] ?? '')),
            'industry'    => $industry,
            'contactsUid' => $uid,
        ];

        $data         = array_filter($data, fn($v) => $v !== '' && $v !== null);
        $data['name'] = $name;
        $data['type'] = $clientType;

        return $data;
    }//end buildClientImportData()

    /**
     * Build contact person data from a Nextcloud contact.
     *
     * @param array   $ncContact The Nextcloud contact data.
     * @param string  $uid       The contact UID.
     * @param ?string $clientId  The optional client ID.
     *
     * @return array The contact data ready for saving.
     */
    public function buildContactImportData(array $ncContact, string $uid, ?string $clientId): array
    {
        $name = $this->extractFirstValue(value: ($ncContact['FN'] ?? 'Unknown'));

        $data = [
            'name'        => $name,
            'email'       => $this->extractFirstValue(value: ($ncContact['EMAIL'] ?? '')),
            'phone'       => $this->extractFirstValue(value: ($ncContact['TEL'] ?? '')),
            'role'        => $this->extractFirstValue(value: ($ncContact['ROLE'] ?? $ncContact['TITLE'] ?? '')),
            'contactsUid' => $uid,
        ];

        if ($clientId !== null && $clientId !== '') {
            $data['client'] = $clientId;
        }

        $data         = array_filter($data, fn($v) => $v !== '' && $v !== null);
        $data['name'] = $name;

        return $data;
    }//end buildContactImportData()

    /**
     * Determine the client type based on name and org fields.
     *
     * @param string $name The contact name.
     * @param string $org  The organization name.
     *
     * @return string The client type (person or organization).
     */
    private function determineClientType(string $name, string $org): string
    {
        if ($org !== '' && ($org === $name || $name === '')) {
            return 'organization';
        }

        return 'person';
    }//end determineClientType()

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
