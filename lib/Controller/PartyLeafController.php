<?php

/**
 * Pipelinq PartyLeafController.
 *
 * The HTTP face of the party panel: a party's typed fields and its standing
 * indicators, the blocking question a consuming app asks before it acts, the
 * acknowledgement a handler records, and the parent an organisation is moved
 * under.
 *
 * The blocking route ANSWERS. It does not intercept: pipelinq places no
 * listener, middleware or hook on another app's send path, because doing so
 * would mean knowing about every outbound path in the fleet.
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
 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-pipelinq-shall-answer-whether-an-indicator-blocks-an-act-and-shall-not-intercept-it-req-pfi-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Integration\PartyLeafProvider;
use OCA\Pipelinq\Service\PartyIndicatorService;
use OCA\Pipelinq\Service\PartyOrganisationTreeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Routes for the party panel, the blocking question and the tree.
 *
 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-a-consuming-app-shall-read-party-fields-and-indicators-through-a-leaf-req-pfi-007
 */
class PartyLeafController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PartyLeafProvider $provider The leaf's data provider.
	 * @param PartyIndicatorService $indicatorService Resolves and answers about indicators.
	 * @param PartyOrganisationTreeService $treeService The guarded organisation tree.
	 */
	public function __construct(
		IRequest $request,
		private readonly PartyLeafProvider $provider,
		private readonly PartyIndicatorService $indicatorService,
		private readonly PartyOrganisationTreeService $treeService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * A party's typed fields and its live indicators.
	 *
	 * @param string $partyId The party record's uuid.
	 *
	 * @return JSONResponse The panel, or the refusal.
	 *
	 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-a-consuming-app-shall-read-party-fields-and-indicators-through-a-leaf-req-pfi-007
	 */
	#[NoAdminRequired]
	public function panel(string $partyId): JSONResponse {
		return $this->respond(result: $this->provider->describe(partyId: $partyId));
	}//end panel()

	/**
	 * Whether an indicator blocks an act on a party.
	 *
	 * @param string $partyId The party record's uuid.
	 * @param string $act One of send, publishAddress or handle.
	 *
	 * @return JSONResponse The answer, naming any indicator that blocks.
	 *
	 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-pipelinq-shall-answer-whether-an-indicator-blocks-an-act-and-shall-not-intercept-it-req-pfi-004
	 */
	#[NoAdminRequired]
	public function blocked(string $partyId, string $act): JSONResponse {
		// The party is read through the leaf first, so an unauthorised caller
		// is refused here exactly as it is on the panel. Without it this route
		// would answer questions about parties its caller may not see.
		$panel = $this->provider->describe(partyId: $partyId);
		if ((int)($panel['status'] ?? 500) !== 200) {
			return $this->respond(result: $panel);
		}

		try {
			$answer = $this->indicatorService->isBlocked(partyId: $partyId, act: $act);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}

		return new JSONResponse($answer, 200);
	}//end blocked()

	/**
	 * Record that a handler has seen an indicator that demands it.
	 *
	 * @param string $valueId The indicator value's uuid.
	 *
	 * @return JSONResponse The updated value, or the refusal.
	 *
	 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/contactmomenten/spec.md#requirement-the-contact-moment-panel-shall-show-the-partys-indicators-req-cmi-001
	 */
	#[NoAdminRequired]
	public function acknowledge(string $valueId): JSONResponse {
		return $this->respond(result: $this->indicatorService->acknowledge(valueId: $valueId));
	}//end acknowledge()

	/**
	 * Move an organisation under a parent, taking its subtree with it.
	 *
	 * @param string $partyId The organisation to move.
	 * @param string|null $parentId The new parent, or null to make it a root.
	 *
	 * @return JSONResponse The result, or the refusal naming what it refused.
	 *
	 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-organisations-shall-nest-as-a-guarded-tree-carrying-their-own-fields-req-pfi-005
	 */
	#[NoAdminRequired]
	public function setParent(string $partyId, ?string $parentId = null): JSONResponse {
		return $this->respond(
			result: $this->treeService->setParent(nodeId: $partyId, parentId: $parentId)
		);
	}//end setParent()

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
