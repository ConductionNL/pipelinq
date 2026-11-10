<?php

/**
 * Pipelinq TimedResponse.
 *
 * An HTTP response and how long the request took.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\Http\Client\IResponse;

/**
 * The response of a direct BRP lookup and its duration in milliseconds.
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003-01
 */
final class TimedResponse {
	/**
	 * Constructor.
	 *
	 * @param IResponse $response   The HTTP response.
	 * @param int       $durationMs The request duration in milliseconds.
	 */
	public function __construct(
		public readonly IResponse $response,
		public readonly int $durationMs,
	) {
	}//end __construct()

	/**
	 * The HTTP status of the response.
	 *
	 * @return int The status code.
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003-01
	 */
	public function status(): int {
		return (int)$this->response->getStatusCode();
	}//end status()
}//end class
