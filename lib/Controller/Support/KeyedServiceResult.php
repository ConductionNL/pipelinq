<?php

/**
 * Pipelinq KeyedServiceResult.
 *
 * Maps a marketing service's write result to a response.
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
 * A result carrying `error: 'Not found'` is a 404, any other `error` a 400,
 * and otherwise the record under its key is answered.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait KeyedServiceResult {

	/**
	 * Map a service result to a response with the right status.
	 *
	 * @param array<string, mixed> $result The service result.
	 * @param string               $key    The key the record is under, in the result and the response.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function keyedResult(array $result, string $key): JSONResponse {
		if (($result['error'] ?? '') === 'Not found') {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse([$key => ($result[$key] ?? null)]);
	}//end keyedResult()
}//end trait
