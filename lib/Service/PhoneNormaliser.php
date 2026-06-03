<?php

/**
 * Pipelinq PhoneNormaliser.
 *
 * Normalises raw telephone numbers to E.164 for reliable contact matching.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-6.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Phone-number normaliser.
 *
 * Produces an E.164 representation (`+<country><subscriber>`) for the formats
 * Pipelinq encounters in practice — fully-qualified international (`+31…`),
 * `00`-prefixed international (`0031…`), and national numbers with a trunk
 * `0` resolved against the org's default country (REQ-CTI-002).
 *
 * This implementation deliberately avoids an external libphonenumber
 * dependency: the supported set of countries is small and well-defined, and
 * the org default country is admin-configured. Unrecognised input never throws
 * — it yields a null E.164 and preserves the raw number for forensics.
 */
class PhoneNormaliser
{
    /**
     * Default country code used when a national number has no country prefix.
     *
     * @var string
     */
    private const DEFAULT_COUNTRY = 'NL';

    /**
     * Map of ISO 3166 alpha-2 country code to international dialling prefix.
     *
     * @var array<string, string>
     */
    private const COUNTRY_DIAL_CODES = [
        'NL' => '31',
        'BE' => '32',
        'DE' => '49',
        'FR' => '33',
        'GB' => '44',
        'US' => '1',
        'ES' => '34',
        'IT' => '39',
        'LU' => '352',
    ];

    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig The app config.
     */
    public function __construct(
        private IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * Normalise a raw number to E.164 using the org's default country.
     *
     * @param string $rawNumber The raw number as received.
     *
     * @return array{e164: string|null, raw: string} The normalised result; `e164`
     *                                               is null when the number cannot
     *                                               be parsed.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-6.3
     */
    public function normalise(string $rawNumber): array
    {
        $raw     = trim($rawNumber);
        $country = $this->defaultCountry();

        $e164 = $this->toE164(rawNumber: $raw, country: $country);

        return [
            'e164' => $e164,
            'raw'  => $rawNumber,
        ];
    }//end normalise()

    /**
     * The org's configured default country (ISO alpha-2), defaulting to NL.
     *
     * @return string The default country code.
     */
    public function defaultCountry(): string
    {
        $configured = strtoupper(
            $this->appConfig->getValueString(Application::APP_ID, 'default_country_code', self::DEFAULT_COUNTRY)
        );

        if (isset(self::COUNTRY_DIAL_CODES[$configured]) === false) {
            return self::DEFAULT_COUNTRY;
        }

        return $configured;
    }//end defaultCountry()

    /**
     * Convert a raw number to E.164, or null when it cannot be parsed.
     *
     * @param string $rawNumber The raw number.
     * @param string $country   The default country (ISO alpha-2).
     *
     * @return string|null The E.164 number, or null.
     */
    private function toE164(string $rawNumber, string $country): ?string
    {
        if ($rawNumber === '') {
            return null;
        }

        // A bare "+" prefix is already (almost) E.164: strip non-digits after it.
        if (str_starts_with($rawNumber, '+') === true) {
            $digits = preg_replace('/\D+/', '', substr($rawNumber, 1));
            return $this->validE164(digits: (string) $digits);
        }

        // Strip every non-digit; reject if any letters were present (unparseable).
        if (preg_match('/[A-Za-z]/', $rawNumber) === 1) {
            return null;
        }

        $digits = (string) preg_replace('/\D+/', '', $rawNumber);
        if ($digits === '') {
            return null;
        }

        // "00" international prefix → drop it, treat remainder as country+subscriber.
        if (str_starts_with($digits, '00') === true) {
            return $this->validE164(digits: substr($digits, 2));
        }

        $dialCode = self::COUNTRY_DIAL_CODES[$country];

        // National number with a trunk "0" → replace trunk with the country code.
        if (str_starts_with($digits, '0') === true) {
            return $this->validE164(digits: $dialCode.substr($digits, 1));
        }

        // No trunk prefix and not international: assume national subscriber number.
        return $this->validE164(digits: $dialCode.$digits);
    }//end toE164()

    /**
     * Validate the assembled digit string and format it as E.164.
     *
     * E.164 numbers carry at most 15 digits and at least a country code plus a
     * short subscriber part.
     *
     * @param string $digits The country+subscriber digits (no plus, no spaces).
     *
     * @return string|null The "+<digits>" E.164 string, or null when out of range.
     */
    private function validE164(string $digits): ?string
    {
        $length = strlen($digits);
        if ($length < 8 || $length > 15) {
            return null;
        }

        return '+'.$digits;
    }//end validE164()
}//end class
