<?php

/**
 * Pipelinq BerichtenboxAdminController.
 *
 * Admin-only operational endpoints for the Berichtenbox bridge:
 * retry a failed message + read aggregate delivery stats. Both routes
 * are admin-gated by NC's framework default (no #[NoAdminRequired])
 * per [[nc-security-defaults]]; SecurityMiddleware enforces it.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/tasks.md#3.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Admin endpoints for Berichtenbox ops.
 */
class BerichtenboxAdminController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request      Request.
     * @param ContainerInterface $container    DI container.
     * @param IAppConfig         $appConfig    App config.
     * @param IUserSession       $userSession  The user session.
     * @param IGroupManager      $groupManager The group manager.
     */
    public function __construct(
        IRequest $request,
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Reject anyone who is not a full Nextcloud administrator.
     *
     * #[AuthorizedAdminSetting] admits a full admin OR a user holding a
     * delegated grant for the named settings section. These two endpoints
     * were strictly admin-only before the attribute was added (they relied on
     * NC's framework default), and this keeps them that way, so declaring the
     * posture does not widen it. Mirrors PortalAdminController::assertAdmin().
     *
     * @return JSONResponse|null A 403 response, or null when the caller is an admin.
     */
    private function assertAdmin(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null || $this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(['error' => 'Admin required'], Http::STATUS_FORBIDDEN);
        }

        return null;
    }//end assertAdmin()

    /**
     * POST /api/admin/berichtenbox/message/{id}/retry — re-queue a failed
     * message for immediate dispatch.
     *
     * @param string $id BerichtenboxMessage uuid.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/archive/2026-06-14-burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-retry-012
     */
    #[AuthorizedAdminSetting(settings: \OCA\Pipelinq\Settings\AdminSettings::class)]
    public function retry(string $id): JSONResponse
    {
        $forbidden = $this->assertAdmin();
        if ($forbidden instanceof JSONResponse) {
            return $forbidden;
        }

        try {
            $service = $this->getObjectService();
            $message = $service->find(
                id: $id,
                register: $this->appConfig->getValueString(Application::APP_ID, 'register', ''),
                schema: $this->appConfig->getValueString(Application::APP_ID, 'berichtenboxMessage_schema', '')
            );
        } catch (Throwable $e) {
            return new JSONResponse(
                ['error' => 'Message not found: '.$e->getMessage()],
                Http::STATUS_NOT_FOUND
            );
        }

        if ($message === null) {
            return new JSONResponse(['error' => 'Message not found'], Http::STATUS_NOT_FOUND);
        }

        $data = $this->toArray(row: $message);
        if ($data === null) {
            return new JSONResponse(['error' => 'Unreadable message'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        // Reset retryCount + clear nextRetryAt + flip back to queued.
        $data['retryCount']     = 0;
        $data['nextRetryAt']    = '';
        $data['deliveryStatus'] = 'queued';
        try {
            $service
                ->saveObject(
                    object: $data,
                    extend: [],
                    register: $this->appConfig->getValueString(Application::APP_ID, 'register', ''),
                    schema: $this->appConfig->getValueString(Application::APP_ID, 'berichtenboxMessage_schema', ''),
                    uuid: ($data['uuid'] ?? $id)
                );
        } catch (Throwable $e) {
            return new JSONResponse(
                ['error' => 'Save failed: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return new JSONResponse(['ok' => true, 'id' => $id, 'status' => 'queued']);
    }//end retry()

    /**
     * GET /api/admin/berichtenbox/stats — aggregate delivery counts.
     *
     * @return JSONResponse
     *
     * @spec exclude mechanical phpmd cleanup — counter tally extracted to a helper, behaviour unchanged
     */
    #[AuthorizedAdminSetting(settings: \OCA\Pipelinq\Settings\AdminSettings::class)]
    public function stats(): JSONResponse
    {
        $forbidden = $this->assertAdmin();
        if ($forbidden instanceof JSONResponse) {
            return $forbidden;
        }

        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'berichtenboxMessage_schema', '');
        $counters = [
            'queued'           => 0,
            'sent'             => 0,
            'read'             => 0,
            'fallback-emailed' => 0,
            'failed'           => 0,
            'opted-out'        => 0,
        ];
        if ($register === '' || $schema === '') {
            return new JSONResponse(['counters' => $counters, 'unread' => 0]);
        }

        try {
            $rows = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register' => $register,
                        'schema'   => $schema,
                    ],
                ]
            );
        } catch (Throwable $e) {
            return new JSONResponse(
                ['error' => 'Stats query failed: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return new JSONResponse($this->tally(rows: $rows, counters: $counters));
    }//end stats()

    /**
     * Tally delivery counters and unread total from OR rows.
     *
     * @param iterable $rows     OR result rows.
     * @param array    $counters Zero-initialised counter map.
     *
     * @return array{counters: array, unread: int}
     */
    private function tally(iterable $rows, array $counters): array
    {
        $unread = 0;
        foreach ($rows as $row) {
            $data = $this->toArray(row: $row);
            if ($data === null) {
                continue;
            }

            $status = (string) ($data['deliveryStatus'] ?? '');
            if (isset($counters[$status]) === true) {
                $counters[$status]++;
            }

            if ($status === 'sent' && (($data['readAt'] ?? '') === '' || $data['readAt'] === null)) {
                $unread++;
            }
        }

        return ['counters' => $counters, 'unread' => $unread];
    }//end tally()

    /**
     * Resolve OR service.
     *
     * @return \OCA\OpenRegister\Service\ObjectService
     */
    private function getObjectService(): \OCA\OpenRegister\Service\ObjectService
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()

    /**
     * Normalise OR result to array.
     *
     * @param mixed $row Row.
     *
     * @return array|null
     */
    private function toArray(mixed $row): ?array
    {
        if (is_array($row) === true) {
            return $row;
        }

        if (is_object($row) === true) {
            if (method_exists($row, 'jsonSerialize') === true) {
                $serialized = $row->jsonSerialize();
                if (is_array($serialized) === true) {
                    return $serialized;
                }
            }

            if (method_exists($row, 'getObject') === true) {
                $inner = $row->getObject();
                if (is_array($inner) === true) {
                    return $inner;
                }
            }
        }

        return null;
    }//end toArray()
}//end class
