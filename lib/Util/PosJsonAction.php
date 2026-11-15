<?php

/**
 * Pipelinq PosJsonAction.
 *
 * A POS controller action answered as JSON with the shared error mapping.
 *
 * @category Util
 * @package  OCA\Pipelinq\Util
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

namespace OCA\Pipelinq\Util;

use OCP\AppFramework\Http\JSONResponse;

/**
 * Runs an action and answers its result as JSON, mapping failures through the
 * host's posResponses() and logging them under the host class's short name.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait PosJsonAction {

	/**
	 * Run an action with shared error handling.
	 *
	 * @param callable $action The action.
	 * @param string $label A short label for log context.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function run(callable $action, string $label): JSONResponse {
		return $this->posResponses()->run(
			respond: fn (): JSONResponse => new JSONResponse($action()),
			logLabel: substr(strrchr(self::class, '\\'), 1).'::'.$label
		);
	}//end run()
}//end trait
