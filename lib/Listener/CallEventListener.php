<?php

/**
 * Pipelinq CallEventListener.
 *
 * Mirrors a call that another app's telephony adapter reports.
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
 *
 * @spec openspec/changes/kcc-agent-panel/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\Pipelinq\Service\Calls\CallMirror;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hands integriq's call event to the call mirror.
 *
 * The telephony adapter lives in integriq, which dispatches one typed event
 * per call event (`CtiEventIntake::dispatchFor()`). Both apps run in the same
 * Nextcloud, so the event arrives in process: no webhook, no shared secret and
 * no subscription to provision. The event class is named as a plain string and
 * read by its public properties, so pipelinq loads without integriq and
 * nothing fires when it is absent.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-a-call-event-is-mirrored-and-resolved-once
 */
class CallEventListener implements IEventListener {
	/**
	 * Integriq's call event. A plain string, never imported.
	 *
	 * @var string
	 */
	public const EVENT = 'OCA\Integriq\Event\CallEvent';

	/**
	 * Constructor.
	 *
	 * @param CallMirror $mirror The call mirror.
	 * @param LoggerInterface $logger Logger. A caller's number MUST NOT be written here.
	 *
	 * @spec exclude constructor wiring only; it makes no decision a requirement can describe
	 */
	public function __construct(
		private readonly CallMirror $mirror,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Mirror one call event. Never throws: a failed mirror must not turn a
	 * call that really happened into an error back to the telephony platform.
	 *
	 * @param Event $event Integriq's call event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-a-call-event-is-mirrored-and-resolved-once
	 */
	public function handle(Event $event): void {
		if (is_a($event, self::EVENT) === false) {
			return;
		}

		try {
			$fields = get_object_vars($event);
			$caller = ($fields['caller'] ?? null);
			$this->mirror->mirror(
				call: [
					'callId' => ($fields['callId'] ?? ''),
					'kind' => ($fields['kind'] ?? ''),
					'sourceId' => ($fields['sourceId'] ?? ''),
					'callerNumber' => ($fields['callerNumber'] ?? ''),
					'callerName' => $this->nameOf(caller: $caller),
					'openCases' => ($fields['openCases'] ?? []),
					'agentId' => ($fields['agentId'] ?? ''),
					'at' => ($fields['at'] ?? ''),
					'durationSeconds' => ($fields['durationSeconds'] ?? 0),
				]
			);
		} catch (Throwable $e) {
			$this->logger->error('CallEventListener: a call event is not mirrored', ['exception' => $e->getMessage()]);
		}
	}//end handle()

	/**
	 * The name to show for the party integriq resolved, or '' for an unknown caller.
	 *
	 * @param mixed $caller The resolved party, or null.
	 *
	 * @return string The name.
	 */
	private function nameOf(mixed $caller): string {
		if (is_array($caller) === false) {
			return '';
		}

		foreach (['naam', 'name', 'volledigeNaam', 'displayName'] as $key) {
			if (is_string($caller[$key] ?? null) === true && trim($caller[$key]) !== '') {
				return trim($caller[$key]);
			}
		}

		return '';
	}//end nameOf()
}//end class
