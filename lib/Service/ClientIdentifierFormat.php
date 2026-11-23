<?php

/**
 * Pipelinq ClientIdentifierFormat.
 *
 * The format rules of an organisation's official numbers: KvK, RSIN, VAT,
 * DUNS and OIN. How a number is normalised, the RSIN eleven test and the
 * per-country VAT pattern, with the reason a number fails in the user's
 * language. Checked on every save; no register is asked here.
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
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/specs/client-registry-numbers/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IL10N;

/**
 * Normalises and validates the numbers a client's identifiers carry.
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/specs/client-registry-numbers/spec.md
 */
class ClientIdentifierFormat {

	/**
	 * The schemes an identifier may carry.
	 *
	 * @var array<int, string>
	 */
	public const SCHEMES = ['kvk', 'rsin', 'vat', 'duns', 'oin'];

	/**
	 * The digit-only formats, by scheme.
	 *
	 * @var array<string, string>
	 */
	private const DIGIT_FORMATS = [
		'kvk' => '/^[0-9]{8}$/',
		'rsin' => '/^[0-9]{9}$/',
		'duns' => '/^[0-9]{9}$/',
		'oin' => '/^[0-9]{20}$/',
	];

	/**
	 * The national part of an EU VAT number, by country prefix (VIES formats).
	 *
	 * @var array<string, string>
	 */
	private const VAT_PATTERNS = [
		'AT' => 'U[0-9]{8}',
		'BE' => '[01][0-9]{9}',
		'BG' => '[0-9]{9,10}',
		'CY' => '[0-9]{8}[A-Z]',
		'CZ' => '[0-9]{8,10}',
		'DE' => '[0-9]{9}',
		'DK' => '[0-9]{8}',
		'EE' => '[0-9]{9}',
		'EL' => '[0-9]{9}',
		'ES' => '[0-9A-Z][0-9]{7}[0-9A-Z]',
		'FI' => '[0-9]{8}',
		'FR' => '[0-9A-HJ-NP-Z]{2}[0-9]{9}',
		'HR' => '[0-9]{11}',
		'HU' => '[0-9]{8}',
		'IE' => '([0-9]{7}[A-W][A-I]?|[0-9][A-Z+*][0-9]{5}[A-W])',
		'IT' => '[0-9]{11}',
		'LT' => '([0-9]{9}|[0-9]{12})',
		'LU' => '[0-9]{8}',
		'LV' => '[0-9]{11}',
		'MT' => '[0-9]{8}',
		'NL' => '[0-9]{9}B[0-9]{2}',
		'PL' => '[0-9]{10}',
		'PT' => '[0-9]{9}',
		'RO' => '[0-9]{2,10}',
		'SE' => '[0-9]{12}',
		'SI' => '[0-9]{8}',
		'SK' => '[0-9]{10}',
		'XI' => '([0-9]{9}|[0-9]{12}|GD[0-9]{3}|HA[0-9]{3})',
	];

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n The user's language.
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The number as it is compared and stored: without spaces and dots, VAT in capitals.
	 *
	 * @param string $scheme The scheme.
	 * @param string $value  The number as typed.
	 *
	 * @return string The normalised number.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.1
	 */
	public function normalise(string $scheme, string $value): string {
		$plain = str_replace([' ', '.', '-'], '', trim($value));
		if ($scheme === 'vat') {
			return strtoupper($plain);
		}

		return $plain;
	}//end normalise()

	/**
	 * Whether nine digits pass the eleven test the RSIN shares with the BSN.
	 *
	 * The first eight digits weigh 9 down to 2, the last weighs -1; the sum must
	 * be a multiple of eleven.
	 *
	 * @param string $digits Nine digits.
	 *
	 * @return bool True when the number passes.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.1
	 */
	public function passesElevenTest(string $digits): bool {
		if (preg_match('/^[0-9]{9}$/', $digits) !== 1) {
			return false;
		}

		$sum = 0;
		for ($position = 0; $position < 8; $position++) {
			$sum += ((int)$digits[$position] * (9 - $position));
		}

		$sum -= (int)$digits[8];
		return ($sum % 11) === 0;
	}//end passesElevenTest()

	/**
	 * Why a number does not match its scheme's format, or null when it does.
	 *
	 * @param string $scheme The scheme: kvk, rsin, vat, duns or oin.
	 * @param string $value  The number.
	 *
	 * @return string|null The problem, in the user's language, or null.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.1
	 */
	public function validateFormat(string $scheme, string $value): ?string {
		if (in_array($scheme, self::SCHEMES, true) === false) {
			return $this->l10n->t('"%s" is not a kind of number pipelinq keeps.', [$scheme]);
		}

		$number = $this->normalise(scheme: $scheme, value: $value);
		if ($scheme === 'vat') {
			return $this->vatProblem(number: $number);
		}

		if (preg_match(self::DIGIT_FORMATS[$scheme], $number) !== 1) {
			return $this->digitsProblem(scheme: $scheme);
		}

		if ($scheme === 'rsin' && $this->passesElevenTest(digits: $number) === false) {
			return $this->l10n->t('The RSIN %s is not valid: it fails the eleven test.', [$number]);
		}

		return null;
	}//end validateFormat()

	/**
	 * Every format problem in a client's identifiers list.
	 *
	 * @param mixed $identifiers The client's `identifiers` value.
	 *
	 * @return array<int, string> The problems; empty when the list is valid.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.1
	 */
	public function problemsIn(mixed $identifiers): array {
		if ($identifiers === null || $identifiers === []) {
			return [];
		}

		if (is_array($identifiers) === false || array_is_list($identifiers) === false) {
			return [$this->l10n->t('The identifiers must be a list.')];
		}

		$problems = [];
		foreach ($identifiers as $identifier) {
			$problem = $this->entryProblem(identifier: $identifier);
			if ($problem !== null) {
				$problems[] = $problem;
			}
		}

		return array_values(array_unique($problems));
	}//end problemsIn()

	/**
	 * The problem with one entry of the identifiers list, or null.
	 *
	 * @param mixed $identifier The entry.
	 *
	 * @return string|null The problem.
	 */
	private function entryProblem(mixed $identifier): ?string {
		if (is_array($identifier) === false) {
			return $this->l10n->t('Each identifier needs a kind of number and a number.');
		}

		return $this->validateFormat(scheme: (string)($identifier['scheme'] ?? ''), value: (string)($identifier['value'] ?? ''));
	}//end entryProblem()

	/**
	 * The digit count a digit-only scheme asks for, as a sentence.
	 *
	 * @param string $scheme The scheme: kvk, rsin, duns or oin.
	 *
	 * @return string The problem.
	 */
	private function digitsProblem(string $scheme): string {
		return [
			'kvk' => $this->l10n->t('A KvK number has 8 digits.'),
			'rsin' => $this->l10n->t('An RSIN has 9 digits.'),
			'duns' => $this->l10n->t('A DUNS number has 9 digits.'),
			'oin' => $this->l10n->t('An OIN has 20 digits.'),
		][$scheme];
	}//end digitsProblem()

	/**
	 * Why a VAT number does not match its country's format, or null.
	 *
	 * @param string $number The normalised VAT number.
	 *
	 * @return string|null The problem.
	 */
	private function vatProblem(string $number): ?string {
		$country = substr($number, 0, 2);
		if (isset(self::VAT_PATTERNS[$country]) === false) {
			return $this->l10n->t('A VAT number starts with the two letters of an EU country, such as NL or DE.');
		}

		if (preg_match('/^' . self::VAT_PATTERNS[$country] . '$/', substr($number, 2)) !== 1) {
			return $this->l10n->t('%s is not a valid VAT number for country %s.', [$number, $country]);
		}

		return null;
	}//end vatProblem()
}//end class
