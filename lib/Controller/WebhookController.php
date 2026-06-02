<?php

/**
 * Pipelinq WebhookController.
 *
 * Webhook subscription management for CRM events, delegating entirely to
 * OpenRegister's webhook engine (WebhookMapper for persistence, WebhookService
 * for test delivery) — the webhook subsystem is NOT reimplemented in Pipelinq.
 * Listing is available to any authenticated user; create / update / delete /
 * test require a Nextcloud admin (REQ-NFR-005). The test action fires a real
 * outbound request, so it is gated identically to the write endpoints.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
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
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Controller for webhook subscription endpoints (OpenRegister-backed).
 *
 * All persistence goes through OpenRegister's WebhookMapper and all test
 * delivery through WebhookService; this controller adds only the Pipelinq REST
 * surface, pagination envelope and admin gating. The OR services are resolved
 * lazily from the container so the app degrades gracefully when OpenRegister is
 * not installed.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Standard NC controller deps
 *  plus the lazily-resolved OR webhook collaborators.
 *
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.2
 */
class WebhookController extends Controller
{
    /**
     * Editable webhook properties accepted from a client.
     *
     * @var array<int, string>
     */
    private const EDITABLE = [
        'name',
        'url',
        'events',
        'headers',
        'enabled',
        'retryPolicy',
        'maxRetries',
        'timeout',
    ];

    /**
     * Constructor.
     *
     * @param IRequest           $request      The request.
     * @param ContainerInterface $container    The DI container (OR services).
     * @param IUserSession       $userSession  The user session.
     * @param IGroupManager      $groupManager The group manager.
     * @param IL10N              $l10n         The localization service.
     * @param LoggerInterface    $logger       The logger.
     */
    public function __construct(
        IRequest $request,
        private ContainerInterface $container,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List webhook subscriptions (paginated).
     *
     * @return JSONResponse The paginated webhook list.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.2
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $guard = $this->requireUser();
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

        return $this->run(
            action: function (): array {
                $mapper   = $this->webhookMapper();
                $webhooks = $mapper->findAll();
                $results  = array_map(static fn ($webhook): array => $webhook->jsonSerialize(), $webhooks);

                return [
                    'results' => $results,
                    'total'   => count($results),
                    'page'    => 1,
                    'pages'   => 1,
                ];
            },
            label: 'index',
            key: null
        );
    }//end index()

    /**
     * Create a webhook subscription (admin only).
     *
     * @return JSONResponse The created webhook.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.2
     */
    #[NoAdminRequired]
    public function create(): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

        $data = $this->sanitize(data: $this->request->getParams());

        return $this->run(
            action: fn (): array => ['webhook' => $this->webhookMapper()->createFromArray(data: $data)->jsonSerialize()],
            label: 'create',
            key: null,
            success: Http::STATUS_CREATED
        );
    }//end create()

    /**
     * Update a webhook subscription (admin only).
     *
     * @param int $id The webhook ID.
     *
     * @return JSONResponse The updated webhook.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.2
     */
    #[NoAdminRequired]
    public function update(int $id): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

        $data = $this->sanitize(data: $this->request->getParams());

        return $this->run(
            action: fn (): array => ['webhook' => $this->webhookMapper()->updateFromArray(id: $id, data: $data)->jsonSerialize()],
            label: 'update',
            key: null
        );
    }//end update()

    /**
     * Delete a webhook subscription (admin only).
     *
     * @param int $id The webhook ID.
     *
     * @return JSONResponse The deletion result.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.2
     */
    #[NoAdminRequired]
    public function destroy(int $id): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

        return $this->run(
            action: function () use ($id): array {
                $mapper  = $this->webhookMapper();
                $webhook = $mapper->find(id: $id);
                $mapper->delete(entity: $webhook);

                return ['status' => 'deleted'];
            },
            label: 'destroy',
            key: null
        );
    }//end destroy()

    /**
     * Fire a test event to a webhook (admin only).
     *
     * @param int $id The webhook ID.
     *
     * @return JSONResponse The test delivery result.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.2
     */
    #[NoAdminRequired]
    public function test(int $id): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

        return $this->run(
            action: function () use ($id): array {
                $webhook = $this->webhookMapper()->find(id: $id);
                $payload = [
                    'test'      => true,
                    'message'   => 'Pipelinq test webhook',
                    'timestamp' => date('c'),
                ];

                $success = $this->webhookService()->deliverWebhook(
                    webhook: $webhook,
                    eventName: 'OCA\OpenRegister\Event\TestEvent',
                    payload: $payload,
                    attempt: 1
                );

                $message = 'Test webhook delivery failed';
                if ($success === true) {
                    $message = 'Test webhook delivered';
                }

                return [
                    'success' => $success,
                    'message' => $message,
                ];
            },
            label: 'test',
            key: null
        );
    }//end test()

    /**
     * List the available webhook event types.
     *
     * @return JSONResponse The supported CRM event types.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.2
     */
    #[NoAdminRequired]
    public function events(): JSONResponse
    {
        $guard = $this->requireUser();
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

        return new JSONResponse(
            [
                'results' => [
                    'lead_created',
                    'lead_stage_changed',
                    'lead_assigned',
                    'contact_created',
                    'request_created',
                    'request_status_changed',
                    'marketing_segment_match',
                ],
            ]
        );
    }//end events()

    /**
     * Whitelist the editable webhook properties from raw client input.
     *
     * @param array<string, mixed> $data The raw parameters.
     *
     * @return array<string, mixed> The whitelisted properties.
     */
    private function sanitize(array $data): array
    {
        $clean = [];
        foreach (self::EDITABLE as $key) {
            if (array_key_exists($key, $data) === true) {
                $clean[$key] = $data[$key];
            }
        }

        return $clean;
    }//end sanitize()

    /**
     * Resolve the OpenRegister WebhookMapper.
     *
     * @return object The webhook mapper.
     *
     * @throws RuntimeException If OpenRegister is not available.
     */
    private function webhookMapper(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Db\WebhookMapper');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister webhook service is not available.');
        }
    }//end webhookMapper()

    /**
     * Resolve the OpenRegister WebhookService.
     *
     * @return object The webhook service.
     *
     * @throws RuntimeException If OpenRegister is not available.
     */
    private function webhookService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\WebhookService');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister webhook service is not available.');
        }
    }//end webhookService()

    /**
     * Require an authenticated user.
     *
     * @return null|JSONResponse A 401 response when unauthenticated, else null.
     */
    private function requireUser(): ?JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Authentication required')],
                Http::STATUS_UNAUTHORIZED
            );
        }

        return null;
    }//end requireUser()

    /**
     * Require an authenticated Nextcloud admin.
     *
     * @return null|JSONResponse A 401/403 response when not an admin, else null.
     */
    private function requireAdmin(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Authentication required')],
                Http::STATUS_UNAUTHORIZED
            );
        }

        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Administrator privileges required')],
                Http::STATUS_FORBIDDEN
            );
        }

        return null;
    }//end requireAdmin()

    /**
     * Run an action with shared error handling and a JSON envelope.
     *
     * @param callable    $action  The action returning the response payload.
     * @param string      $label   A short label for log context.
     * @param string|null $key     Optional envelope key wrapping the payload.
     * @param int         $success The success HTTP status.
     *
     * @return JSONResponse The response.
     */
    private function run(callable $action, string $label, ?string $key='data', int $success=Http::STATUS_OK): JSONResponse
    {
        try {
            $payload = $action();
            if ($key !== null) {
                $payload = [$key => $payload];
            }

            return new JSONResponse($payload, $success);
        } catch (RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l10n->t('Webhook not found')], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('WebhookController::'.$label.' failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end run()
}//end class
