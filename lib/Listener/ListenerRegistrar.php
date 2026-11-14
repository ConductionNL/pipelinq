<?php

/**
 * Pipelinq Listener Registrar
 *
 * @category  Listener
 * @package   OCA\Pipelinq\Listener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\Pipelinq\Event\LandingPageFormSubmittedEvent;
use OCA\Pipelinq\Event\TimeEntryApprovedEvent;
use OCA\Pipelinq\Kernel\Contracts\BrpMutationWebhookListenerInterface;
use OCA\Pipelinq\Kernel\Contracts\NrcNotificationListenerInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The Listener component's own registration: which listener handles which
 * event. Registration order is the order listeners of one event run in.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://pipelinq.nl
 *
 * @spec exclude Composition root wiring, no functional requirement.
 */
class ListenerRegistrar {
	/**
	 * Register the event listeners, in the order their handlers run.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Composition root wiring, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(BrpMutationWebhookListenerInterface::class, BrpMutationWebhookListener::class);
		$context->registerServiceAlias(NrcNotificationListenerInterface::class, NrcNotificationListener::class);

		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: ObjectEventListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: ObjectEventListener::class
		);
		// A saved answer on a resident's portal question tells them, with
		// pipelinq's own message (portal-questions-in-dutch).
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: QuestionAnsweredListener::class
		);
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: DealCreatedListener::class
		);
		// Every new lead sits in a pipeline stage (pipeline-numbers-tell-the-truth).
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: LeadStageCreatingListener::class
		);
		$this->registerNumberListeners(context: $context);
		// A lead a form created gets its contact and client before it is stored
		// and its submit touchpoint once it is (decision 179: the submit creates
		// the lead itself, so nothing relays it afterwards).
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: SubmittedLeadListener::class
		);
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: SubmittedLeadListener::class
		);
		// A published website form is checked against its destination on every
		// save, and refused on any finding (website-forms-submit-into-tickets,
		// decisions 179 and 181 Q9).
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: WebFormBindingListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: WebFormBindingListener::class
		);
		// A pipeline stage with a limit refuses one item more, from any screen
		// (pipeline-stage-limit).
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: StageLimitGuard::class
		);
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: StageLimitGuard::class
		);
		// A Nextcloud Forms answer creates the object its binding names (Q6).
		// Forms is optional; a `::class` string does not autoload, and the guard
		// keeps an instance without Forms from registering anything.
		if (class_exists(FormsSubmissionListener::EVENT) === true) {
			$context->registerEventListener(
				event: FormsSubmissionListener::EVENT,
				listener: FormsSubmissionListener::class
			);
		}
		// A new task records who created it (round3-review-points).
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: TaskCreatedByCreatingListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: DealUpdatedListener::class
		);
		// A cancelled campaign event tells everyone with a place or on the
		// waiting list, in the deferred job (marketing-events, task 3.1).
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: EventCancelledListener::class
		);
		// A changed resource or service expires the cached availability it
		// made wrong, for every writer (appointment-booking, REQ-APT-015).
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: AvailabilityCacheInvalidationListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: AvailabilityCacheInvalidationListener::class
		);
		$context->registerEventListener(
			event: ObjectDeletedEvent::class,
			listener: AvailabilityCacheInvalidationListener::class
		);
		// Burgerportaal / MijnOverheid Berichtenbox bridge:
		// listen for zaak status transitions and queue an outbound
		// Berichtenbox message via BerichtenboxService
		// (burgerportaal-mijnoverheid-bridge / REQ-OUTBOUND-001).
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: BerichtenboxZaakStatusListener::class
		);

		// Shillinq WIP integration: time-entry approval dispatches a CloudEvent
		// to Shillinq (pipelinq-time-to-shillinq-wip / REQ-WIP-001). The listener
		// is idempotent and a no-op when the Shillinq app is not installed.
		$context->registerEventListener(
			event: TimeEntryApprovedEvent::class,
			listener: TimeApprovalListener::class
		);

		// Loyalty program: POS transaction completion fires the loyalty engine
		// (loyalty-program / REQ-LOY-002). The listener filters to posTransaction
		// entities + completed/settled/paid statuses, catches all errors, and never
		// throws so the POS flow is unaffected (REQ-LOY-002-05).
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: PosTransactionCompletedListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: PosTransactionCompletedListener::class
		);

		// Expense → Shillinq AP voucher dispatch on status=approved transitions
		// (pipelinq-expense-to-shillinq-ap / REQ-AP-002). Listener is filtered
		// to the expense schema and idempotent on apSyncStatus=synced so a
		// re-fired update event cannot create a duplicate AP voucher.
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: ExpenseApprovalListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: ExpenseApprovalListener::class
		);

		// SLA engine (sla-engine-and-escalation / REQ-001, REQ-003, REQ-007):
		// initialise slaStatus on tracked-object create, re-evaluate /
		// pause / resume / escalate on update. Listener exceptions are
		// swallowed (REQ-007 fail-safe).
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: SlaObjectCreatedListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: SlaObjectUpdatedListener::class
		);

		// Satisfaction surveys (customer-satisfaction-closed-loop): a ticket
		// that reaches a status an enabled survey rule names gets its
		// invitations written, in the deferred job (pipelinq#2072). Created as
		// well as updated, because a ticket can be logged already closed.
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: SurveyDispatchListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: SurveyDispatchListener::class
		);

		// Contact erasure keeps the opt-out in integriq (opt-out-before-send REQ-CII-005).
		// An API delete is a soft delete, which OpenRegister reports as an update
		// (erase-on-soft-delete REQ-CII-008); the purge is the deleted event.
		$context->registerEventListener(event: ObjectDeletedEvent::class, listener: ContactErasedListener::class);
		$context->registerEventListener(event: ObjectUpdatedEvent::class, listener: ContactErasedListener::class);

		// Marketing: portaliq relays a landing-page form submission by
		// dispatching PIPELINQ'S OWN event class, which it resolves by the
		// sourceApp on the form and class_exists()-guards (ADR-041,
		// landing-page-provisioning). The event is ours, so this is an
		// ordinary same-app registration: nothing here needs portaliq to be
		// installed, and with portaliq absent the listener simply never fires.
		$context->registerEventListener(
			event: LandingPageFormSubmittedEvent::class,
			listener: LandingPageFormSubmittedListener::class
		);
		$this->registerMergeListeners(context: $context);
		$context->registerEventListener(
			event: SchemaUpdatedEvent::class,
			listener: SchemaChangeListener::class
		);
	}//end register()

	/**
	 * Register the listeners that stamp a number on a new record: a request or
	 * complaint gets its reference ("PQ-2026-0402"), a quote its number
	 * ("OFF-2026-0042").
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerNumberListeners(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: TicketReferenceCreatingListener::class
		);
		// A ticket carries the number it is about in E.164, whoever wrote it
		// (contact-moments-phone-lookup D2).
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: TicketPhoneE164Listener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: TicketPhoneE164Listener::class
		);
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: QuoteNumberCreatingListener::class
		);
		// A quote's totals and a lead's value follow their lines (line-totals-roll-up).
		foreach ([ObjectCreatedEvent::class, ObjectUpdatedEvent::class, ObjectDeletedEvent::class] as $lineEvent) {
			$context->registerEventListener(event: $lineEvent, listener: LineTotalsListener::class);
		}

		// A till line gets its VAT and total from the server before it is stored (till-server-priced-lines).
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $lineEvent) {
			$context->registerEventListener(event: $lineEvent, listener: PosLinePricingListener::class);
		}
	}//end registerNumberListeners()

	/**
	 * Register the listeners of an OpenRegister merge: the downstream sync after a
	 * merge or reversal (REQ-MDM-004, ADR-041 event-not-RPC propagation), the
	 * stamp that says where a merged-away client or contact went, and the guard
	 * that keeps it read-only.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerMergeListeners(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: \OCA\OpenRegister\Event\ObjectsMergedEvent::class,
			listener: ObjectsMergedSyncListener::class
		);
		// Before the stamp listener: a reversal reads the moved rows before the stamp is cleared.
		$context->registerEventListener(
			event: \OCA\OpenRegister\Event\ObjectsMergedEvent::class,
			listener: ClientMergeReferenceListener::class
		);
		$context->registerEventListener(
			event: \OCA\OpenRegister\Event\ObjectsMergedEvent::class,
			listener: MergedAwayRecordListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: MergedAwayRecordGuard::class
		);
	}//end registerMergeListeners()
}//end class
