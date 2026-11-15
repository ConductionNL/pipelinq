<?php

/**
 * Pipelinq OcsErrorResponse.
 *
 * The JSON response a service's OCS exception maps to.
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

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use Throwable;

/**
 * Maps OCS not-found, forbidden and bad-request exceptions to 404, 403 and 422
 * responses carrying the exception message; anything else maps to nothing.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait OcsErrorResponse {

	/**
	 * The response for an OCS exception, or null for any other failure.
	 *
	 * @param Throwable $failure The failure.
	 *
	 * @return JSONResponse|null The 404 / 403 / 422 response, or null.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function ocsErrorResponse(Throwable $failure): ?JSONResponse {
		if ($failure instanceof OCSNotFoundException) {
			return new JSONResponse(['error' => $failure->getMessage()], Http::STATUS_NOT_FOUND);
		}

		if ($failure instanceof OCSForbiddenException) {
			return new JSONResponse(['error' => $failure->getMessage()], Http::STATUS_FORBIDDEN);
		}

		if ($failure instanceof OCSBadRequestException) {
			return new JSONResponse(['error' => $failure->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return null;
	}//end ocsErrorResponse()
}//end trait
