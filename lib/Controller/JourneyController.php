<?php

/**
 * Pipelinq JourneyController.
 *
 * The journey endpoints the browser cannot get from the object API: writing
 * a journey compiles it into a flow, and reading its runs is what tells a
 * marketer who the journey refused and why.
 *
 * Authentication is not authorization: a journey reaches customers and its
 * run log names them, so it takes the same privilege check the blast and
 * campaign endpoints take.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Marketing\JourneyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * JourneyController: journeys, their compilation and their run log.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */
class JourneyController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request The request.
	 * @param JourneyService $journeys Journeys and their flows.
	 * @param IUserSession $userSession The session.
	 * @param ObjectOwnerAccessPolicy $policy CRM privilege check.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly JourneyService $journeys,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Every journey, with the flow status of each.
	 *
	 * @return JSONResponse The journeys.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}

		return new JSONResponse(['results' => $this->journeys->listJourneys()]);
	}//end index()

	/**
	 * One journey.
	 *
	 * @param string $id Journey UUID or slug.
	 *
	 * @return JSONResponse The journey, or 404.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}

		$journey = $this->journeys->find(journeyId: $id);
		if ($journey === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($journey);
	}//end show()

	/**
	 * Write a new journey and compile it.
	 *
	 * @return JSONResponse The stored journey.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		return $this->write(id: null);
	}//end create()

	/**
	 * Update a journey and compile it again.
	 *
	 * @param string $id Journey UUID or slug.
	 *
	 * @return JSONResponse The stored journey.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		return $this->write(id: $id);
	}//end update()

	/**
	 * Compile one journey again without changing it.
	 *
	 * @param string $id Journey UUID or slug.
	 *
	 * @return JSONResponse The journey with its flow status.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	#[NoAdminRequired]
	public function compile(string $id): JSONResponse {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}

		$journey = $this->journeys->compile(journeyId: $id, runAs: $this->uid());
		if ($journey === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($journey);
	}//end compile()

	/**
	 * What this journey did, and who it refused.
	 *
	 * @param string $id Journey UUID or slug.
	 *
	 * @return JSONResponse The runs.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
	 */
	#[NoAdminRequired]
	public function runs(string $id): JSONResponse {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}

		return new JSONResponse(['results' => $this->journeys->runsFor(journeyId: $id)]);
	}//end runs()

	/**
	 * The shared write path for create and update.
	 *
	 * @param string|null $id Journey UUID or slug, null when creating.
	 *
	 * @return JSONResponse The stored journey, or a typed refusal.
	 */
	private function write(?string $id): JSONResponse {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}

		$payload = $this->collectBody();
		if (trim((string)($payload['name'] ?? '')) === '') {
			return new JSONResponse(['error' => 'name_required'], Http::STATUS_BAD_REQUEST);
		}

		$stored = $this->journeys->save(payload: $payload, createdByUid: $this->uid(), journeyId: $id);
		if ($stored === null) {
			return new JSONResponse(['error' => 'write_failed'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse($stored);
	}//end write()

	/**
	 * The fields a client may set on a journey.
	 *
	 * `flowUuid`, `flowStatus` and `flowError` are absent by construction:
	 * the compiler stamps them, and a value arriving here would have to be
	 * dropped there anyway.
	 *
	 * @return array<string, mixed> The collected payload.
	 */
	private function collectBody(): array {
		$payload = [];
		foreach (['name', 'description', 'status', 'audienceSegment', 'waitFor'] as $key) {
			$value = $this->request->getParam($key);
			if (is_scalar($value) === true) {
				$payload[$key] = (string)$value;
			}
		}

		foreach (['trigger', 'condition', 'action'] as $key) {
			$value = $this->request->getParam($key);
			if (is_array($value) === true) {
				$payload[$key] = $value;
			}
		}

		return $payload;
	}//end collectBody()

	/**
	 * The acting user's id.
	 *
	 * @return string The uid, empty when there is no session.
	 */
	private function uid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end uid()

	/**
	 * Refuse a caller without a session or without the CRM privilege.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may proceed.
	 */
	private function guard(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end guard()
}//end class
