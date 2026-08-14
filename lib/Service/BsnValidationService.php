<?php

/**
 * Pipelinq BsnValidationService.
 *
 * Implements the RvIG-documented 11-proef (eleven-test) algorithm for Burgerservicenummer (BSN)
 * formal validity. Stateless, deterministic, no external calls.
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
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Stateless BSN validator.
 *
 * @see https://nl.wikipedia.org/wiki/Burgerservicenummer for the 11-proef definition.
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-001
 */
class BsnValidationService {
	/**
	 * Validation result error: input length / character composition.
	 */
	public const ERROR_LENGTH = 'length';

	/**
	 * Validation result error: 11-proef checksum mismatch.
	 */
	public const ERROR_CHECKSUM = 'checksum';

	/**
	 * Validate a BSN against the formal 11-proef.
	 *
	 * Returns an associative array with:
	 *  - isFormeelGeldig: bool
	 *  - elfproefScore:   int (0 when valid)
	 *  - errorCode:       null | self::ERROR_LENGTH | self::ERROR_CHECKSUM
	 *  - errorMessage:    null | string (Dutch)
	 *  - maskedBsn:       string (***XXXX* style — empty when input too short to mask)
	 *
	 * The method is pure and never logs the raw BSN. Callers MUST never persist
	 * `$bsnInput` itself; only the masked variant and the SHA-256 hash are safe to store.
	 *
	 * @param string $bsnInput Raw 9-digit BSN candidate (caller-trimmed).
	 *
	 * @return array{isFormalValid: bool, elfproefScore: int, errorCode: ?string, errorMessage: ?string, maskedBsn: string}
	 *
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-001-01
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-001-02
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-001-03
	 */
	public function validate(string $bsnInput): array {
		// 1. Length / character check (REQ-BSN-001-03 — short-circuits 11-proef).
		if (strlen($bsnInput) !== 9 || ctype_digit($bsnInput) === false) {
			return [
				'isFormalValid' => false,
				'elfproefScore' => -1,
				'errorCode' => self::ERROR_LENGTH,
				'errorMessage' => 'Een BSN bestaat uit exact 9 cijfers.',
				'maskedBsn' => self::mask(bsnInput: $bsnInput),
			];
		}

		// 2. 11-proef: sum(digit[i] * (9-i)) for i=0..7, plus digit[8] * -1; sum mod 11 == 0.
		$sum = 0;
		for ($i = 0; $i < 8; $i++) {
			$sum += ((int)$bsnInput[$i]) * (9 - $i);
		}

		// Last digit uses weight -1.
		$sum -= (int)$bsnInput[8];

		$modulo = $sum % 11;
		$isValid = ($modulo === 0);

		$errorCode = null;
		$errorMessage = null;
		if ($isValid === false) {
			$errorCode = self::ERROR_CHECKSUM;
			$errorMessage = 'Dit BSN voldoet niet aan de 11-proef (controlesom fout).';
		}

		return [
			'isFormalValid' => $isValid,
			'elfproefScore' => $modulo,
			'errorCode' => $errorCode,
			'errorMessage' => $errorMessage,
			'maskedBsn' => self::mask(bsnInput: $bsnInput),
		];
	}//end validate()

	/**
	 * Mask a BSN for safe display / logging (ADR-005, REQ-BSN-009-01).
	 *
	 * Returns `***{last1digit}` when input has at least 1 digit. Otherwise an empty string.
	 * Pattern matches the design.md convention `***45678*`.
	 *
	 * @param string $bsnInput Raw BSN or any string that should be masked.
	 *
	 * @return string The masked variant safe for logging / UI / audit storage.
	 *
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-009-01
	 */
	public static function mask(string $bsnInput): string {
		$length = strlen($bsnInput);
		if ($length === 0) {
			return '';
		}

		if ($length < 5) {
			return str_repeat('*', $length);
		}

		// Reveal characters 3..7 (1-indexed): chars at index 3..6.
		$reveal = substr($bsnInput, 3, 4);
		return '***' . $reveal . '*';
	}//end mask()

	/**
	 * Hash a BSN with SHA-256 for persistent storage.
	 *
	 * The hash is deterministic (same BSN → same hash) so it can be used as an index key,
	 * but irreversible (one-way) so a database leak does not expose raw BSNs.
	 *
	 * Callers comparing two hashes MUST use {@see hash_equals()} to prevent timing attacks.
	 *
	 * @param string $bsnInput Raw BSN.
	 *
	 * @return string 64-character hex SHA-256 digest.
	 *
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-009-01
	 */
	public static function hash(string $bsnInput): string {
		return hash('sha256', $bsnInput);
	}//end hash()
}//end class
