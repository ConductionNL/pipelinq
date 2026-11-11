<?php

/**
 * Pipelinq RequestBody.
 *
 * The raw body of a webhook request, for the signature checks that hash it.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
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

namespace OCA\Pipelinq\Controller;

use OCP\IRequest;

/**
 * Reads a request's raw body: `php://input`, or the JSON-encoded parameters
 * when the input stream is empty (the unit-test boundary).
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
final class RequestBody {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 */
	public function __construct(
		private readonly IRequest $request,
	) {
	}//end __construct()

	/**
	 * The raw body, or '' when there is none.
	 *
	 * @return string The body.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function raw(): string {
		$body = file_get_contents('php://input');
		if (is_string($body) === true && $body !== '') {
			return $body;
		}

		// Fallback for the unit-test boundary (IRequest::getParams).
		$params = $this->request->getParams();
		if ($params !== []) {
			$encoded = json_encode($params);
			if (is_string($encoded) === true) {
				return $encoded;
			}
		}

		return '';
	}//end raw()
}//end class
