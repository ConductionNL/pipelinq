<?php

/**
 * Pipelinq OriginateResult.
 *
 * Service-level wrapper around adapter CtiCallResult, adding telemetry context.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti\Result
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti\Result;

/**
 * Service-level outcome of an outbound click-to-dial originate call.
 */
final class OriginateResult {
	/**
	 * Constructor.
	 *
	 * @param bool $success Whether the originate request was accepted.
	 * @param string|null $externalCallId Platform's call UUID when allocated.
	 * @param string|null $interactionId Pre-created contactmoment UUID.
	 * @param string|null $error Error message when $success is false.
	 * @param string|null $platform Active CTI platform identifier.
	 */
	public function __construct(
		public readonly bool $success,
		public readonly ?string $externalCallId = null,
		public readonly ?string $interactionId = null,
		public readonly ?string $error = null,
		public readonly ?string $platform = null,
	) {
	}//end __construct()

	/**
	 * Convert to an array for a JSONResponse.
	 *
	 * @return array<string,mixed>
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.1
	 */
	public function toArray(): array {
		return [
			'success' => $this->success,
			'externalCallId' => $this->externalCallId,
			'interactionId' => $this->interactionId,
			'error' => $this->error,
			'platform' => $this->platform,
		];
	}//end toArray()
}//end class
