<?php

/**
 * Pipelinq RequestFields.
 *
 * Reads the fields a client may send from a request body.
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

use OCP\IRequest;

/**
 * Collects named request params: a field the request leaves out is left out
 * of the result too, so a partial PATCH keeps the stored value.
 *
 * @spec exclude shared plumbing of the controllers that use it, no spec surface of its own.
 */
final class RequestFields {
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
	 * The given params the request carries, cast to string.
	 *
	 * @param array<int, string> $keys The param names.
	 *
	 * @return array<string, string> The present fields.
	 *
	 * @spec exclude shared plumbing of the controllers that use it, no spec surface of its own.
	 */
	public function strings(array $keys): array {
		$fields = [];
		foreach ($keys as $key) {
			$value = $this->request->getParam($key);
			if ($value !== null) {
				$fields[$key] = (string)$value;
			}
		}

		return $fields;
	}//end strings()

	/**
	 * The given params the request carries as arrays.
	 *
	 * @param array<int, string> $keys The param names.
	 *
	 * @return array<string, array<mixed>> The present fields.
	 *
	 * @spec exclude shared plumbing of the controllers that use it, no spec surface of its own.
	 */
	public function arrays(array $keys): array {
		$fields = [];
		foreach ($keys as $key) {
			$value = $this->request->getParam($key);
			if (is_array($value) === true) {
				$fields[$key] = $value;
			}
		}

		return $fields;
	}//end arrays()
}//end class
