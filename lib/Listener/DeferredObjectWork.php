<?php

/**
 * Pipelinq DeferredObjectWork.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

/**
 * Implemented by a post-event listener whose work runs in a background job.
 *
 * ADR-078: a listener on `ObjectCreatedEvent` / `ObjectUpdatedEvent` /
 * `ObjectDeletedEvent` cannot influence the write it observes, so any real work
 * it does is latency the user pays for a result they have already earned. Such
 * a listener's `handle()` keeps only the cheap in-memory filtering and hands an
 * entry to {@see \OCA\Pipelinq\BackgroundJob\DeferredObjectListenerJob} through
 * OpenRegister's `ListenerDeferralService`; the job calls `runDeferredWork()`
 * back on the listener under the captured actor.
 *
 * IMPLEMENTATIONS MUST BE IDEMPOTENT AND MUST RECONCILE AGAINST CURRENT STATE.
 * Delivery is at-least-once and ordering against the write is not guaranteed
 * (ADR-078 Rule 7), so an entry whose object is gone, or whose triggering
 * condition no longer holds, is a no-op — not an error.
 */
interface DeferredObjectWork {

	/**
	 * Perform the work that used to run inside the object write.
	 *
	 * @param array<string, mixed> $entry The entry captured at dispatch time.
	 *
	 * @return void
	 */
	public function runDeferredWork(array $entry): void;
}//end interface
