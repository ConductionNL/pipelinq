<?php

/**
 * Pipelinq CallRecording.
 *
 * A recording attached to a contactmoment ticket.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/cti-screenpop-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti;


/**
 * Where a call recording lives and until when it is retained.
 *
 * @spec openspec/specs/cti-screenpop-adapter/spec.md
 */
final class CallRecording {
	/**
	 * Constructor.
	 *
	 * @param string $recordingUrl URL of the recording.
	 * @param string $expiresAt    ISO 8601 retention expiry.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $recordingUrl,
		public readonly string $expiresAt,
	) {
	}//end __construct()
}//end class
