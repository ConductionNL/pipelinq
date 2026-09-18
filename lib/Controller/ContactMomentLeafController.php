<?php

/**
 * Pipelinq ContactMomentLeafController.
 *
 * The HTTP face of the `pipelinq-contact-moments` leaf. A host app renders the
 * panel and calls these two routes with its own object's uuid; it never reads
 * pipelinq's register itself and never learns which schema holds a contact
 * moment.
 *
 * The controller is a thin mapper: every decision, including the refusal for a
 * caller who may not read the host, is the provider's, and the status it
 * returns is passed through unchanged.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/contact-moments-on-pipelinq-schema/specs/contactmomenten/spec.md#requirement-contact-moments-are-a-data-provider-leaf-with-append-req-cmd-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Integration\ContactMomentLeafProvider;
use OCA\Pipelinq\Service\ContactMomentFilingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Routes for the contact moments leaf.
 *
 * @spec openspec/changes/contact-moments-on-pipelinq-schema/specs/contactmomenten/spec.md#requirement-contact-moments-are-a-data-provider-leaf-with-append-req-cmd-003
 */
class ContactMomentLeafController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ContactMomentLeafProvider $provider The leaf's data provider.
	 * @param ContactMomentFilingService $filingService The two filing acts.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContactMomentLeafProvider $provider,
		private readonly ContactMomentFilingService $filingService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List the contact moments filed on a host object.
	 *
	 * Per-object authorisation lives in the provider, which refuses a caller
	 * who cannot read the host through OpenRegister's own RBAC. The route is
	 * `#[NoAdminRequired]` because every handler uses it, not only admins.
	 *
	 * @param string $hostId The host object's uuid.
	 * @param int $limit Page size.
	 *
	 * @return JSONResponse The rows, or the provider's refusal.
	 *
	 * @spec openspec/changes/contact-moments-on-pipelinq-schema/specs/contactmomenten/spec.md#requirement-contact-moments-are-a-data-provider-leaf-with-append-req-cmd-003
	 */
	#[NoAdminRequired]
	public function index(string $hostId, int $limit = ContactMomentLeafProvider::DEFAULT_LIMIT): JSONResponse {
		$result = $this->provider->list(hostId: $hostId, limit: $limit);

		return $this->respond(result: $result);
	}//end index()

	/**
	 * Append one contact moment to a host object.
	 *
	 * @param string $hostId The host object's uuid.
	 * @param string $title The subject of the contact moment.
	 * @param string $channel The channel it ran over.
	 * @param string $direction inbound, outbound or internal.
	 * @param string|null $outcome Optional disposition.
	 * @param string|null $summary Optional free text.
	 * @param string|null $occurredAt Optional instant; defaults to now.
	 *
	 * @return JSONResponse The created row, or the provider's refusal.
	 *
	 * @spec openspec/changes/contact-moments-on-pipelinq-schema/specs/contactmomenten/spec.md#requirement-contact-moments-are-a-data-provider-leaf-with-append-req-cmd-003
	 */
	#[NoAdminRequired]
	public function create(
		string $hostId,
		string $title = '',
		string $channel = '',
		string $direction = '',
		?string $outcome = null,
		?string $summary = null,
		?string $occurredAt = null,
	): JSONResponse {
		$result = $this->provider->create(
			hostId: $hostId,
			payload: [
				'title' => $title,
				'channel' => $channel,
				'direction' => $direction,
				'outcome' => ($outcome ?? ''),
				'summary' => ($summary ?? ''),
				'occurredAt' => ($occurredAt ?? ''),
			],
		);

		return $this->respond(result: $result);
	}//end create()

	/**
	 * File an existing contact moment onto one further case.
	 *
	 * An act, not a copy: it appends a reference and records who did it. The
	 * number of contact moments is unchanged by design, which is the whole
	 * point of the capability.
	 *
	 * @param string $momentId The contact moment's uuid.
	 * @param string $caseId The case to file it onto.
	 *
	 * @return JSONResponse The updated contact moment, or the refusal.
	 *
	 * @spec openspec/changes/one-contact-moment-on-several-cases/specs/contactmomenten/spec.md#requirement-filing-onto-a-further-case-is-an-act-not-a-copy-req-cms-004
	 */
	#[NoAdminRequired]
	public function fileOnAlsoCase(string $momentId, string $caseId = ''): JSONResponse {
		return $this->respond(
			result: $this->filingService->fileOnAlsoCase(momentId: $momentId, caseId: $caseId)
		);
	}//end fileOnAlsoCase()

	/**
	 * Take a contact moment off one case.
	 *
	 * @param string $momentId The contact moment's uuid.
	 * @param string $caseId The case to take it off.
	 * @param string|null $newPrimary The next primary, required when the
	 *   primary itself is being removed.
	 *
	 * @return JSONResponse The updated contact moment, or the refusal.
	 *
	 * @spec openspec/changes/one-contact-moment-on-several-cases/specs/contactmomenten/spec.md#requirement-a-contact-moment-cannot-be-left-with-no-case-req-cms-006
	 */
	#[NoAdminRequired]
	public function unfileFromCase(string $momentId, string $caseId, ?string $newPrimary = null): JSONResponse {
		return $this->respond(
			result: $this->filingService->unfileFromCase(
				momentId: $momentId,
				caseId: $caseId,
				newPrimary: $newPrimary,
			)
		);
	}//end unfileFromCase()

	/**
	 * Turn a provider result into a JSON response.
	 *
	 * @param array<string, mixed> $result The provider's answer.
	 *
	 * @return JSONResponse The response.
	 */
	private function respond(array $result): JSONResponse {
		$status = (int)($result['status'] ?? 200);
		unset($result['status']);

		return new JSONResponse($result, $status);
	}//end respond()
}//end class
