<?php

/**
 * Pipelinq QuoteController.
 *
 * Sends a quote from a lead's lines (products-quote-from-lead). The browser
 * talks only to pipelinq: pipelinq reads the lead as the caller, filinq
 * renders the PDF and files a copy in the caller's Files, pipelinq mails it
 * and logs an outbound contact moment on the lead.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use DateTimeImmutable;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\CallerObjectReaderInterface;
use OCA\Pipelinq\Kernel\Contracts\FilinqLetterAdapterInterface;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Quote\QuoteRefusedException;
use OCA\Pipelinq\Service\Quote\QuoteSender;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * Quotes sent from a lead.
 *
 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
 */
class QuoteController extends Controller {

	/**
	 * Without a valid-until date a quote holds this many days.
	 *
	 * @var int
	 */
	private const DEFAULT_VALID_DAYS = 30;

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request      The request.
	 * @param QuoteSender                  $sender       Renders, mails and logs the quote.
	 * @param FilinqLetterAdapterInterface $letters      Whether filinq is there, and the template.
	 * @param IUserSession                 $userSession  The acting user.
	 * @param CallerObjectReaderInterface  $reader       Reads the lead as the caller.
	 * @param ObjectOwnerAccessPolicy      $accessPolicy Who may act on a lead.
	 */
	public function __construct(
		IRequest $request,
		private readonly QuoteSender $sender,
		private readonly FilinqLetterAdapterInterface $letters,
		private readonly IUserSession $userSession,
		private readonly CallerObjectReaderInterface $reader,
		private readonly ObjectOwnerAccessPolicy $accessPolicy,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * POST /api/leads/{id}/quotes: send a quote from this lead's lines.
	 *
	 * Body: `templateId`, and optionally `validUntil` (Y-m-d, today or later;
	 * thirty days from today when absent). Refuses, in this order: no user
	 * (401), no template id or a bad date (400), no filinq (503), a lead the
	 * caller may not read (404), an unknown template (404), no recipient
	 * (422), a render or mail that failed (502).
	 *
	 * @param string $id The lead id.
	 *
	 * @return JSONResponse The sent quote, or the refusal.
	 *
	 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
	 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-sent-quote-does-not-change-afterwards-req-qslice-004
	 */
	#[NoAdminRequired]
	public function send(string $id): JSONResponse {
		$uid = $this->userId();
		if ($uid === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$templateId = trim((string) $this->request->getParam('templateId', ''));
		if ($templateId === '') {
			return new JSONResponse(['message' => 'Missing required parameter: templateId'], Http::STATUS_BAD_REQUEST);
		}

		$validUntil = self::validUntil(value: (string) $this->request->getParam('validUntil', ''), today: new DateTimeImmutable('today'));
		if ($validUntil === null) {
			return new JSONResponse(['message' => 'validUntil must be a date (Y-m-d), today or later'], Http::STATUS_BAD_REQUEST);
		}

		$refusal = $this->unavailableRefusal();
		if ($refusal !== null) {
			return $refusal;
		}

		$lead = $this->readLead(id: $id, uid: $uid);
		if ($lead === null) {
			return new JSONResponse(['message' => 'Lead not found'], Http::STATUS_NOT_FOUND);
		}

		$template = $this->template(templateId: $templateId);
		if ($template instanceof JSONResponse) {
			return $template;
		}

		try {
			return new JSONResponse($this->sender->send(leadId: $id, lead: $lead, templateId: $templateId, validUntil: $validUntil));
		} catch (QuoteRefusedException $e) {
			return new JSONResponse(['message' => $e->getMessage()] + $e->getDetails(), $e->getStatus());
		}
	}//end send()

	/**
	 * GET /api/leads/{id}/quotes/recipient: who a quote for this lead would go to.
	 *
	 * The dialog shows it before sending. `recipient` is null when neither the
	 * contact nor the client has an email address. A lead the caller may not
	 * read answers 404.
	 *
	 * @param string $id The lead id.
	 *
	 * @return JSONResponse `{recipient: {email, name, source}|null}`, or the refusal.
	 *
	 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
	 */
	#[NoAdminRequired]
	public function recipient(string $id): JSONResponse {
		$uid = $this->userId();
		if ($uid === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$lead = $this->readLead(id: $id, uid: $uid);
		if ($lead === null) {
			return new JSONResponse(['message' => 'Lead not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['recipient' => $this->sender->recipientFor(lead: $lead)]);
	}//end recipient()

	/**
	 * The last day a quote holds: the date asked for, or thirty days from today.
	 *
	 * @param string            $value The requested date, or ''.
	 * @param DateTimeImmutable $today Today, at midnight.
	 *
	 * @return string|null The date as Y-m-d, or null when it is not a date or lies in the past.
	 *
	 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
	 */
	public static function validUntil(string $value, DateTimeImmutable $today): ?string {
		$value = trim($value);
		if ($value === '') {
			return $today->modify('+'.self::DEFAULT_VALID_DAYS.' days')->format('Y-m-d');
		}

		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		if ($date === false || $date->format('Y-m-d') !== $value || $date < $today) {
			return null;
		}

		return $value;
	}//end validUntil()

	/**
	 * The acting user's id, or null when nobody is signed in.
	 *
	 * @return string|null The user id.
	 */
	private function userId(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end userId()

	/**
	 * The 503 refusal when filinq is not available.
	 *
	 * @return JSONResponse|null The refusal, or null when filinq is available.
	 */
	private function unavailableRefusal(): ?JSONResponse {
		$reason = $this->letters->unavailableReason();
		if ($reason === null) {
			return null;
		}

		return new JSONResponse(
			['message' => 'filinq is not available, so no quote can be sent.', 'reason' => $reason],
			Http::STATUS_SERVICE_UNAVAILABLE
		);
	}//end unavailableRefusal()

	/**
	 * The lead as the caller reads it, or null when it is absent or not theirs.
	 *
	 * @param string $id  The lead id.
	 * @param string $uid The caller.
	 *
	 * @return array<string, mixed>|null The lead.
	 */
	private function readLead(string $id, string $uid): ?array {
		$lead = $this->reader->read(id: $id, schemaKey: 'lead_schema');
		if ($lead === null) {
			return null;
		}

		$lead['id'] = ($lead['id'] ?? $id);
		// Existence is not authorization: OpenRegister answers a read whatever
		// `_rbac` says, so this app decides, as the lead pages do.
		if ($this->accessPolicy->mayAccess(uid: $uid, object: $lead, ownerField: 'assignee') === false) {
			return null;
		}

		return $lead;
	}//end readLead()

	/**
	 * The template when it is one of pipelinq's, else the 404 (or 502 when filinq refuses the lookup).
	 *
	 * @param string $templateId The template id.
	 *
	 * @return array<string, mixed>|JSONResponse The template, or the refusal.
	 */
	private function template(string $templateId): array|JSONResponse {
		try {
			$template = $this->letters->findTemplate(templateId: $templateId);
		} catch (RuntimeException $e) {
			return new JSONResponse(['message' => 'filinq could not look up the template.', 'reason' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}

		if ($template === null) {
			return new JSONResponse(['message' => 'Template not found'], Http::STATUS_NOT_FOUND);
		}

		return $template;
	}//end template()
}//end class
