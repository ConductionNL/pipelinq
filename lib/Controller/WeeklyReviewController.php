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
 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Service\Marketing\WeeklyReviewService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * WeeklyReviewController: read the review, or compose one.
 *
 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
 */
class WeeklyReviewController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request The request.
	 * @param WeeklyReviewService $reviews The review composer.
	 * @param CrmPrivilegeCheck $privilege The session user and the CRM privilege check.
	 *
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly WeeklyReviewService $reviews,
		private readonly CrmPrivilegeCheck $privilege,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The latest stored review, or a freshly composed one when there is none.
	 *
	 * @return JSONResponse The review, in one response.
	 *
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
	 */
	#[NoAdminRequired]
	public function show(): JSONResponse {
		$denied = $this->privilege->refusal();
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
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
	 */
	#[NoAdminRequired]
	public function generate(string $weekStarting = ''): JSONResponse {
		$denied = $this->privilege->refusal();
		if ($denied !== null) {
			return $denied;
		}

		$review = $this->reviews->generate(weekStarting: $weekStarting);
		if ($review === null) {
			return new JSONResponse(['error' => 'write_failed'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse($review);
	}//end generate()
}//end class
