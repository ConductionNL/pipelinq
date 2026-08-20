<?php

/**
 * Pipelinq DeferredWorkGuard.
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
 * Process-wide re-entrancy guard for deferred post-event listener work.
 *
 * THIS IS THE PART OF THE ADR-078 CONVERSION THAT IS EASY TO GET WRONG, so it
 * is one class rather than seven copies.
 *
 * Every listener converted here writes an object back through
 * `ObjectService::saveObject()`, and OpenRegister's `MagicMapper::update()`
 * dispatches `ObjectUpdatedEvent` for that write — which re-enters the same
 * listeners. Inline, that recursion terminated (or not) on each listener's own
 * idempotency check. Deferred, it does not terminate at all: the re-entrant
 * `handle()` would enqueue ANOTHER job, whose write re-enters again, and cron
 * would grind on the same object forever. `cron.php` runs one job per web call,
 * so a self-re-queuing job does not merely waste work — it starves every other
 * job behind it.
 *
 * So the deferred work marks itself in-flight for its `(listener, uuid)` pair,
 * and a listener that finds its own pair already running returns without
 * deferring. The write raised by the deferred work is then observed exactly
 * once and goes no further.
 *
 * Static state is correct here for the same reason it is correct in
 * `ExpenseApprovalListener::$inFlight`, which this generalises: Nextcloud
 * resolves a listener from the container per dispatch, so a re-entrant dispatch
 * is not guaranteed to reach the same instance; and the process context is torn
 * down per request / per cron job, so nothing leaks between them. `leave()` is
 * always called from a `finally`.
 */
final class DeferredWorkGuard {

	/**
	 * Keys currently on the stack, as `<handler>|<uuid>`.
	 *
	 * @var array<string, true>
	 */
	private static array $inFlight = [];

	/**
	 * Build the guard key for a handler + object pair.
	 *
	 * @param string $handler The handler key of the listener doing the work.
	 * @param string $uuid The uuid of the object being written.
	 *
	 * @return string The guard key.
	 */
	public static function key(string $handler, string $uuid): string {
		return $handler . '|' . $uuid;
	}//end key()

	/**
	 * Claim a key, or report that it is already claimed.
	 *
	 * @param string $key The guard key.
	 *
	 * @return bool True when the caller claimed it and MUST call leave();
	 *              false when the work is already running on this stack.
	 */
	public static function enter(string $key): bool {
		if (isset(self::$inFlight[$key]) === true) {
			return false;
		}

		self::$inFlight[$key] = true;
		return true;
	}//end enter()

	/**
	 * Release a key claimed by enter().
	 *
	 * @param string $key The guard key.
	 *
	 * @return void
	 */
	public static function leave(string $key): void {
		unset(self::$inFlight[$key]);
	}//end leave()

	/**
	 * Whether a key is currently being processed on this stack.
	 *
	 * @param string $key The guard key.
	 *
	 * @return bool True when the deferred work for this pair is running.
	 */
	public static function isRunning(string $key): bool {
		return isset(self::$inFlight[$key]);
	}//end isRunning()

	/**
	 * Drop every claim. Tests only — a leaked key would make later tests lie.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$inFlight = [];
	}//end reset()
}//end class
