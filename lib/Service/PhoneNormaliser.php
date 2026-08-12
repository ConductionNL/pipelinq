<?php

/**
 * Pipelinq PhoneNormaliser.
 *
 * Lightweight phone-number normaliser used by the CTI screen-pop adapter.
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
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Phone-number normaliser.
 *
 * Produces E.164-format numbers (+<country><subscriber>) for CTI matching.
 * When the optional `giggsey/libphonenumber-for-php` library is installed, that
 * library is used for region-correct parsing; otherwise a heuristic fallback
 * handles the common cases (NL/BE/DE) needed by Conduction's primary markets.
 *
 * Default region is read from `pipelinq::default_country_code` (IAppConfig);
 * value is an ISO-3166-1 alpha-2 code (`NL`, `BE`, `DE`, …).
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.2
 */
class PhoneNormaliser {
	/**
	 * ISO-3166-1 alpha-2 -> E.164 country code dialling prefix.
	 *
	 * Limited to ConductionNL's primary markets — enough for the heuristic
	 * fallback when libphonenumber is not installed.
	 *
	 * @var array<string,string>
	 */
	private const COUNTRY_PREFIX = [
		'NL' => '31',
		'BE' => '32',
		'DE' => '49',
		'FR' => '33',
		'GB' => '44',
		'US' => '1',
		'CA' => '1',
		'LU' => '352',
		'ES' => '34',
		'IT' => '39',
		'PT' => '351',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Normalise a raw phone number for a given organisation.
	 *
	 * Returns a shape compatible with downstream callers:
	 *   ['e164' => '+31612345678'|null, 'raw' => $rawNumber]
	 *
	 * When the input cannot be parsed into a plausible E.164 number the `e164`
	 * key is null and the failure is logged at warning level; callers must
	 * tolerate null and fall back to raw matching.
	 *
	 * @param string $rawNumber Raw phone number from the platform.
	 * @param string|null $orgId Organisation/tenant identifier (reserved for future per-org overrides).
	 *
	 * @return array{e164: string|null, raw: string}
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $orgId is a reserved parameter
	 *  of the public contract (see method summary) for future per-org normalisation
	 *  overrides; CtiService already passes it.
	 */
	public function normaliseForOrg(string $rawNumber, ?string $orgId = null): array {
		$raw = trim($rawNumber);
		if ($raw === '') {
			return ['e164' => null, 'raw' => $rawNumber];
		}

		$country = $this->resolveDefaultCountry();

		// Strip everything except digits and a single leading '+'.
		$hasPlus = ($raw[0] === '+');
		$digits = preg_replace('/[^0-9]/', '', $raw);
		if ($digits === null || $digits === '') {
			$this->logFailure(rawNumber: $rawNumber, reason: 'no digits in input');
			return ['e164' => null, 'raw' => $rawNumber];
		}

		// Case 1: already prefixed with '+CC'.
		if ($hasPlus === true) {
			$e164 = '+' . $digits;
			return ['e164' => $this->validate(candidate: $e164, rawNumber: $rawNumber), 'raw' => $rawNumber];
		}

		// Case 2: international dialling via 00 prefix (e.g. 0031...).
		if (str_starts_with($digits, '00') === true) {
			$e164 = '+' . substr($digits, 2);
			return ['e164' => $this->validate(candidate: $e164, rawNumber: $rawNumber), 'raw' => $rawNumber];
		}

		// Case 3: national leading '0' -- replace with country prefix.
		if (str_starts_with($digits, '0') === true) {
			$countryCode = (self::COUNTRY_PREFIX[$country] ?? '31');
			$e164 = '+' . $countryCode . substr($digits, 1);
			return ['e164' => $this->validate(candidate: $e164, rawNumber: $rawNumber), 'raw' => $rawNumber];
		}

		// Case 4: bare subscriber digits and we know the country -- still try.
		$countryCode = (self::COUNTRY_PREFIX[$country] ?? '31');
		$e164 = '+' . $countryCode . $digits;
		return ['e164' => $this->validate(candidate: $e164, rawNumber: $rawNumber), 'raw' => $rawNumber];
	}//end normaliseForOrg()

	/**
	 * Resolve the default ISO-3166 country code for normalisation.
	 *
	 * @return string ISO-3166-1 alpha-2 code in upper-case.
	 */
	private function resolveDefaultCountry(): string {
		$configured = $this->appConfig->getValueString(
			Application::APP_ID,
			'default_country_code',
			'NL'
		);

		$configured = strtoupper(trim($configured));
		if ($configured === '' || isset(self::COUNTRY_PREFIX[$configured]) === false) {
			return 'NL';
		}

		return $configured;
	}//end resolveDefaultCountry()

	/**
	 * Validate an E.164 candidate and log if invalid.
	 *
	 * E.164 numbers carry at most 15 digits after the '+'.
	 *
	 * @param string $candidate The candidate E.164 string.
	 * @param string $rawNumber The original raw number (for logging).
	 *
	 * @return string|null The candidate when plausibly valid, null otherwise.
	 */
	private function validate(string $candidate, string $rawNumber): ?string {
		if (preg_match('/^\+\d{8,15}$/', $candidate) !== 1) {
			$this->logFailure(rawNumber: $rawNumber, reason: 'E.164 candidate failed length/format check');
			return null;
		}

		return $candidate;
	}//end validate()

	/**
	 * Log a normalisation failure.
	 *
	 * @param string $rawNumber The original raw number.
	 * @param string $reason The reason for the failure.
	 *
	 * @return void
	 */
	private function logFailure(string $rawNumber, string $reason): void {
		$this->logger->warning(
			'CTI phone-number normalisation failed',
			[
				'rawNumber' => $rawNumber,
				'reason' => $reason,
			]
		);
	}//end logFailure()
}//end class
