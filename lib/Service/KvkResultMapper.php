<?php

/**
 * Pipelinq KvkResultMapper.
 *
 * Maps KVK API results to the internal prospect format.
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
 * Mapper for KVK API results to prospect format.
 */
class KvkResultMapper
{
    /**
     * Map a KVK API result to our prospect format.
     *
     * @param array  $item    The raw API result item.
     * @param string $sbiCode The SBI code that matched.
     *
     * @return array|null The mapped result or null.
     */
    public function mapResult(array $item, string $sbiCode): ?array
    {
        $kvkNumber = $item['kvkNummer'] ?? null;
        if ($kvkNumber === null) {
            return null;
        }

        return [
            'kvkNumber'        => (string) $kvkNumber,
            'tradeName'        => $this->extractTradeName(item: $item),
            'legalForm'        => $item['rechtsvorm'] ?? '',
            'sbiCode'          => $sbiCode,
            'sbiDescription'   => $this->findSbiDescription(item: $item, sbiCode: $sbiCode),
            'employeeCount'    => $item['totaalWerkzamePersonen'] ?? null,
            'address'          => $this->extractAddress(item: $item),
            'website'          => null,
            'registrationDate' => $item['registratieDatum'] ?? null,
            'isActive'         => $this->isCompanyActive(item: $item),
            'source'           => 'kvk',
        ];
    }//end mapResult()

    /**
     * Extract the trade name from a KVK result.
     *
     * @param array $item The raw API result item.
     *
     * @return string The trade name.
     */
    private function extractTradeName(array $item): string
    {
        return $item['eersteHandelsnaam'] ?? ($item['naam'] ?? '');
    }//end extractTradeName()

    /**
     * Extract and map the address from a KVK result.
     *
     * @param array $item The raw API result item.
     *
     * @return array The mapped address.
     */
    private function extractAddress(array $item): array
    {
        $address = $item['adres'] ?? ($item['vestingAdres'] ?? []);

        return [
            'street'     => ($address['straatnaam'] ?? '').' '.($address['huisnummer'] ?? ''),
            'city'       => $address['plaats'] ?? '',
            'province'   => $address['provincie'] ?? '',
            'postalCode' => $address['postcode'] ?? '',
        ];
    }//end extractAddress()

    /**
     * Check if a company is active.
     *
     * @param array $item The raw API result item.
     *
     * @return bool True if company is active.
     */
    private function isCompanyActive(array $item): bool
    {
        return ($item['actief'] ?? 'Ja') === 'Ja';
    }//end isCompanyActive()

    /**
     * Find the SBI description matching the given code.
     *
     * @param array  $item    The raw API result item.
     * @param string $sbiCode The SBI code to look for.
     *
     * @return string The SBI description or empty string.
     */
    private function findSbiDescription(array $item, string $sbiCode): string
    {
        $sbiActivities = $item['spiActiviteiten'] ?? [];

        foreach ($sbiActivities as $activity) {
            $activityCode = (string) ($activity['sbiCode'] ?? '');
            if (str_starts_with(haystack: $activityCode, needle: $sbiCode) === true) {
                return $activity['sbiOmschrijving'] ?? '';
            }
        }

        return '';
    }//end findSbiDescription()
}//end class
