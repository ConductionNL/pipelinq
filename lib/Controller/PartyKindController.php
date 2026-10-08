<?php

/**
 * Pipelinq PartyKindController.
 *
 * The HTTP face of the party kind registry: which kinds a record type accepts,
 * and the one write path that links a party to a record under one of them.
 *
 * Every route here treats the record type as an opaque string. pipelinq holds
 * no case type and learns nothing about one from these calls.
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
 * @spec openspec/changes/party-kinds-accepted-per-case-type/specs/party-kind-registry/spec.md#requirement-the-picker-shall-offer-only-the-declared-kinds-in-the-declared-order-req-pkr-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PartyKindRegistryService;
use OCA\Pipelinq\Service\PartyLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Routes for the party kind registry and the party link write path.
 *
 * @spec openspec/changes/party-kinds-accepted-per-case-type/specs/party-kind-registry/spec.md#requirement-a-consuming-app-shall-declare-which-kinds-a-record-type-accepts-req-pkr-002
 */
class PartyKindController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PartyKindRegistryService $registry The vocabulary and the rule.
	 * @param PartyLinkService $linkService The one write path for a link.
	 */
	public function __construct(
		IRequest $request,
		private readonly PartyKindRegistryService $registry,
		private readonly PartyLinkService $linkService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The kinds a record type accepts, in the declared order.
	 *
	 * @param string $recordType The `<app>:<schema>:<type>` literal, or '' for
	 *   the whole active vocabulary.
	 *
	 * @return JSONResponse The offered kinds, and whether an acceptance exists.
	 *
	 * @spec openspec/changes/party-kinds-accepted-per-case-type/specs/party-kind-registry/spec.md#requirement-the-picker-shall-offer-only-the-declared-kinds-in-the-declared-order-req-pkr-003
	 */
	#[NoAdminRequired]
	public function index(string $recordType = ''): JSONResponse {
		return new JSONResponse(
			[
				'recordType' => $recordType,
				// Named so a picker can tell "this type accepts these two"
				// from "this type has declared nothing, so here is everything".
				'declared' => ($this->registry->acceptanceFor(recordType: $recordType) !== null),
				'kinds' => $this->registry->kindsFor(recordType: $recordType),
			],
			200
		);
	}//end index()

	/**
	 * Link a party to a record under a kind.
	 *
	 * @param string $recordType The `<app>:<schema>:<type>` literal.
	 * @param string $recordId The record.
	 * @param string $party The party.
	 * @param string $kind The party kind code.
	 *
	 * @return JSONResponse The link, or the refusal naming what it refused.
	 *
	 * @spec openspec/changes/party-kinds-accepted-per-case-type/specs/party-kind-registry/spec.md#requirement-a-party-link-with-an-unaccepted-kind-shall-be-refused-on-the-write-req-pkr-004
	 */
	#[NoAdminRequired]
	public function link(
		string $recordType = '',
		string $recordId = '',
		string $party = '',
		string $kind = '',
	): JSONResponse {
		return $this->respond(
			result: $this->linkService->link(
				recordType: $recordType,
				recordId: $recordId,
				party: $party,
				kind: $kind,
			)
		);
	}//end link()

	/**
	 * Link several parties at once, judged row by row by the same rule.
	 *
	 * @param string $recordType The `<app>:<schema>:<type>` literal.
	 * @param string $recordId The record.
	 * @param array<int, array<string, string>> $rows Each with `party` and `kind`.
	 *
	 * @return JSONResponse The rows that landed and the rows refused.
	 *
	 * @spec openspec/changes/party-kinds-accepted-per-case-type/specs/party-kind-registry/spec.md#requirement-a-party-link-with-an-unaccepted-kind-shall-be-refused-on-the-write-req-pkr-004
	 */
	#[NoAdminRequired]
	public function import(string $recordType = '', string $recordId = '', array $rows = []): JSONResponse {
		return $this->respond(
			result: $this->linkService->import(
				recordType: $recordType,
				recordId: $recordId,
				rows: $rows,
			)
		);
	}//end import()

	/**
	 * End a party link.
	 *
	 * @param string $linkId The link's uuid.
	 *
	 * @return JSONResponse The ended link, or the refusal.
	 *
	 * @spec openspec/changes/party-kinds-accepted-per-case-type/specs/party-kind-registry/spec.md#requirement-a-kind-declared-single-shall-refuse-a-second-holder-on-one-record-req-pkr-005
	 */
	#[NoAdminRequired]
	public function endLink(string $linkId): JSONResponse {
		return $this->respond(result: $this->linkService->end(linkId: $linkId));
	}//end endLink()

	/**
	 * Turn a service result into a JSON response.
	 *
	 * @param array<string, mixed> $result The service's answer.
	 *
	 * @return JSONResponse The response.
	 */
	private function respond(array $result): JSONResponse {
		$status = (int)($result['status'] ?? 200);
		unset($result['status']);

		return new JSONResponse($result, $status);
	}//end respond()
}//end class
