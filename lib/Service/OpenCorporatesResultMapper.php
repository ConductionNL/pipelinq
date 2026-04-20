<?php

/**
 * Pipelinq OpenCorporatesResultMapper.
 *
 * Maps OpenCorporates API results to the internal prospect format.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Mapper for OpenCorporates API results to prospect format.
 */
class OpenCorporatesResultMapper
{
    /**
     * Map an OpenCorporates result to our prospect format.
     *
     * @param array $company The raw company data.
     *
     * @return array|null The mapped result or null.
     */
    public function mapResult(array $company): ?array
    {
        $companyNumber = $company['company_number'] ?? null;
        if ($companyNumber === null) {
            return null;
        }

        return [
            'kvkNumber'        => (string) $companyNumber,
            'tradeName'        => $company['name'] ?? '',
            'legalForm'        => $company['company_type'] ?? '',
            'sbiCode'          => '',
            'sbiDescription'   => $this->extractSbiDescription(company: $company),
            'employeeCount'    => null,
            'address'          => $this->mapAddress(address: $company['registered_address'] ?? []),
            'website'          => null,
            'registrationDate' => $company['incorporation_date'] ?? null,
            'isActive'         => $this->isCompanyActive(company: $company),
            'source'           => 'opencorporates',
        ];
    }//end mapResult()

    /**
     * Extract the first SBI description from industry codes.
     *
     * @param array $company The raw company data.
     *
     * @return string The SBI description or empty string.
     */
    private function extractSbiDescription(array $company): string
    {
        return $company['industry_codes'][0]['description'] ?? '';
    }//end extractSbiDescription()

    /**
     * Check if a company is active.
     *
     * @param array $company The raw company data.
     *
     * @return bool True if company is active.
     */
    private function isCompanyActive(array $company): bool
    {
        return ($company['current_status'] ?? 'Active') === 'Active';
    }//end isCompanyActive()

    /**
     * Map an OpenCorporates address to our format.
     *
     * @param array $address The raw address data.
     *
     * @return array The mapped address.
     */
    private function mapAddress(array $address): array
    {
        return [
            'street'     => $address['street_address'] ?? '',
            'city'       => $address['locality'] ?? '',
            'province'   => $address['region'] ?? '',
            'postalCode' => $address['postal_code'] ?? '',
        ];
    }//end mapAddress()
}//end class
