<?php

/**
 * Pipelinq ErrorAwareJsonAnswer.
 *
 * A service result as JSON, 400 when it carries an error.
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

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * A result with an 'error' key answers 400 with only that error; any other
 * result answers 200 with the result.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ErrorAwareJsonAnswer {

	/**
	 * Turn a service answer into a response.
	 *
	 * @param array<string, mixed> $result The service's answer.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function answer(array $result): JSONResponse {
		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($result);
	}//end answer()
}//end trait
