<?php

/**
 * Pipelinq PosTransitionErrors.
 *
 * Maps the failures of a POS lifecycle transition to OCS exceptions.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Support
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
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Support;

use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use ReflectionClass;

/**
 * Maps a TransitionEngine failure: an RBAC denial (NotAuthorizedException or
 * a 'permission' message) or a guard denial carrying one of TRANSITION_ACCESS_MARKERS → 403; a message
 * carrying one of TRANSITION_PRECONDITION_MARKERS → 422; 'not found' → 404;
 * anything else is rethrown as is. Matching is by class short-name and
 * message so the app does not hard-depend on OpenRegister's exception classes.
 * The host class provides the TRANSITION_FORBIDDEN,
 * TRANSITION_ACCESS_MARKERS and TRANSITION_PRECONDITION_MARKERS constants.
 *
 * @spec openspec/changes/archive/2026-06-01-pos-lifecycle-guard-adoption/tasks.md#3.3
 */
trait PosTransitionErrors {

	/**
	 * Map a TransitionEngine throwable to the correct OCS exception.
	 *
	 * @param \Throwable $e The engine throwable.
	 *
	 * @return \Throwable The mapped OCS exception (or the original on no match).
	 *
	 * @spec openspec/changes/archive/2026-06-01-pos-lifecycle-guard-adoption/tasks.md#3.3
	 */
	private function mapTransitionError(\Throwable $e): \Throwable {
		$short = (new ReflectionClass($e))->getShortName();
		$message = $e->getMessage();

		if ($short === 'NotAuthorizedException' || stripos($message, 'permission') !== false) {
			return $this->forbiddenFor(message: $message);
		}

		if ($this->mentionsAny(message: $message, markers: self::TRANSITION_ACCESS_MARKERS) === true) {
			return new OCSForbiddenException($message);
		}

		return $this->mapByMessage(e: $e, message: $message);
	}//end mapTransitionError()

	/**
	 * The 403 for an RBAC denial, carrying the engine's message when it has one.
	 *
	 * @param string $message The engine's message.
	 *
	 * @return OCSForbiddenException
	 *
	 * @spec openspec/changes/archive/2026-06-01-pos-lifecycle-guard-adoption/tasks.md#3.3
	 */
	private function forbiddenFor(string $message): OCSForbiddenException {
		$forbidden = self::TRANSITION_FORBIDDEN;
		if ($message !== '') {
			$forbidden = $message;
		}

		return new OCSForbiddenException($forbidden);
	}//end forbiddenFor()

	/**
	 * Map a non-authorization transition failure by its message: 422, 404, or the original.
	 *
	 * @param \Throwable $e The original failure.
	 * @param string $message Its message.
	 *
	 * @return \Throwable
	 *
	 * @spec openspec/changes/archive/2026-06-01-pos-lifecycle-guard-adoption/tasks.md#3.3
	 */
	private function mapByMessage(\Throwable $e, string $message): \Throwable {
		if ($this->mentionsAny(message: $message, markers: self::TRANSITION_PRECONDITION_MARKERS) === true) {
			return new OCSBadRequestException($message);
		}

		if (stripos($message, 'not found') !== false) {
			return new OCSNotFoundException($message);
		}

		return $e;
	}//end mapByMessage()

	/**
	 * Whether a message contains any of the markers, case-insensitively.
	 *
	 * @param string $message The message.
	 * @param array<int, string> $markers The markers.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-06-01-pos-lifecycle-guard-adoption/tasks.md#3.3
	 */
	private function mentionsAny(string $message, array $markers): bool {
		foreach ($markers as $marker) {
			if (stripos($message, $marker) !== false) {
				return true;
			}
		}

		return false;
	}//end mentionsAny()
}//end trait
