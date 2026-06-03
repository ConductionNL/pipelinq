<?php

/**
 * Pipelinq CostEstimationService.
 *
 * Estimates message cost from a static price table for providers that do not
 * expose per-message cost (e.g. Meta WhatsApp Cloud API).
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-5.6
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Static price-table cost estimation (REQ-007).
 *
 * Meta Cloud API prices per conversation by template category and destination
 * country. The figures below are indicative EUR rates used for budget tracking
 * when no exact cost is exposed; estimated costs are flagged so they can be
 * reconciled against the provider's invoice later.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-5.6
 */
class CostEstimationService
{
    /**
     * Indicative EUR price per message, keyed by category then ISO country.
     *
     * @var array<string, array<string, float>>
     */
    private const PRICE_TABLE = [
        'marketing'      => [
            'NL'      => 0.0768,
            'BE'      => 0.0654,
            'DE'      => 0.0768,
            'DEFAULT' => 0.0500,
        ],
        'utility'        => [
            'NL'      => 0.0120,
            'BE'      => 0.0110,
            'DE'      => 0.0120,
            'DEFAULT' => 0.0150,
        ],
        'authentication' => [
            'NL'      => 0.0331,
            'BE'      => 0.0300,
            'DE'      => 0.0331,
            'DEFAULT' => 0.0300,
        ],
    ];

    /**
     * Default category used when the message category is unknown.
     *
     * @var string
     */
    private const DEFAULT_CATEGORY = 'utility';

    /**
     * Estimate the EUR cost of a message by category and destination country.
     *
     * @param string $category The template category.
     * @param string $country  The destination ISO 3166 alpha-2 country code.
     *
     * @return float The estimated EUR cost.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-5.6
     */
    public function estimate(string $category, string $country): float
    {
        $category = strtolower(trim($category));
        if (isset(self::PRICE_TABLE[$category]) === false) {
            $category = self::DEFAULT_CATEGORY;
        }

        $country = strtoupper(trim($country));
        $table   = self::PRICE_TABLE[$category];

        return ($table[$country] ?? $table['DEFAULT']);
    }//end estimate()

    /**
     * Derive the ISO country code from an E.164 number (best-effort).
     *
     * Only the dialling prefixes Pipelinq encounters in practice are mapped;
     * unknown prefixes yield an empty string so the DEFAULT price applies.
     *
     * @param string $e164 The recipient E.164 number.
     *
     * @return string The ISO alpha-2 country code, or empty string.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-5.6
     */
    public function countryFromE164(string $e164): string
    {
        $digits = ltrim(trim($e164), '+');
        $map    = [
            '31' => 'NL',
            '32' => 'BE',
            '49' => 'DE',
            '33' => 'FR',
            '44' => 'GB',
        ];

        foreach ($map as $prefix => $country) {
            if (str_starts_with($digits, (string) $prefix) === true) {
                return $country;
            }
        }

        return '';
    }//end countryFromE164()
}//end class
