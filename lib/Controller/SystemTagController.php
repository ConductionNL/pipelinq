<?php

/**
 * Pipelinq SystemTagController.
 *
 * The shared admin actions of the controllers that manage one type of system tag.
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
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Lists, creates, renames and deletes the system tags of one object type.
 *
 * A subclass exposes the routed actions with their access attributes
 * (listing open to every logged-in user, the rest admin settings) and names
 * its tag type and its log label.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
abstract class SystemTagController extends Controller {

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
	 * List all tags of this controller's type.
	 *
	 * @return JSONResponse The response containing tags.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	protected function listTags(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(
			[
				'success' => true,
				'tags' => $this->systemTagService->getTags($this->objectType()),
			]
		);
	}//end listTags()

	/**
	 * Create a new tag of this controller's type.
	 *
	 * @return JSONResponse The response containing the created tag.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	protected function createTag(): JSONResponse {
		$name = $this->request->getParam('name', '');

		try {
			$tag = $this->systemTagService->addTag(
				objectType: $this->objectType(),
				name: $name
			);
			return new JSONResponse(['success' => true, 'tag' => $tag]);
		} catch (\InvalidArgumentException|\RuntimeException $e) {
			return new JSONResponse(['success' => false, 'message' => $e->getMessage()], 400);
		}
	}//end createTag()

	/**
	 * Rename a tag of this controller's type.
	 *
	 * @param string $id The tag ID.
	 *
	 * @return JSONResponse The response containing the renamed tag.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	protected function renameTagById(string $id): JSONResponse {
		$name = $this->request->getParam('name', '');

		try {
			$tag = $this->systemTagService->renameTag(
				objectType: $this->objectType(),
				tagId: (int)$id,
				newName: $name
			);
			return new JSONResponse(['success' => true, 'tag' => $tag]);
		} catch (\InvalidArgumentException|\RuntimeException $e) {
			return new JSONResponse(['success' => false, 'message' => $e->getMessage()], 400);
		}
	}//end renameTagById()

	/**
	 * Delete a tag of this controller's type.
	 *
	 * @param string $id The tag ID.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	protected function deleteTag(string $id): JSONResponse {
		try {
			$objectType = $this->objectType();
			$tagId = (int)$id;
			$this->systemTagService->removeTag(
				objectType: $objectType,
				tagId: $tagId
			);
			return new JSONResponse(['success' => true]);
		} catch (\Exception $e) {
			$this->logger->error($this->logLabel() . '::destroy failed', ['exception' => $e]);
			return new JSONResponse(['success' => false, 'message' => 'An unexpected error occurred'], 500);
		}
	}//end deleteTag()

	/**
	 * The object type of the system tags this controller manages.
	 *
	 * @return string The object type.
	 */
	abstract protected function objectType(): string;

	/**
	 * The controller name its failures are logged under.
	 *
	 * @return string The log label.
	 */
	abstract protected function logLabel(): string;
}//end class
