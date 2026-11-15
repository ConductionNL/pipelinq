<?php

/**
 * Pipelinq IncomingCallScope.
 *
 * The agent and moment an incoming-call lookup judges calls against.
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
 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti;

/**
 * The agent whose line is checked and the Unix time a call's age is measured against.
 *
 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
 */
final class IncomingCallScope {
	/**
	 * Constructor.
	 *
	 * @param string $userId The agent's Nextcloud uid.
	 * @param int    $now    Unix time.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $userId,
		public readonly int $now,
	) {
	}//end __construct()
}//end class
