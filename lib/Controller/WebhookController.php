<?php

/**
 * Pipelinq WebhookController.
 *
 * Thin wrapper over OpenRegister's WebhookMapper + WebhookService for
 * CRM webhook subscription management. All persistence and delivery logic
 * is delegated upstream; this controller adds only the auth layer.
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
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for /api/webhooks endpoints — delegates to OpenRegister.
 *
 * Mutations require IGroupManager::isAdmin (REQ-NFR-005). The controller
 * never re-implements webhook persistence or dispatch — it lazily resolves
 * the upstream WebhookMapper / WebhookService and forwards.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.2
 */
class WebhookController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request      Request.
     * @param ContainerInterface $container    DI container (lazy OpenRegister resolution).
     * @param IUserSession       $userSession  User session.
     * @param IGroupManager      $groupManager Group manager.
     * @param LoggerInterface    $logger       Logger.
     */
    public function __construct(
        IRequest $request,
        private ContainerInterface $container,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * GET /api/webhooks — list webhook subscriptions (paginated).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.2
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $page  = max(1, (int) $this->request->getParam('page', 1));
        $limit = max(1, min(200, (int) $this->request->getParam('limit', 50)));
        try {
            $mapper = $this->getWebhookMapper();
            $rows   = $mapper->findAll(limit: $limit, offset: (($page - 1) * $limit));
        } catch (\Throwable $e) {
            $this->logger->warning('Webhook list failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['results' => [], 'total' => 0, 'page' => $page, 'pages' => 0]);
        }
        $results = $this->serializeRows($rows);
        return new JSONResponse([
            'results' => $results,
            'total'   => count($results),
            'page'    => $page,
            'pages'   => (count($results) > 0) ? 1 : 0,
        ]);
    }//end index()

    /**
     * POST /api/webhooks — create subscription (admin only).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.2
     */
    #[NoAdminRequired]
    public function create(): JSONResponse
    {
        if ($this->assertAdmin() === false) {
            return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }
        $data = $this->request->getParams();
        unset($data['_route']);
        try {
            $webhook = $this->getWebhookMapper()->createFromArray($data);
        } catch (\Throwable $e) {
            $this->logger->warning('Webhook create failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Create failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        return new JSONResponse($this->serializeRow($webhook), Http::STATUS_CREATED);
    }//end create()

    /**
     * GET /api/webhooks/{id} — webhook detail.
     *
     * @param string $id Webhook ID (numeric in OR, accepted as string).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.2
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        try {
            $webhook = $this->getWebhookMapper()->find((int) $id);
        } catch (\Throwable) {
            return new JSONResponse(['message' => 'Webhook not found'], Http::STATUS_NOT_FOUND);
        }
        return new JSONResponse($this->serializeRow($webhook));
    }//end show()

    /**
     * PUT /api/webhooks/{id} — update subscription (admin only).
     *
     * @param string $id Webhook ID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.2
     */
    #[NoAdminRequired]
    public function update(string $id): JSONResponse
    {
        if ($this->assertAdmin() === false) {
            return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }
        $data = $this->request->getParams();
        unset($data['_route']);
        try {
            $webhook = $this->getWebhookMapper()->updateFromArray((int) $id, $data);
        } catch (\Throwable $e) {
            $this->logger->warning('Webhook update failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Update failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        return new JSONResponse($this->serializeRow($webhook));
    }//end update()

    /**
     * DELETE /api/webhooks/{id} — delete subscription (admin only).
     *
     * @param string $id Webhook ID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.2
     */
    #[NoAdminRequired]
    public function destroy(string $id): JSONResponse
    {
        if ($this->assertAdmin() === false) {
            return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }
        try {
            $mapper  = $this->getWebhookMapper();
            $webhook = $mapper->find((int) $id);
            $mapper->delete($webhook);
        } catch (\Throwable $e) {
            $this->logger->warning('Webhook delete failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Delete failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        return new JSONResponse(['message' => 'Deleted']);
    }//end destroy()

    /**
     * POST /api/webhooks/{id}/test — fire a sample event (admin only).
     *
     * Delegates to WebhookService::deliverWebhook so the test event uses
     * the same retry / signing pipeline as production deliveries.
     *
     * @param string $id Webhook ID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.2
     */
    #[NoAdminRequired]
    public function test(string $id): JSONResponse
    {
        if ($this->assertAdmin() === false) {
            return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }
        try {
            $webhook = $this->getWebhookMapper()->find((int) $id);
            $payload = ['test' => true, 'app' => Application::APP_ID, 'ts' => date('c')];
            $ok      = $this->getWebhookService()->deliverWebhook($webhook, 'pipelinq.test.event', $payload, 1);
        } catch (\Throwable $e) {
            $this->logger->warning('Webhook test failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Test failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        return new JSONResponse(['delivered' => $ok]);
    }//end test()

    /**
     * Serialise a single webhook entity to an array.
     *
     * @param mixed $row Webhook entity or array.
     *
     * @return array<string, mixed>
     */
    private function serializeRow(mixed $row): array
    {
        if (is_array($row) === true) {
            return $row;
        }
        if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
            $out = $row->jsonSerialize();
            if (is_array($out) === true) {
                return $out;
            }
        }
        return [];
    }//end serializeRow()

    /**
     * Serialise a list of webhook entities to an array of arrays.
     *
     * @param iterable<mixed> $rows Webhook rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private function serializeRows(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->serializeRow($row);
        }
        return $out;
    }//end serializeRows()

    /**
     * Assert the current user is an admin.
     *
     * @return bool
     */
    private function assertAdmin(): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }
        return $this->groupManager->isAdmin($user->getUID());
    }//end assertAdmin()

    /**
     * Resolve the OpenRegister WebhookMapper from the container.
     *
     * @return object
     *
     * @throws \RuntimeException When OpenRegister is not installed.
     */
    private function getWebhookMapper(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Db\WebhookMapper');
        } catch (\Throwable) {
            throw new \RuntimeException('OpenRegister WebhookMapper is not available.');
        }
    }//end getWebhookMapper()

    /**
     * Resolve the OpenRegister WebhookService from the container.
     *
     * @return object
     *
     * @throws \RuntimeException When OpenRegister is not installed.
     */
    private function getWebhookService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\WebhookService');
        } catch (\Throwable) {
            throw new \RuntimeException('OpenRegister WebhookService is not available.');
        }
    }//end getWebhookService()
}//end class
