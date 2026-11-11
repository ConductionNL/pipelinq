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
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.2
	 */
	public function normaliseForOrg(string $rawNumber, ?string $orgId = null): array {
		$raw = trim($rawNumber);
		if ($raw === '') {
			return ['e164' => null, 'raw' => $rawNumber];
		}

		$country = $this->resolveDefaultCountry();
		$digits = preg_replace('/[^0-9]/', '', $raw);
		if ($digits === null || $digits === '') {
			$this->logFailure(rawNumber: $rawNumber, reason: 'no digits in input');
			return ['e164' => null, 'raw' => $rawNumber];
		}

		$candidate = $this->candidate(dialled: $this->dialled(raw: $raw, digits: $digits), countryCode: (self::COUNTRY_PREFIX[$country] ?? '31'));
		return ['e164' => $this->validate(candidate: $candidate, rawNumber: $rawNumber), 'raw' => $rawNumber];
	}//end normaliseForOrg()

	/**
	 * The digits as dialled: a leading '+' is the same as dialling 00.
	 *
	 * @param string $raw    The trimmed input.
	 * @param string $digits Its digits.
	 *
	 * @return string
	 */
	private function dialled(string $raw, string $digits): string {
		if ($raw[0] === '+') {
			return '00' . $digits;
		}

		return $digits;
	}//end dialled()

	/**
	 * The E.164 candidate of dialled digits: international (00) as it is,
	 * national (leading 0) with the country code instead of the 0, bare
	 * subscriber digits with the country code in front.
	 *
	 * @param string $dialled     The dialled digits.
	 * @param string $countryCode The default country's code.
	 *
	 * @return string
	 */
	private function candidate(string $dialled, string $countryCode): string {
		if (str_starts_with($dialled, '00') === true) {
			return '+' . substr($dialled, 2);
		}

		if (str_starts_with($dialled, '0') === true) {
			return '+' . $countryCode . substr($dialled, 1);
		}

		return '+' . $countryCode . $dialled;
	}//end candidate()

	/**
	 * Normalise the sender of an inbound SMS or WhatsApp callback to E.164.
	 *
	 * Providers name the sender internationally, and Meta and MessageBird
	 * leave out the '+' (31611119999). Read as a national number that would
	 * get the country prefix twice, so bare digits that do not start with 0
	 * are taken as international. A sender that is not a phone number is
	 * returned trimmed, so the caller still has an address to record.
	 *
	 * @param string $rawNumber Sender as the provider sent it.
	 *
	 * @return string E.164 number, or the trimmed input when it is none.
	 *
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
	 */
	public function normaliseInbound(string $rawNumber): string {
		$raw = trim($rawNumber);
		if ($raw === '') {
			return '';
		}

		$candidate = $raw;
		if (preg_match('/^[1-9][0-9 ]*$/', $raw) === 1) {
			$candidate = '+' . $raw;
		}

		return ($this->normaliseForOrg(rawNumber: $candidate)['e164'] ?? $raw);
	}//end normaliseInbound()

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
