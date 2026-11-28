<?php

/**
 * Pipelinq ComplaintAnswerTermCreatingListener.
 *
 * Gives a new complaint its answer deadline before it is saved: the term of
 * its category when one is configured and shorter, else the legal answer
 * term of six weeks, counted from when it was received. A complaint that
 * already names a deadline keeps it.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/specify-capability-rows/specs/klachtenregistratie/spec.md#requirement-a-complaint-is-held-to-the-legal-answer-term-by-default
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\ComplaintSlaService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Override;
use Throwable;

/**
 * Stamps the answer deadline on a new complaint.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/specify-capability-rows/specs/klachtenregistratie/spec.md#requirement-a-complaint-is-held-to-the-legal-answer-term-by-default
 */
class ComplaintAnswerTermCreatingListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig          $appConfig    The app config (ticket schema).
	 * @param ComplaintSlaService $complaintSla Works out the answer deadline.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ComplaintSlaService $complaintSla,
	) {
	}//end __construct()

	/**
	 * Stamp `slaDeadline` on a new complaint that has none.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/specify-capability-rows/specs/klachtenregistratie/spec.md#requirement-a-complaint-is-held-to-the-legal-answer-term-by-default
	 */
	#[Override]
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false) {
			return;
		}

		$entity       = $event->getObject();
		$ticketSchema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'ticket_schema', '');
		if ($ticketSchema === '' || (string) $entity->getSchema() !== $ticketSchema) {
			return;
		}

		$ticket = array_merge($entity->getObject(), $event->getModifiedData());
		if (($ticket['ticketType'] ?? '') !== 'complaint' || trim((string) ($ticket['slaDeadline'] ?? '')) !== '') {
			return;
		}

		$deadline = $this->complaintSla->answerDeadline(
			category: (string) ($ticket['complaintCategory'] ?? ''),
			received: $this->receivedAt(ticket: $ticket)
		);

		// Merge, never replace: another listener may already have modified the data.
		$event->setModifiedData(array_merge($event->getModifiedData(), ['slaDeadline' => $deadline->format(DateTimeInterface::ATOM)]));
	}//end handle()

	/**
	 * When the complaint was received: its `occurredAt`, else now.
	 *
	 * @param array<string, mixed> $ticket The complaint.
	 *
	 * @return DateTimeImmutable The receipt instant.
	 */
	private function receivedAt(array $ticket): DateTimeImmutable {
		$occurred = trim((string) ($ticket['occurredAt'] ?? ''));
		if ($occurred !== '') {
			try {
				return new DateTimeImmutable($occurred);
			} catch (Throwable) {
				// An unreadable date counts from now, as a complaint without one does.
			}
		}

		return new DateTimeImmutable();
	}//end receivedAt()
}//end class
