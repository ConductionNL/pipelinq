<?php

/**
 * Pipelinq PosUserAction.
 *
 * Runs a POS action for the authenticated user.
 *
 * @category Util
 * @package  OCA\Pipelinq\Controller\Support
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller\Support;

use OCP\AppFramework\Http\JSONResponse;

/**
 * Runs a POS action for the session user, or answers the 401. The host class
 * uses PosControllerResponses and provides a run(action, label) method.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait PosUserAction {

	/**
	 * Run an action for the authenticated user through the host's run().
	 *
	 * @param callable $action The action; it receives the acting user UID.
	 * @param string   $label  A short label for log context.
	 *
	 * @return JSONResponse The 401, or the action's response.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function runAsUser(callable $action, string $label): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		return $this->run(action: fn (): array => $action($uid), label: $label);
	}//end runAsUser()
}//end trait
