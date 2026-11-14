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
 * @spec openspec/specs/berichtenbox/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Util\OptionalPayloadToArray;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Admin endpoints for Berichtenbox ops.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
class BerichtenboxAdminController extends Controller {
	use OptionalPayloadToArray;

	/**
	 * Rows read per stats page.
	 *
	 * @var int
	 */
	private const STATS_PAGE_SIZE = 500;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request Request.
	 * @param IAppConfig $appConfig App config.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 */
	public function __construct(
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
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
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function retry(string $id): JSONResponse {
		try {
			$service = $this->getObjectService();
			$message = $service->find(
				id: $id,
				register: $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', ''),
				schema: $this->appConfig->getValueString(AppIdentity::APP_ID, 'berichtenboxMessage_schema', '')
			);
		} catch (Throwable $e) {
			return new JSONResponse(
				['error' => 'Message not found: ' . $e->getMessage()],
				Http::STATUS_NOT_FOUND
			);
		}

		if ($message === null) {
			return new JSONResponse(['error' => 'Message not found'], Http::STATUS_NOT_FOUND);
		}

		$data = $this->toArray(object: $message);
		if ($data === null) {
			return new JSONResponse(['error' => 'Unreadable message'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		// Reset retryCount + clear nextRetryAt + flip back to queued.
		$data['retryCount'] = 0;
		$data['nextRetryAt'] = '';
		$data['deliveryStatus'] = 'queued';
		try {
			$service
				->saveObject(
					object: $data,
					extend: [],
					register: $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', ''),
					schema: $this->appConfig->getValueString(AppIdentity::APP_ID, 'berichtenboxMessage_schema', ''),
					uuid: ($data['uuid'] ?? $id)
				);
		} catch (Throwable $e) {
			return new JSONResponse(
				['error' => 'Save failed: ' . $e->getMessage()],
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
	 * @spec openspec/changes/berichtenbox-admin-stats-pagination-and-tests/specs/berichtenbox-integration/spec.md
	 */
	public function stats(): JSONResponse {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'berichtenboxMessage_schema', '');
		$counters = [
			'queued' => 0,
			'sent' => 0,
			'read' => 0,
			'fallback-emailed' => 0,
			'failed' => 0,
			'opted-out' => 0,
		];
		if ($register === '' || $schema === '') {
			return new JSONResponse(['counters' => $counters, 'unread' => 0]);
		}

		try {
			$result = $this->tallyPaged(register: $register, schema: $schema, counters: $counters);
		} catch (Throwable $e) {
			return new JSONResponse(
				['error' => 'Stats query failed: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse($result);
	}//end stats()

	/**
	 * Tally the messages page by page, so no more than one page of rows is
	 * loaded at a time.
	 *
	 * Each read carries an explicit limit; the walk stops at the first page
	 * holding fewer rows than the limit.
	 *
	 * @param string $register Register id.
	 * @param string $schema   Message schema id.
	 * @param array  $counters Zero-initialised counter map.
	 *
	 * @return array{counters: array, unread: int}
	 *
	 * @spec openspec/changes/berichtenbox-admin-stats-pagination-and-tests/specs/berichtenbox-integration/spec.md
	 */
	private function tallyPaged(string $register, string $schema, array $counters): array {
		$result = ['counters' => $counters, 'unread' => 0];
		$offset = 0;
		do {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => self::STATS_PAGE_SIZE,
					'offset' => $offset,
				]
			);
			$result = $this->tally(rows: $rows, counters: $result['counters'], unread: $result['unread']);
			$offset += self::STATS_PAGE_SIZE;
			$fullPage = count($rows) === self::STATS_PAGE_SIZE;
		} while ($fullPage === true);

		return $result;
	}//end tallyPaged()

	/**
	 * Fold one page of OR rows into the delivery counters and the unread total.
	 *
	 * A message is unread when it was sent and carries no readAt.
	 *
	 * @param iterable $rows     OR result rows.
	 * @param array    $counters Counter map so far.
	 * @param int      $unread   Unread total so far.
	 *
	 * @return array{counters: array, unread: int} The totals including this page.
	 *
	 * @spec openspec/changes/berichtenbox-admin-stats-pagination-and-tests/specs/berichtenbox-integration/spec.md
	 */
	private function tally(iterable $rows, array $counters, int $unread): array {
		foreach ($rows as $row) {
			$data = $this->toArray(object: $row);
			if ($data === null) {
				continue;
			}

			$status = (string)($data['deliveryStatus'] ?? '');
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
	 * @return \OCA\OpenRegister\Contract\ObjectServiceInterface
	 */
	private function getObjectService(): \OCA\OpenRegister\Contract\ObjectServiceInterface {
		return $this->objectService;
	}//end getObjectService()
}//end class
