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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-10
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SystemTagService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for request channel management.
 */
class RequestChannelController extends Controller
{
    private const OBJECT_TYPE = 'pipelinq_request_channel';

    /**
     * Constructor.
     *
     * @param IRequest         $request          The request.
     * @param SystemTagService $systemTagService The system tag service.
     * @param IUserSession     $userSession      The user session.
     */
    public function __construct(
        IRequest $request,
        private SystemTagService $systemTagService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List all request channel tags.
     *
     * @return JSONResponse The response containing tags.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-10
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(
                [
                    'success' => true,
                    'tags'    => $this->systemTagService->getTags(self::OBJECT_TYPE),
                ]
                );
    }//end index()

    /**
     * Create a new request channel tag.
     *
     * @return JSONResponse The response containing the created tag.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-10
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function create(): JSONResponse
    {
        $name = $this->request->getParam('name', '');

        try {
            $tag = $this->systemTagService->addTag(
                objectType: self::OBJECT_TYPE,
                name: $name
            );
            return new JSONResponse(['success' => true, 'tag' => $tag]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return new JSONResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }//end create()

    /**
     * Rename a request channel tag.
     *
     * @param string $id The tag ID.
     *
     * @return JSONResponse The response containing the renamed tag.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-10
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function update(string $id): JSONResponse
    {
        $name = $this->request->getParam('name', '');

        try {
            $tag = $this->systemTagService->renameTag(
                objectType: self::OBJECT_TYPE,
                tagId: (int) $id,
                newName: $name
            );
            return new JSONResponse(['success' => true, 'tag' => $tag]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return new JSONResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }//end update()

    /**
     * Delete a request channel tag.
     *
     * @param string $id The tag ID.
     *
     * @return JSONResponse The response.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-10
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function destroy(string $id): JSONResponse
    {
        try {
            $objectType = self::OBJECT_TYPE;
            $tagId      = (int) $id;
            $this->systemTagService->removeTag(
                objectType: $objectType,
                tagId: $tagId
            );
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }//end destroy()
}//end class
