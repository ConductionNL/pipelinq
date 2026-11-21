<?php

/**
 * Pipelinq MyWorkActivityController.
 *
 * GET /api/my-work/recent-activity: the latest audit-trail entries of the
 * work assigned to the session user, for the Recent activity section of My
 * work. Always the session user's own items, read as that user.
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
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The Recent activity of My work.
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
	 * @param LoggerInterface       $logger   The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly RecentActivityService $activity,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The session user's recent activity as `{items: [...]}`.
	 *
	 * Work that cannot be read answers an empty list, so My work shows its
	 * empty text instead of an error.
	 *
	 * @return JSONResponse The entries, or 401 without a user.
	 *
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$uid = $this->session->getUser()?->getUID();
		if ($uid === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(['items' => $this->activityOf(uid: $uid)]);
	}//end index()

	/**
	 * The user's recent activity; none when the work cannot be read.
	 *
	 * @param string $uid The user.
	 *
	 * @return array<int, array<string, string>> The entries.
	 *
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
	 */
	private function activityOf(string $uid): array {
		try {
			return $this->activity->forUser(userId: $uid);
		} catch (Throwable $e) {
			$this->logger->info('Pipelinq: My work read no recent activity', ['exception' => $e->getMessage()]);
			return [];
		}
	}//end activityOf()
}//end class
