<?php

/**
 * Pipelinq LandingPageFormSubmittedListener.
 *
 * Turns a visitor's landing-page form submission into a contact, a lead
 * and a touchpoint, and tells Portaliq what became of it.
 *
 * 🔴 IDEMPOTENT ON THE NONCE, AND THE CHECK COMES FIRST. Portaliq's relay
 * reacts to OpenRegister's `ObjectCreatedEvent`, so a repair, a replayed
 * write or a retried request can dispatch the same submission twice. The
 * guard reads the touchpoint log for the submission's nonce before it
 * touches anything. It is on the touchpoint and not on the lead because
 * a touchpoint is written on every submission and a lead is not: a
 * second submission by a known contact appends a touchpoint and creates
 * no lead, so a lead-shaped guard would let it through.
 *
 * The work runs inline rather than through `DeferredObjectWork`, because
 * the contract's acknowledgement is synchronous: `setLeadId()` and
 * `setHandled()` are read off this instance the moment dispatch returns.
 * It is fail-safe, matching Portaliq's own posture on this relay: the
 * visitor's submission is already durable, and nothing here may turn it
 * into an error.
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
 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\Pipelinq\Event\LandingPageFormSubmittedEvent;
use OCA\Pipelinq\Kernel\Contracts\EventRegistrationServiceInterface;
use OCA\Pipelinq\Service\CampaignService;
use OCA\Pipelinq\Service\Marketing\EventEmailService;
use OCA\Pipelinq\Service\Marketing\EventRegistrationOutcome;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCA\Pipelinq\Service\TouchpointService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Landing-page submission to contact, lead and touchpoint.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
 */
class LandingPageFormSubmittedListener implements IEventListener {

	/**
	 * The contact schema slug.
	 *
	 * @var string
	 */
	public const CONTACT_SCHEMA_SLUG = 'contact';

	/**
	 * The client schema slug.
	 *
	 * @var string
	 */
	public const CLIENT_SCHEMA_SLUG = 'client';

	/**
	 * The lead schema slug.
	 *
	 * @var string
	 */
	public const LEAD_SCHEMA_SLUG = 'lead';

	/**
	 * What a record created from a landing page records as its origin.
	 *
	 * @var string
	 */
	public const SOURCE = 'landing-page';

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store Register-scoped object access.
	 * @param CampaignService $campaigns Resolves the campaign the form belongs to.
	 * @param TouchpointService $touchpoints The touchpoint log and its nonce guard.
	 * @param LoggerInterface $logger Logger.
	 * @param EventRegistrationServiceInterface|null $eventRegistrations Registers the sign-up for the campaign's event.
	 * @param EventEmailService|null $eventMails Confirms the registration by mail.
	 *
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function __construct(
		private ListObjectStore $store,
		private CampaignService $campaigns,
		private TouchpointService $touchpoints,
		private LoggerInterface $logger,
		private ?EventRegistrationServiceInterface $eventRegistrations = null,
		private ?EventEmailService $eventMails = null,
	) {
	}//end __construct()

	/**
	 * Handle one submission.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function handle(Event $event): void {
		if (($event instanceof LandingPageFormSubmittedEvent) === false) {
			return;
		}

		try {
			$this->ingest(event: $event);
		} catch (Throwable $e) {
			// The visitor's submission is already durable in Portaliq. A
			// failure here costs a lead, never the submission.
			$this->logger->warning(
				'Pipelinq: landing page submission could not be ingested',
				['form' => $event->getFormId(), 'reason' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * Record the submission: guard on the nonce, then contact, lead and
	 * touchpoint, then acknowledge.
	 *
	 * @param LandingPageFormSubmittedEvent $event The submission.
	 *
	 * @return void
	 */
	private function ingest(LandingPageFormSubmittedEvent $event): void {
		if ($this->alreadyRecorded(event: $event) === true) {
			return;
		}

		$campaignId = $this->campaignIdFor(event: $event);
		$submission = new LandingSubmission(
			event: $event,
			campaign: new SubmissionCampaign(id: $campaignId, row: ($this->campaigns->find(id: $campaignId) ?? []))
		);
		$records = $this->recordsFor(submission: $submission);

		$this->touchpoints->append(
			touchpoint: $this->touchpointFor(submission: $submission, records: $records)
		);

		if ($records->leadId !== '') {
			$event->setLeadId($records->leadId);
		}

		$event->setHandled(true);

		$this->registerForEvent(submission: $submission, records: $records);
	}//end ingest()

	/**
	 * Register the sign-up for the campaign's event and mail the person what happened.
	 *
	 * Runs after the submission is acknowledged, and a failure here is logged
	 * on its own: the contact, lead and touchpoint stand whatever happens to
	 * the registration. The event's contract with portaliq is unchanged.
	 *
	 * @param LandingSubmission $submission The submission and its campaign.
	 * @param SubmissionRecords $records    The contact and lead written for it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-a-landing-page-sign-up-registers-for-the-event-req-mev-002
	 */
	private function registerForEvent(LandingSubmission $submission, SubmissionRecords $records): void {
		$values = $submission->event->getValues();
		$email = strtolower(trim((string)($values['email'] ?? '')));
		if ($this->eventRegistrations === null || $email === '') {
			return;
		}

		try {
			$campaignEvent = $this->eventRegistrations->eventForCampaign(campaignId: $submission->campaign->id);
			if ($campaignEvent === null) {
				return;
			}

			$person = [
				'name' => trim((string)($values['name'] ?? '')),
				'email' => $email,
				'contact' => $records->contactId,
				'lead' => $records->leadId,
				'source' => 'web-form',
			];
			$outcome = $this->eventRegistrations->register(event: $campaignEvent, person: $person);
			$this->mailOutcome(campaignEvent: $campaignEvent, person: $person, outcome: $outcome);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: landing page sign-up could not be registered for the event',
				['form' => $submission->event->getFormId(), 'reason' => $e->getMessage()]
			);
		}
	}//end registerForEvent()

	/**
	 * Mail the person the outcome of their registration.
	 *
	 * @param array<string, mixed>     $campaignEvent The event.
	 * @param array<string, mixed>     $person        The person who signed up.
	 * @param EventRegistrationOutcome $outcome       What the registration did.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	private function mailOutcome(array $campaignEvent, array $person, EventRegistrationOutcome $outcome): void {
		if ($this->eventMails === null) {
			return;
		}

		if ($outcome->status === EventRegistrationOutcome::CLOSED) {
			$this->eventMails->sendClosed(event: $campaignEvent, person: $person);
			return;
		}

		$isNew = in_array($outcome->status, [EventRegistrationOutcome::REGISTERED, EventRegistrationOutcome::WAITLISTED], true);
		if ($isNew === false || $outcome->registration === null) {
			return;
		}

		if ($this->eventMails->sendConfirmation(event: $campaignEvent, registration: $outcome->registration) === true) {
			$this->eventRegistrations?->stamp(registration: $outcome->registration, field: 'confirmationSentAt');
		}
	}//end mailOutcome()

	/**
	 * Acknowledge a redelivered submission whose nonce already has a touchpoint.
	 *
	 * @param LandingPageFormSubmittedEvent $event The submission.
	 *
	 * @return bool True when the submission was recorded before and is now acknowledged.
	 */
	private function alreadyRecorded(LandingPageFormSubmittedEvent $event): bool {
		$nonce = trim($event->getNonce());
		if ($nonce !== '' && $this->touchpoints->existsForNonce(nonce: $nonce) === true) {
			$this->logger->info(
				'Pipelinq: landing page submission already recorded, ignoring the redelivery',
				['form' => $event->getFormId()]
			);
			$event->setHandled(true);
			return true;
		}

		return false;
	}//end alreadyRecorded()

	/**
	 * Match or create the contact and write the lead, both only when an address was given.
	 *
	 * @param LandingSubmission $submission The submission and its campaign.
	 *
	 * @return SubmissionRecords The contact and lead ids, empty when not written.
	 */
	private function recordsFor(LandingSubmission $submission): SubmissionRecords {
		$values = $submission->event->getValues();
		$email = strtolower(trim((string)($values['email'] ?? '')));

		$contactId = '';
		$leadId = '';
		if ($email !== '') {
			$contactId = $this->matchOrCreateContact(email: $email, values: $values);
			$leadId = $this->createLead(submission: $submission, contactId: $contactId);
		}

		return new SubmissionRecords(contactId: $contactId, leadId: $leadId);
	}//end recordsFor()

	/**
	 * The campaign this submission belongs to.
	 *
	 * The external reference Pipelinq set on the request carries it
	 * (`pipelinq:campaign:<id>`). When it is missing or unreadable the
	 * form id is matched against the campaigns' stored `formRef`, so a
	 * submission is still attributed after a reference is lost.
	 *
	 * @param LandingPageFormSubmittedEvent $event The submission.
	 *
	 * @return string The campaign id, or an empty string.
	 */
	private function campaignIdFor(LandingPageFormSubmittedEvent $event): string {
		$reference = trim($event->getExternalReference());
		$prefix = 'pipelinq:campaign:';
		if (str_starts_with($reference, $prefix) === true) {
			$id = trim(substr($reference, strlen($prefix)));
			if ($id !== '') {
				return $id;
			}
		}

		$formId = trim($event->getFormId());
		if ($formId === '') {
			return '';
		}

		$matches = $this->campaigns->all(filters: ['formRef' => $formId]);
		if ($matches === []) {
			return '';
		}

		return $this->campaigns->idOf(campaign: $matches[0]);
	}//end campaignIdFor()

	/**
	 * Find the contact with this email address, or create one.
	 *
	 * The address is matched lowercased and then as submitted, because
	 * OpenRegister's filter DSL has no case-insensitive equality and a
	 * contact stored with capitals would otherwise be duplicated. A
	 * contact stored in a third casing is still missed; that residual is
	 * the price of a bounded query, and a duplicate contact is a smaller
	 * harm than an unbounded scan of every contact on the instance.
	 *
	 * @param string $email The submitted address, already lowercased.
	 * @param array<string, mixed> $values The submitted values.
	 *
	 * @return string The contact id, or an empty string when the write failed.
	 */
	private function matchOrCreateContact(string $email, array $values): string {
		foreach ([$email, trim((string)($values['email'] ?? ''))] as $candidate) {
			if ($candidate === '') {
				continue;
			}

			$rows = $this->store->findAll(schemaSlug: self::CONTACT_SCHEMA_SLUG, filters: ['email' => $candidate]);
			if ($rows !== []) {
				return $this->store->idOf(payload: $rows[0]);
			}
		}

		$name = trim((string)($values['name'] ?? ''));
		if ($name === '') {
			$name = $email;
		}

		$saved = $this->store->save(
			schemaSlug: self::CONTACT_SCHEMA_SLUG,
			payload: [
				'name' => $name,
				'email' => $email,
				'source' => self::SOURCE,
				'marketingConsent' => false,
			],
		);

		return $this->store->idOf(payload: $saved);
	}//end matchOrCreateContact()

	/**
	 * Write the lead this submission produced.
	 *
	 * A client is created only when the visitor named an organisation.
	 * Inventing one from a personal address would put a record in the
	 * account list that nobody asked for and that the shillinq lookup
	 * would then try to match against an invoice.
	 *
	 * @param LandingSubmission $submission The submission and its campaign.
	 * @param string            $contactId  The contact id.
	 *
	 * @return string The lead id, or an empty string when the write failed.
	 */
	private function createLead(LandingSubmission $submission, string $contactId): string {
		$event = $submission->event;
		$values = $event->getValues();
		$payload = [
			'title' => ($this->leadTitle(submission: $submission) . ': ' . trim((string)($values['name'] ?? $event->getPortal()))),
			'source' => self::SOURCE,
			'status' => 'open',
			'campaignId' => $submission->campaign->id,
			'firstTouch' => $this->utmBlock(utm: $event->getUtmFirstTouch()),
			'lastTouch' => $this->utmBlock(utm: $event->getUtmLastTouch()),
			'description' => $this->describe(event: $event, values: $values),
		];

		if ($contactId !== '') {
			$payload['contact'] = $contactId;
		}

		$clientId = $this->matchOrCreateClient(values: $values);
		if ($clientId !== '') {
			$payload['client'] = $clientId;
		}

		return $this->store->idOf(payload: $this->store->save(schemaSlug: self::LEAD_SCHEMA_SLUG, payload: $payload));
	}//end createLead()

	/**
	 * The lead's title prefix: the campaign name, else the page route, else 'Landing page'.
	 *
	 * @param LandingSubmission $submission The submission and its campaign.
	 *
	 * @return string
	 */
	private function leadTitle(LandingSubmission $submission): string {
		$title = trim((string)($submission->campaign->row['name'] ?? ''));
		if ($title === '') {
			$title = trim($submission->event->getPageRoute());
		}

		if ($title === '') {
			$title = 'Landing page';
		}

		return $title;
	}//end leadTitle()

	/**
	 * Find the client with this organisation name, or create one.
	 *
	 * @param array<string, mixed> $values The submitted values.
	 *
	 * @return string The client id, or an empty string when none was named.
	 */
	private function matchOrCreateClient(array $values): string {
		$organisation = trim((string)($values['organisation'] ?? ''));
		if ($organisation === '') {
			return '';
		}

		$rows = $this->store->findAll(schemaSlug: self::CLIENT_SCHEMA_SLUG, filters: ['name' => $organisation]);
		if ($rows !== []) {
			return $this->store->idOf(payload: $rows[0]);
		}

		$saved = $this->store->save(
			schemaSlug: self::CLIENT_SCHEMA_SLUG,
			payload: ['name' => $organisation, 'type' => 'organization', 'notes' => 'Created from a landing page submission.'],
		);

		return $this->store->idOf(payload: $saved);
	}//end matchOrCreateClient()

	/**
	 * The touchpoint this submission appends.
	 *
	 * @param LandingSubmission $submission The submission and its campaign.
	 * @param SubmissionRecords $records    The contact and lead it produced.
	 *
	 * @return array<string, mixed> The touchpoint row.
	 */
	private function touchpointFor(LandingSubmission $submission, SubmissionRecords $records): array {
		$event = $submission->event;
		$utm = $this->utmBlock(utm: $event->getUtmLastTouch());

		return [
			'campaignId' => $submission->campaign->id,
			'contactId' => $records->contactId,
			'leadId' => $records->leadId,
			'kind' => 'submit',
			'channel' => $this->touchpoints->channelForMedium(medium: (string)($utm['medium'] ?? '')),
			'utm' => $utm,
			'sourceRef' => $event->getPageId(),
			'occurredAt' => $event->getSubmittedAt(),
			'nonce' => trim($event->getNonce()),
		];
	}//end touchpointFor()

	/**
	 * Normalise a UTM block to the five keys the schemas declare.
	 *
	 * @param array<string, mixed> $utm Whatever Portaliq sent.
	 *
	 * @return array<string, string> The block, absent keys empty.
	 */
	private function utmBlock(array $utm): array {
		$block = [];
		foreach (['campaign', 'source', 'medium', 'term', 'content'] as $key) {
			$block[$key] = trim((string)($utm[$key] ?? ''));
		}

		return $block;
	}//end utmBlock()

	/**
	 * A readable description of where the lead came from.
	 *
	 * @param LandingPageFormSubmittedEvent $event The submission.
	 * @param array<string, mixed> $values The submitted values.
	 *
	 * @return string The description.
	 */
	private function describe(LandingPageFormSubmittedEvent $event, array $values): string {
		$lines = ['Submitted on ' . $event->getPageRoute() . ' (' . $event->getPortal() . ').'];
		foreach ($values as $key => $value) {
			if (is_scalar($value) === true) {
				$lines[] = ($key . ': ' . (string)$value);
			}
		}

		$referrer = trim($event->getReferrer());
		if ($referrer !== '') {
			$lines[] = ('Referrer: ' . $referrer);
		}

		return implode("\n", $lines);
	}//end describe()
}//end class
