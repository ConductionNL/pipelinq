<?php

/**
 * Pipelinq MyWorkMeetingsController.
 *
 * GET /api/my-work/meetings: the session user's meetings of the next seven
 * days that link a pipelinq client, contact or lead, for the Upcoming
 * meetings section of My work. Always the session user's own calendars.
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
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-upcoming-meetings-from-the-calendar-req-mw-130
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\MyWork\MyWorkCalendarService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The Upcoming meetings of My work.
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-upcoming-meetings-from-the-calendar-req-mw-130
 */
class MyWorkMeetingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest              $request  The HTTP request.
	 * @param MyWorkCalendarService $meetings The linked meetings.
	 * @param IUserSession          $session  The signed-in user.
	 * @param LoggerInterface       $logger   The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly MyWorkCalendarService $meetings,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The session user's linked meetings as `{items: [...]}`.
	 *
	 * A calendar that cannot be read answers an empty list, so My work hides
	 * the section instead of showing an error.
	 *
	 * @return JSONResponse The meetings, or 401 without a user.
	 *
	 * @spec openspec/changes/my-work-roadmap/tasks.md#task-5.2
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$uid = $this->session->getUser()?->getUID();
		if ($uid === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$items = $this->meetings->upcoming(userId: $uid);
		} catch (Throwable $e) {
			$this->logger->info('Pipelinq: My work read no meetings', ['exception' => $e->getMessage()]);
			$items = [];
		}

		return new JSONResponse(['items' => $items]);
	}//end index()
}//end class
