<?php

/**
 * Pipelinq RequestChannelController.
 *
 * Controller for managing request channel tags via SystemTagService.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SystemTagService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for request channel management.
 *
 * @spec openspec/specs/omnichannel-registratie/spec.md#requirement-unified-contact-registration-form
 */
class RequestChannelController extends Controller {
	private const OBJECT_TYPE = 'pipelinq_request_channel';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param SystemTagService $systemTagService The system tag service.
	 * @param IUserSession $userSession The user session.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private SystemTagService $systemTagService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List all request channel tags.
	 *
	 * @return JSONResponse The response containing tags.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->tagActions()->index(user: $this->userSession->getUser());
	}//end index()

	/**
	 * Create a new request channel tag.
	 *
	 * @return JSONResponse The response containing the created tag.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function create(): JSONResponse {
		return $this->tagActions()->create(name: $this->request->getParam('name', ''));
	}//end create()

	/**
	 * Rename a request channel tag.
	 *
	 * @param string $id The tag ID.
	 *
	 * @return JSONResponse The response containing the renamed tag.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function update(string $id): JSONResponse {
		return $this->tagActions()->rename(id: $id, name: $this->request->getParam('name', ''));
	}//end update()

	/**
	 * Delete a request channel tag.
	 *
	 * @param string $id The tag ID.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function destroy(string $id): JSONResponse {
		return $this->tagActions()->delete(id: $id, logger: $this->logger);
	}//end destroy()

	/**
	 * The actions on this controller's kind of tag.
	 *
	 * @return SystemTagActions The actions.
	 */
	private function tagActions(): SystemTagActions {
		return new SystemTagActions(
			systemTagService: $this->systemTagService,
			kind: new SystemTagKind(objectType: self::OBJECT_TYPE, logLabel: 'RequestChannelController')
		);
	}//end tagActions()
}//end class
