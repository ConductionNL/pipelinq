<?php

/**
 * Pipelinq MyWorkActivityController.
 *
 * GET /api/my-work/recent-activity: the latest audit-trail entries of the
 * work assigned to the session user, for the Recent activity section of My
 * work.
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
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\MyWork\RecentActivityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The Recent activity of My work, always the session user's own.
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
 */
class MyWorkActivityController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest              $request  The HTTP request.
	 * @param RecentActivityService $activity The recent activity.
	 * @param IUserSession          $session  The signed-in user.
	 */
	public function __construct(
		IRequest $request,
		private readonly RecentActivityService $activity,
		private readonly IUserSession $session,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The session user's recent activity as `{items: [...]}`; 401 without a user.
	 *
	 * @return JSONResponse The entries.
	 *
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(['items' => $this->activity->forUser(userId: $user->getUID())]);
	}//end index()
}//end class
