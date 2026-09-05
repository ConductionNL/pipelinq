<?php

/**
 * Pipelinq WeeklyReviewController.
 *
 * The weekly review as one response, and the action that composes a fresh
 * one. There is no send endpoint and no publish endpoint here, and that is
 * the point: the review recommends, a person acts (ADR-088).
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
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-three-sources-and-names-the-one-it-cannot
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Marketing\WeeklyReviewService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * WeeklyReviewController: read the review, or compose one.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-three-sources-and-names-the-one-it-cannot
 */
class WeeklyReviewController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request The request.
	 * @param WeeklyReviewService $reviews The review composer.
	 * @param IUserSession $userSession The session.
	 * @param ObjectOwnerAccessPolicy $policy CRM privilege check.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-three-sources-and-names-the-one-it-cannot
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly WeeklyReviewService $reviews,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The latest stored review, or a freshly composed one when there is none.
	 *
	 * @return JSONResponse The review, in one response.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-three-sources-and-names-the-one-it-cannot
	 */
	#[NoAdminRequired]
	public function show(): JSONResponse {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}

		$review = $this->reviews->latest();
		if ($review === null) {
			// Never an empty page. A tenant that has not run the agent yet
			// still gets last week's numbers, which is the half of the review
			// that does not need one.
			$review = $this->reviews->compose();
		}

		return new JSONResponse($review);
	}//end show()

	/**
	 * Compose the review for one week and store it.
	 *
	 * @param string $weekStarting The Monday, `YYYY-MM-DD`. Empty means last week.
	 *
	 * @return JSONResponse The stored review.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-three-sources-and-names-the-one-it-cannot
	 */
	#[NoAdminRequired]
	public function generate(string $weekStarting = ''): JSONResponse {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}

		$review = $this->reviews->generate(weekStarting: $weekStarting);
		if ($review === null) {
			return new JSONResponse(['error' => 'write_failed'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse($review);
	}//end generate()

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
