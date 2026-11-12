<?php

/**
 * Pipelinq CallAgent.
 *
 * The agent a CTI call is assigned to.
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
 * The Nextcloud user and phone extension that handle a call.
 *
 * @spec openspec/specs/cti-screenpop-adapter/spec.md
 */
final class CallAgent {
	/**
	 * Constructor.
	 *
	 * @param string $userId    Acting agent UID.
	 * @param string $extension Agent extension.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $userId,
		public readonly string $extension,
	) {
	}//end __construct()
}//end class
