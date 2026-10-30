<?php

/**
 * Pipelinq PhoneNeedles.
 *
 * The number fragments a stored phone number is matched on.
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
 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * One set of fragments for contacts, clients and tickets, so the screen pop and
 * the phone search find the same records.
 *
 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
 */
final class PhoneNeedles {
	/**
	 * The fragments of a normalised number: the E.164 value, its digits, and
	 * its last 9 and last 8 digits.
	 *
	 * @param string $e164 Normalised E.164 number.
	 *
	 * @return array<int,string> The fragments, without duplicates or empties.
	 *
	 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
	 */
	public function for(string $e164): array {
		$digitsOnly = preg_replace('/[^0-9]/', '', $e164) ?? '';
		$needles = [$e164, $digitsOnly];
		if (strlen($digitsOnly) >= 9) {
			$needles[] = substr($digitsOnly, -9);
		}

		if (strlen($digitsOnly) >= 8) {
			$needles[] = substr($digitsOnly, -8);
		}

		return array_values(array_unique(array_filter($needles)));
	}//end for()
}//end class
