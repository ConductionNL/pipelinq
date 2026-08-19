<?php

/**
 * Pipelinq BerichtenboxAdminController.
 *
 * Admin-only operational endpoints for the Berichtenbox bridge:
 * retry a failed message + read aggregate delivery stats. Both routes
 * are admin-gated by Nextcloud's framework default — no NoAdminRequired
 * attribute is present — per [[nc-security-defaults]]; SecurityMiddleware
 * enforces it. Each method declares that posture in its own docblock.
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
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
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
     * @param IRequest           $request   Request.
     * @param ContainerInterface $container DI container.
     * @param IAppConfig         $appConfig App config.
     */
    public function __construct(
        IRequest $request,
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * POST /api/admin/berichtenbox/message/{id}/retry — re-queue a failed
     * message for immediate dispatch.
     *
     * @param string $id BerichtenboxMessage uuid.
     *
     * @auth admin-only Re-dispatches a citizen message on any tenant's behalf; restricted to server administrators by the framework default.
     *
     * @return JSONResponse
     */
    public function retry(string $id): JSONResponse
    {
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
     * @auth admin-only Exposes cross-tenant delivery totals for the whole instance; restricted to server administrators by the framework default.
     *
     * @return JSONResponse
     *
     * @spec exclude mechanical phpmd cleanup — counter tally extracted to a helper, behaviour unchanged
     */
    public function stats(): JSONResponse
    {
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
