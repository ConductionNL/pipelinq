<?php

/**
 * Pipelinq EventCancelledListener.
 *
 * When a campaign event's registration status turns to cancelled, everyone
 * who holds a place or waits for one gets a notice. The mails go out in the
 * deferred job, not in the request that cancelled the event.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/marketing-events/tasks.md#3.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\Pipelinq\BackgroundJob\DeferredObjectListenerJob;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\EventEmailServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\EventRegistrationServiceInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Notifies the registrations of a campaign event that was cancelled.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/marketing-events/tasks.md#3.1
 */
class EventCancelledListener implements IEventListener, DeferredObjectWork {

	/**
	 * The deferred-work handler key.
	 */
	public const HANDLER_KEY = 'event-cancelled';

	/**
	 * The registration statuses that are told: a place, or waiting for one.
	 */
	private const NOTIFIED = ['confirmed', 'waitlist'];

	/**
	 * Constructor.
	 *
	 * @param EventRegistrationServiceInterface $registrations Reads the event and its registrations.
	 * @param EventEmailServiceInterface        $mails         Sends the notice.
	 * @param IAppConfig                        $appConfig     The event schema id.
	 * @param ListenerDeferralService           $deferral      Defers the mails to a background job.
	 * @param LoggerInterface                   $logger        Logs a notice that could not be sent.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly EventRegistrationServiceInterface $registrations,
		private readonly EventEmailServiceInterface $mails,
		private readonly IAppConfig $appConfig,
		private readonly ListenerDeferralService $deferral,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Defer the notices when an event turns cancelled.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-events/tasks.md#3.1
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		$new = $event->getNewObject();
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'campaignEvent_schema', '');
		if ($schema === '' || (string)$new->getSchema() !== $schema) {
			return;
		}

		$was = (string)($event->getOldObject()?->getObject()['signupStatus'] ?? '');
		$uuid = (string)$new->getUuid();
		if ($uuid === '' || $was === 'cancelled' || self::isCancelled(event: $new->getObject()) === false) {
			return;
		}

		$this->deferral->defer(
			jobClass: DeferredObjectListenerJob::class,
			entry: ['handler' => self::HANDLER_KEY, 'uuid' => $uuid],
			dedupeKey: self::HANDLER_KEY.'|'.$uuid
		);
	}//end handle()

	/**
	 * Mail everyone with a place or on the waiting list, if the event is still cancelled.
	 *
	 * @param array<string, mixed> $entry The deferred entry (`uuid`).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-events/tasks.md#3.1
	 */
	public function runDeferredWork(array $entry): void {
		$uuid = (string)($entry['uuid'] ?? '');
		$current = $this->registrations->event(eventId: $uuid);
		// Re-decided against the event as it is now: one reopened since is not announced as cancelled.
		if ($uuid === '' || $current === null || self::isCancelled(event: $current) === false) {
			return;
		}

		foreach (self::NOTIFIED as $status) {
			foreach ($this->registrations->registrationsWithStatus(eventId: $uuid, status: $status) as $registration) {
				$this->notify(event: $current, registration: $registration);
			}
		}
	}//end runDeferredWork()

	/**
	 * Send one notice; a failure is logged and the others still go out.
	 *
	 * @param array<string, mixed> $event        The cancelled event.
	 * @param array<string, mixed> $registration The registration.
	 *
	 * @return void
	 */
	private function notify(array $event, array $registration): void {
		try {
			$this->mails->sendEventCancelled(event: $event, registration: $registration);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: an event cancellation notice could not be sent',
				['registration' => (string)($registration['uuid'] ?? $registration['id'] ?? ''), 'reason' => $e->getMessage()]
			);
		}
	}//end notify()

	/**
	 * Whether an event's registration status is cancelled.
	 *
	 * @param array<string, mixed>|null $event The event data.
	 *
	 * @return bool
	 */
	private static function isCancelled(?array $event): bool {
		return (string)($event['signupStatus'] ?? '') === 'cancelled';
	}//end isCancelled()
}//end class
