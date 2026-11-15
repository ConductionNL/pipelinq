<?php

/**
 * Pipelinq DefersObjectWork.
 *
 * Hands one object's follow-up work to the deferred listener job.
 *
 * @category Util
 * @package  OCA\Pipelinq\Listener\Support
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

namespace OCA\Pipelinq\Listener\Support;

use OCA\Pipelinq\BackgroundJob\DeferredObjectListenerJob;

/**
 * Defers the host's work on one object, deduplicated per object. The host
 * class provides a ListenerDeferralService `$deferral` property and a
 * `HANDLER_KEY` constant.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait DefersObjectWork {

	/**
	 * Defer the host's work on the object with this UUID.
	 *
	 * @param string $uuid The object UUID.
	 *
	 * @return void
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function deferObjectWork(string $uuid): void {
		$this->deferral->defer(
			jobClass: DeferredObjectListenerJob::class,
			entry: ['handler' => self::HANDLER_KEY, 'uuid' => $uuid],
			dedupeKey: self::HANDLER_KEY . '|' . $uuid
		);
	}//end deferObjectWork()
}//end trait
