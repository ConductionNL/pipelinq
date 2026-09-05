<?php

/**
 * Pipelinq JourneyStepRunner.
 *
 * The one place a journey's action actually happens. The flow engine decides
 * WHEN, this decides WHETHER and WHAT.
 *
 * 🔴 THE GATE IS THE SAME GATE. A journey send calls
 * `ComplianceService::permitsSend()`, which is the method an ordinary blast's
 * audience is filtered by. A journey therefore cannot reach anyone a blast
 * could not, and a rule added to the consent gate applies to journeys the
 * day it lands, without anybody remembering to copy it here.
 *
 * 🔴 A REFUSAL IS WRITTEN DOWN, WITH THE CONTACT IN IT. Skipping quietly
 * would make a journey with no consent look exactly like a journey with a
 * small audience, and the difference is only visible months later in a
 * campaign report that never adds up.
 *
 * The send itself goes through `MailTransportService::sendOneDelivery()` on a
 * `blastDelivery` row, the same ledger an ordinary blast writes, so tracking,
 * unsubscribes and the campaign report keep working on a journey send without
 * knowing it was one.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\Service\ComplianceService;
use OCA\Pipelinq\Service\SegmentService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * JourneyStepRunner: the gate, then the action, then the record.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One step joins the journey,
 *  its audience, the consent gate, the transport and the run ledger; splitting
 *  it would put the gate somewhere the action could be reached without it.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
 */
class JourneyStepRunner {

	/**
	 * The channel a journey mailing uses.
	 *
	 * @var string
	 */
	public const CHANNEL = 'email';

	/**
	 * Never act on more than this many contacts in one step.
	 *
	 * @var int
	 */
	private const MAX_CONTACTS = 500;

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store Register-scoped object plumbing.
	 * @param JourneyService $journeys Journeys and their run ledger.
	 * @param ComplianceService $compliance The consent and suppression gate.
	 * @param SegmentService $segments Segment membership.
	 * @param MailTransportService $transports Transport resolution and the per-recipient send.
	 * @param ITimeFactory $time Clock.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
	 */
	public function __construct(
		private ListObjectStore $store,
		private JourneyService $journeys,
		private ComplianceService $compliance,
		private SegmentService $segments,
		private MailTransportService $transports,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run one journey's action for whatever the flow delivered.
	 *
	 * @param string $journeyId The journey.
	 * @param array<string, mixed> $subject The triggering object, empty for a scheduled journey.
	 * @param string $flowRunUuid The flow run, recorded on each outcome.
	 *
	 * @return array<int, array{contactId: string, state: string, reason: string}> One entry per contact.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
	 */
	public function run(string $journeyId, array $subject = [], string $flowRunUuid = ''): array {
		$journey = $this->journeys->find(journeyId: $journeyId);
		if ($journey === null) {
			return [];
		}

		$action = (array)($journey['action'] ?? []);
		$outcomes = [];
		foreach ($this->contactsFor(journey: $journey, subject: $subject) as $contactId) {
			$outcome = $this->act(
				journeyId: $journeyId,
				action: $action,
				contactId: $contactId,
				segmentId: trim((string)($journey['audienceSegment'] ?? ''))
			);
			$outcome['contactId'] = $contactId;
			$outcomes[] = $outcome;

			$this->journeys->recordRun(run: [
				'journeyId' => $journeyId,
				'contactId' => $contactId,
				'clientId' => $this->clientOf(contactId: $contactId),
				'state' => $outcome['state'],
				'reason' => $outcome['reason'],
				'actionKind' => (string)($action['kind'] ?? ''),
				'flowRunUuid' => $flowRunUuid,
			]);
		}

		return $outcomes;
	}//end run()

	/**
	 * Perform one action for one contact, gate first.
	 *
	 * @param string $journeyId The journey.
	 * @param array<string, mixed> $action The journey's action block.
	 * @param string $contactId The contact.
	 * @param string $segmentId The journey's audience segment, for the delivery ledger.
	 *
	 * @return array{state: string, reason: string} What happened.
	 */
	private function act(string $journeyId, array $action, string $contactId, string $segmentId = ''): array {
		if ((string)($action['kind'] ?? 'createTask') === 'createTask') {
			// A task is internal work, not a message to the contact, so no
			// consent question arises and none is asked.
			return $this->createTask(action: $action, contactId: $contactId);
		}

		$gate = $this->compliance->permitsSend(
			contactId: $contactId,
			channel: self::CHANNEL,
			intent: (string)($action['intent'] ?? ComplianceService::INTENT_PROMOTIONAL),
			listId: (string)($action['listId'] ?? '')
		);

		if ($gate['allowed'] === false) {
			$this->logger->info(
				'JourneyStepRunner: refused a journey send',
				['journeyId' => $journeyId, 'contactId' => $contactId, 'reason' => $gate['reason']]
			);
			return ['state' => 'refused', 'reason' => $gate['reason']];
		}

		return $this->sendMailing(journeyId: $journeyId, action: $action, contactId: $contactId, segmentId: $segmentId);
	}//end act()

	/**
	 * Send one journey mailing to one contact.
	 *
	 * @param string $journeyId The journey.
	 * @param array<string, mixed> $action The action block.
	 * @param string $contactId The contact.
	 * @param string $segmentId The journey's audience segment, for the delivery ledger.
	 *
	 * @return array{state: string, reason: string} What happened.
	 */
	private function sendMailing(string $journeyId, array $action, string $contactId, string $segmentId = ''): array {
		$template = $this->store->find(
			schemaSlug: $this->store->schemaSlug('campaignTemplate_schema', 'campaignTemplate'),
			id: (string)($action['templateId'] ?? '')
		);
		if ($template === null) {
			return ['state' => 'failed', 'reason' => 'template_missing'];
		}

		$transport = $this->transports->resolveTransport(blast: []);
		if ($transport === null) {
			return ['state' => 'failed', 'reason' => 'no_transport'];
		}

		$contact = $this->store->find(schemaSlug: $this->store->schemaSlug('contact_schema', 'contact'), id: $contactId);
		$email = trim((string)(($contact ?? [])['email'] ?? ''));
		if ($email === '') {
			return ['state' => 'failed', 'reason' => 'no_email'];
		}

		$blastId = $this->ledgerBlast(
			journeyId: $journeyId,
			templateId: (string)($action['templateId'] ?? ''),
			segmentId: $segmentId
		);
		if ($blastId === '') {
			return ['state' => 'failed', 'reason' => 'no_ledger'];
		}

		$delivery = $this->store->save(
			schemaSlug: $this->store->schemaSlug('blastDelivery_schema', 'blastDelivery'),
			payload: ['blastId' => $blastId, 'contactId' => $contactId, 'email' => $email, 'status' => 'queued']
		);
		if ($delivery === null) {
			return ['state' => 'failed', 'reason' => 'no_delivery'];
		}

		try {
			$accepted = $this->transports->sendOneDelivery(delivery: $delivery, template: $template, transport: $transport);
		} catch (Throwable $e) {
			$this->logger->warning(
				'JourneyStepRunner.sendMailing: the transport threw',
				['journeyId' => $journeyId, 'contactId' => $contactId, 'exception' => $e->getMessage()]
			);
			return ['state' => 'failed', 'reason' => 'transport_error'];
		}

		if ($accepted === true) {
			return ['state' => 'sent', 'reason' => ''];
		}

		return ['state' => 'failed', 'reason' => 'transport_rejected'];
	}//end sendMailing()

	/**
	 * Put a task on somebody's list.
	 *
	 * @param array<string, mixed> $action The action block.
	 * @param string $contactId The contact the task is about.
	 *
	 * @return array{state: string, reason: string} What happened.
	 */
	private function createTask(array $action, string $contactId): array {
		$subject = trim((string)($action['taskSubject'] ?? ''));
		if ($subject === '') {
			return ['state' => 'failed', 'reason' => 'task_subject_missing'];
		}

		$payload = [
			'subject' => $subject,
			'type' => (string)($action['taskType'] ?? 'callback'),
			'status' => 'open',
			'clientId' => $this->clientOf(contactId: $contactId),
		];

		$assignee = trim((string)($action['taskAssignee'] ?? ''));
		if ($assignee !== '') {
			$payload['assigneeUserId'] = $assignee;
		}

		$stored = $this->store->save(schemaSlug: $this->store->schemaSlug('task_schema', 'task'), payload: $payload);
		if ($stored === null) {
			return ['state' => 'failed', 'reason' => 'task_write_failed'];
		}

		return ['state' => 'task-created', 'reason' => ''];
	}//end createTask()

	/**
	 * The contacts this step acts on.
	 *
	 * @param array<string, mixed> $journey The journey.
	 * @param array<string, mixed> $subject The triggering object, empty for a schedule.
	 *
	 * @return array<int, string> Contact ids, unique and capped.
	 */
	private function contactsFor(array $journey, array $subject): array {
		if ($subject === []) {
			// A scheduled journey has no triggering object, so its audience
			// segment IS the audience.
			return $this->capped(contacts: $this->audienceContacts(journey: $journey));
		}

		$contacts = $this->subjectContacts(subject: $subject);
		if (trim((string)($journey['audienceSegment'] ?? '')) !== '') {
			// The segment is a filter here, not the audience: a lead that
			// moved stage still has to be someone the journey is for.
			$contacts = array_intersect($contacts, $this->audienceContacts(journey: $journey));
		}

		return $this->capped(contacts: $contacts);
	}//end contactsFor()

	/**
	 * Unique, non-empty contact ids, never more than the cap.
	 *
	 * @param array<int, string> $contacts The candidates.
	 *
	 * @return array<int, string> The capped list.
	 */
	private function capped(array $contacts): array {
		return array_slice(array_values(array_unique(array_filter($contacts))), 0, self::MAX_CONTACTS);
	}//end capped()

	/**
	 * The contacts of the journey's audience segment.
	 *
	 * @param array<string, mixed> $journey The journey.
	 *
	 * @return array<int, string> Contact ids.
	 */
	private function audienceContacts(array $journey): array {
		$segment = trim((string)($journey['audienceSegment'] ?? ''));
		if ($segment === '') {
			return [];
		}

		$contacts = [];
		foreach ($this->segments->getMembersForBlast(segmentId: $segment) as $member) {
			$contactId = trim((string)($member['contactId'] ?? ''));
			if ($contactId !== '') {
				$contacts[] = $contactId;
			}
		}

		return $contacts;
	}//end audienceContacts()

	/**
	 * The contacts the triggering object points at.
	 *
	 * @param array<string, mixed> $subject The triggering object.
	 *
	 * @return array<int, string> Contact ids.
	 */
	private function subjectContacts(array $subject): array {
		foreach (['contact', 'contactId'] as $key) {
			$value = trim((string)($subject[$key] ?? ''));
			if ($value !== '') {
				return [$value];
			}
		}

		// A contract names a customer, not a person. Everyone recorded at
		// that customer is a candidate, and the consent gate then decides
		// which of them may actually be written to.
		$clientRef = trim((string)($subject['clientRef'] ?? ''));
		if ($clientRef === '') {
			return [];
		}

		$contacts = [];
		foreach ($this->store->findAll(schemaSlug: $this->store->schemaSlug('contact_schema', 'contact'), filters: ['client' => $clientRef]) as $contact) {
			if ((string)($contact['client'] ?? '') === $clientRef) {
				$contacts[] = $this->store->idOf($contact);
			}
		}

		return $contacts;
	}//end subjectContacts()

	/**
	 * The customer one contact belongs to.
	 *
	 * @param string $contactId The contact.
	 *
	 * @return string The client id, empty when there is none.
	 */
	private function clientOf(string $contactId): string {
		$contact = $this->store->find(schemaSlug: $this->store->schemaSlug('contact_schema', 'contact'), id: $contactId);
		if ($contact === null) {
			return '';
		}

		return trim((string)($contact['client'] ?? ''));
	}//end clientOf()

	/**
	 * The blast a journey's deliveries are recorded against, created once.
	 *
	 * A `blastDelivery` needs a `blastId`, and reusing the blast ledger is
	 * what keeps the click redirect, the unsubscribe link and the campaign
	 * report working on a journey send without a second code path.
	 *
	 * @param string $journeyId The journey.
	 * @param string $templateId The template the journey sends.
	 * @param string $segmentId The journey's audience segment, which the blast schema requires.
	 *
	 * @return string The blast id, empty when it could not be written.
	 */
	private function ledgerBlast(string $journeyId, string $templateId, string $segmentId = ''): string {
		$schema = $this->store->schemaSlug('blast_schema', 'blast');
		foreach ($this->store->findAll(schemaSlug: $schema, filters: ['journeyId' => $journeyId]) as $blast) {
			if ((string)($blast['journeyId'] ?? '') === $journeyId) {
				return $this->store->idOf($blast);
			}
		}

		$stored = $this->store->save(schemaSlug: $schema, payload: [
			'name' => 'Journey ' . $journeyId,
			'status' => 'sending',
			'channel' => self::CHANNEL,
			'journeyId' => $journeyId,
			'templateId' => $templateId,
			// `segmentId` is on the blast schema's required list, so it has
			// to be PRESENT even when a journey has no segment. An absent key
			// is refused by OpenRegister and the write is dropped without an
			// error, which is how a whole send disappears quietly.
			'segmentId' => $segmentId,
			'listId' => '',
			'createdAt' => date('c', $this->time->getTime()),
		]);

		return $this->store->idOf($stored);
	}//end ledgerBlast()
}//end class
