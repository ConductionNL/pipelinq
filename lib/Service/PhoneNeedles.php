<?php

/**
 * Pipelinq PhoneNeedles.
 *
 * The number fragments a stored phone number is matched on, shared by the
 * screen pop and the phone search over tickets.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Builds the fragments a normalised number is looked for by: the E.164 value,
 * its digits, and its last nine and last eight digits.
 *
 * One matcher for contacts, clients and tickets (design D1), so a number found
 * by the screen pop is found the same way on the Tickets list.
 *
 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.1
 */
final class PhoneNeedles {

	/**
	 * The fragments to test stored numbers against, most specific first.
	 *
	 * @param string $e164 Normalised E.164 number.
	 *
	 * @return array<int,string> Unique, non-empty fragments.
	 *
	 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.1
	 */
	public static function for(string $e164): array {
		$digitsOnly = preg_replace('/[^0-9]/', '', $e164) ?? '';
		$needles    = [$e164, $digitsOnly];
		if (strlen($digitsOnly) >= 9) {
			$needles[] = substr($digitsOnly, -9);
		}

		if (strlen($digitsOnly) >= 8) {
			$needles[] = substr($digitsOnly, -8);
		}

		return array_values(array_unique(array_filter($needles)));
	}//end for()

	/**
	 * The last nine digits of a number, the fragment a number stored in any
	 * format still shares with its E.164 form; null when it has fewer.
	 *
	 * @param string $number Any phone number.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.4
	 */
	public static function tail(string $number): ?string {
		$digitsOnly = preg_replace('/[^0-9]/', '', $number) ?? '';
		if (strlen($digitsOnly) < 9) {
			return null;
		}

		return substr($digitsOnly, -9);
	}//end tail()
}//end class
