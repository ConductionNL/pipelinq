<?php

/**
 * Pipelinq OriginateRequest.
 *
 * One outbound call request to a CTI platform.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti\Adapter
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti\Adapter;

/**
 * The endpoint and HTTP options of one originate request.
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
 */
final class OriginateRequest {
	/**
	 * Constructor.
	 *
	 * @param OriginateEndpoint   $endpoint Where the call is placed.
	 * @param array<string,mixed> $options  The HTTP client options (auth, headers, body, timeout).
	 */
	public function __construct(
		public readonly OriginateEndpoint $endpoint,
		public readonly array $options,
	) {
	}//end __construct()
}//end class
