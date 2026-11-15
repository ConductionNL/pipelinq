<?php

/**
 * Pipelinq OriginateEndpoint.
 *
 * Where a CTI platform places an outbound call and where its reply names the call.
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
 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti\Adapter;

/**
 * The originate URL of a CTI platform and the reply fields that carry the new call's id.
 *
 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-1.1
 */
final class OriginateEndpoint {
	/**
	 * Constructor.
	 *
	 * @param string       $url         The absolute originate URL.
	 * @param list<string> $callIdKeys Reply fields holding the call id, first present wins.
	 */
	public function __construct(
		public readonly string $url,
		public readonly array $callIdKeys,
	) {
	}//end __construct()

	/**
	 * A request to this endpoint with the given HTTP client options.
	 *
	 * @param array<string,mixed> $options The HTTP client options (auth, headers, body, timeout).
	 *
	 * @return OriginateRequest The request.
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-1.1
	 */
	public function request(array $options): OriginateRequest {
		return new OriginateRequest(endpoint: $this, options: $options);
	}//end request()
}//end class
