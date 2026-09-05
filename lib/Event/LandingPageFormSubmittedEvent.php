<?php

/**
 * Pipelinq LandingPageFormSubmittedEvent.
 *
 * Portaliq dispatches this after a visitor's landing-page form submission
 * is durably written. Per ADR-041 the receiving class lives in the
 * CONSUMING app's namespace, so this file is Pipelinq's own copy of the
 * shape portaliq's `LandingPageSubmissionDispatchListener` constructs;
 * portaliq resolves it by `sourceApp` and `class_exists()`-guards it.
 *
 * 🔴 THE CONSTRUCTOR IS A FROZEN CROSS-APP CONTRACT AND IS CALLED
 * POSITIONALLY. Portaliq builds it with thirteen positional arguments,
 * `sourceApp` first. Reordering or inserting a parameter silently shifts
 * every later value: the referrer would arrive where the timestamp
 * belongs and nothing would error. Additive changes go on the end, with
 * a default, coordinated with portaliq.
 *
 * ⚠️ `correlationId` is not, today, the correlation id the request
 * carried. Portaliq passes `externalReference` into that slot, because
 * `landingPageSubmission` declares no `correlationId` property to carry
 * one through. Nothing here depends on the two differing.
 *
 * @category Event
 * @package  OCA\Pipelinq\Event
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Event;

use OCP\EventDispatcher\Event;

/**
 * Cross-app notification: a landing-page form Pipelinq asked for was submitted.
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
 */
class LandingPageFormSubmittedEvent extends Event {

	/**
	 * The lead this submission became (optional result slot).
	 *
	 * @var string|null
	 */
	private ?string $leadId = null;

	/**
	 * Whether Pipelinq handled this notification (optional result slot).
	 *
	 * @var boolean
	 */
	private bool $handled = false;

	/**
	 * Construct the notification event.
	 *
	 * @param string $sourceApp The app that requested the originating form, echoed back.
	 * @param string $formId The submitted form's OpenRegister id.
	 * @param string $pageId The bound page's OpenRegister id.
	 * @param string $pageRoute The bound page's route.
	 * @param string $portal The portal slug the page belongs to.
	 * @param string $externalReference The reference Pipelinq set on the request.
	 * @param array<string, mixed> $values The visible-field values the visitor submitted.
	 * @param array<string, mixed> $utmFirstTouch `{campaign, source, medium, term, content}` at first landing.
	 * @param array<string, mixed> $utmLastTouch The same shape, at the submitting visit.
	 * @param string $referrer `document.referrer` captured at first touch.
	 * @param string $submittedAt Server-stamped ISO-8601 timestamp.
	 * @param string $nonce Server-generated, replay resistance only.
	 * @param string $correlationId Correlation id for tracing both directions of this contract.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) A PUBLISHED CROSS-APP
	 * CONTRACT constructed positionally by portaliq, not an internal
	 * signature. See the class docblock.
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $formId,
		private readonly string $pageId,
		private readonly string $pageRoute,
		private readonly string $portal,
		private readonly string $externalReference,
		private readonly array $values,
		private readonly array $utmFirstTouch,
		private readonly array $utmLastTouch,
		private readonly string $referrer,
		private readonly string $submittedAt,
		private readonly string $nonce,
		private readonly string $correlationId = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The app that requested the originating form.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The submitted form's id.
	 *
	 * @return string The form id.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function getFormId(): string {
		return $this->formId;
	}//end getFormId()

	/**
	 * The bound page's id.
	 *
	 * @return string The page id.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function getPageId(): string {
		return $this->pageId;
	}//end getPageId()

	/**
	 * The bound page's route.
	 *
	 * @return string The route.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function getPageRoute(): string {
		return $this->pageRoute;
	}//end getPageRoute()

	/**
	 * The portal the page belongs to.
	 *
	 * @return string The portal slug.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function getPortal(): string {
		return $this->portal;
	}//end getPortal()

	/**
	 * The reference Pipelinq set on the request, `pipelinq:campaign:<id>`.
	 *
	 * @return string The external reference.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function getExternalReference(): string {
		return $this->externalReference;
	}//end getExternalReference()

	/**
	 * The values the visitor submitted.
	 *
	 * @return array<string, mixed> Field id to value.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function getValues(): array {
		return $this->values;
	}//end getValues()

	/**
	 * The campaign parameters of the visitor's first landing.
	 *
	 * @return array<string, mixed> The UTM block.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function getUtmFirstTouch(): array {
		return $this->utmFirstTouch;
	}//end getUtmFirstTouch()

	/**
	 * The campaign parameters of the visit the form was submitted on.
	 *
	 * @return array<string, mixed> The UTM block.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function getUtmLastTouch(): array {
		return $this->utmLastTouch;
	}//end getUtmLastTouch()

	/**
	 * Where the visitor came from at first touch.
	 *
	 * @return string The referrer.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function getReferrer(): string {
		return $this->referrer;
	}//end getReferrer()

	/**
	 * When the submission was written, server-stamped.
	 *
	 * @return string ISO-8601.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function getSubmittedAt(): string {
		return $this->submittedAt;
	}//end getSubmittedAt()

	/**
	 * The nonce this submission carries. The listener's idempotency key.
	 *
	 * @return string The nonce.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function getNonce(): string {
		return $this->nonce;
	}//end getNonce()

	/**
	 * The correlation id, as portaliq passed it.
	 *
	 * @return string The correlation id.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}//end getCorrelationId()

	/**
	 * The lead this submission became (result slot).
	 *
	 * @return string|null The lead id, or null when none was created.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function getLeadId(): ?string {
		return $this->leadId;
	}//end getLeadId()

	/**
	 * Record the lead this submission became.
	 *
	 * @param string $leadId The lead's OpenRegister id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function setLeadId(string $leadId): void {
		$this->leadId = $leadId;
	}//end setLeadId()

	/**
	 * Whether Pipelinq handled this notification.
	 *
	 * @return bool True once the listener has run.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()

	/**
	 * Mark whether Pipelinq handled this notification.
	 *
	 * @param bool $handled True once the listener has run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function setHandled(bool $handled): void {
		$this->handled = $handled;
	}//end setHandled()
}//end class
