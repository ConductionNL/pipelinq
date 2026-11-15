<?php

/**
 * Pipelinq SystemTagActions.
 *
 * The admin actions on the system tags of one object type.
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

use OCA\Pipelinq\Kernel\Contracts\SystemTagServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Lists, creates, renames and deletes the system tags of one kind, for the
 * controllers that manage one kind of tag each.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
final class SystemTagActions {
	/**
	 * Constructor.
	 *
	 * @param SystemTagServiceInterface $systemTagService The system tag service.
	 * @param SystemTagKind    $kind             The tag type and its controller's log label.
	 */
	public function __construct(
		private readonly SystemTagServiceInterface $systemTagService,
		private readonly SystemTagKind $kind,
	) {
	}//end __construct()

	/**
	 * List the tags for a logged-in user.
	 *
	 * @param IUser|null $user The logged-in user, null when nobody is.
	 *
	 * @return JSONResponse The tags, or 401 without a user.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function index(?IUser $user): JSONResponse {
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(
			[
				'success' => true,
				'tags' => $this->systemTagService->getTags($this->kind->objectType),
			]
		);
	}//end index()

	/**
	 * Create a tag.
	 *
	 * @param string $name The tag name.
	 *
	 * @return JSONResponse The created tag, or 400 with the service's message.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function create(string $name): JSONResponse {
		try {
			$tag = $this->systemTagService->addTag(
				objectType: $this->kind->objectType,
				name: $name
			);
			return new JSONResponse(['success' => true, 'tag' => $tag]);
		} catch (\InvalidArgumentException|\RuntimeException $e) {
			return new JSONResponse(['success' => false, 'message' => $e->getMessage()], 400);
		}
	}//end create()

	/**
	 * Rename a tag.
	 *
	 * @param string $id   The tag ID.
	 * @param string $name The new name.
	 *
	 * @return JSONResponse The renamed tag, or 400 with the service's message.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function rename(string $id, string $name): JSONResponse {
		try {
			$tag = $this->systemTagService->renameTag(
				objectType: $this->kind->objectType,
				tagId: (int)$id,
				newName: $name
			);
			return new JSONResponse(['success' => true, 'tag' => $tag]);
		} catch (\InvalidArgumentException|\RuntimeException $e) {
			return new JSONResponse(['success' => false, 'message' => $e->getMessage()], 400);
		}
	}//end rename()

	/**
	 * Delete a tag; a failure is logged under the controller's label.
	 *
	 * @param string          $id     The tag ID.
	 * @param LoggerInterface $logger The controller's logger.
	 *
	 * @return JSONResponse Success, or 500 with a generic message.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function delete(string $id, LoggerInterface $logger): JSONResponse {
		try {
			$this->systemTagService->removeTag(
				objectType: $this->kind->objectType,
				tagId: (int)$id
			);
			return new JSONResponse(['success' => true]);
		} catch (\Exception $e) {
			$logger->error($this->kind->logLabel . '::destroy failed', ['exception' => $e]);
			return new JSONResponse(['success' => false, 'message' => 'An unexpected error occurred'], 500);
		}
	}//end delete()
}//end class
