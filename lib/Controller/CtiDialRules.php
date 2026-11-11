<?php

/**
 * Pipelinq CtiDialRules.
 *
 * What click-to-dial accepts as a dial string before anything reaches the PBX.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The required, dialable-number and dialable-extension checks of click-to-dial.
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-4.1
 */
final class CtiDialRules {

	/**
	 * The 400 for a missing or undialable target number or extension, or null when both may be dialled.
	 *
	 * @param string $targetNumber The number to dial.
	 * @param string $extension    The agent's extension.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-4.1
	 */
	public function refusal(string $targetNumber, string $extension): ?JSONResponse {
		if ($targetNumber === '' || $extension === '') {
			return new JSONResponse(
				['error' => 'targetNumber and extension are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		// 🔴 NON-EMPTY IS NOT DIALABLE.
		//
		// Both values were forwarded to the telephony adapter verbatim once
		// they were non-empty, so anything at all reached the PBX --
		// 'sip:attacker@evil.example' among them. What a dial string can make a
		// PBX do is the adapter's business and varies per platform, which is
		// exactly why the app must not hand it arbitrary text.
		//
		// A dialable target is E.164, optionally with the separators a person
		// might paste from a contact card, and an extension is digits. Anything
		// else is refused here rather than interpreted downstream.
		if (preg_match('/^\+?[0-9][0-9 ()-]{1,20}$/', $targetNumber) !== 1) {
			return new JSONResponse(
				['error' => 'targetNumber is not a dialable number'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if (preg_match('/^[0-9]{1,10}$/', $extension) !== 1) {
			return new JSONResponse(
				['error' => 'extension is not a dialable extension'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end refusal()
}//end class
