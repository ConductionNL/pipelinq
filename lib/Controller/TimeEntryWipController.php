<?php

/**
 * Pipelinq TimeEntryWipController.
 *
 * Admin-only manual re-dispatch of a failed Shillinq Work-In-Progress sync.
 * The endpoint re-fetches the time entry, re-dispatches the WIP event
 * through the WIP service and records the new outcome on the entry.
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
 * @spec openspec/changes/pipelinq-time-to-shillinq-wip/specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ShillinqWipService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Manual WIP re-dispatch controller.
 *
 * Gated with #[AuthorizedAdminSetting]; only Nextcloud administrators can
 * trigger a re-dispatch of a time entry to the Shillinq WIP ledger. Idempotent
 * for entries already marked `synced` (returns the existing status without
 * dispatching a duplicate event).
 *
 * @spec openspec/changes/pipelinq-time-to-shillinq-wip/specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-003
 */
class TimeEntryWipController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ShillinqWipService $wipService The Shillinq WIP service.
	 * @param ContainerInterface $container The DI container (OpenRegister ObjectService lookup).
	 * @param IAppConfig $appConfig The app configuration.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private ShillinqWipService $wipService,
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Manually re-dispatch a time entry to the Shillinq WIP ledger.
	 *
	 * Returns the new sync status as JSON. Errors are surfaced with appropriate
	 * HTTP codes: 400 when the integration is unconfigured, 404 when the entry
	 * cannot be found, 502 when dispatch fails.
	 *
	 * @param string $uuid The time entry UUID to re-dispatch.
	 *
	 * @return JSONResponse The new WIP sync status, or an error.
	 *
	 * @spec openspec/changes/pipelinq-time-to-shillinq-wip/specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-003
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function retry(string $uuid): JSONResponse {
		if ($this->wipService->shouldDispatch() === false) {
			return new JSONResponse(['error' => 'Shillinq WIP integration is not configured.'], 400);
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'timeEntry_schema', '');
		if ($register === '' || $schema === '' || $uuid === '') {
			return new JSONResponse(['error' => 'Time entry configuration is incomplete.'], 400);
		}

		$entry = $this->fetchEntry(register: $register, schema: $schema, uuid: $uuid);
		if ($entry === null) {
			return new JSONResponse(['error' => 'Time entry not found.'], 404);
		}

		// Idempotency: already synced entries are returned as-is without re-dispatch
		// (REQ-WIP-001 idempotent scenario). The caller sees the current status,
		// no duplicate event is emitted.
		if (($entry['wipSyncStatus'] ?? null) === 'synced') {
			return new JSONResponse(
				[
					'status' => 'synced',
					'wipSyncStatus' => 'synced',
					'wipSyncedAt' => (string)($entry['wipSyncedAt'] ?? ''),
				]
			);
		}

		// Reset to pending before re-dispatch (REQ-WIP-003 manual retry).
		$entry['wipSyncStatus'] = 'pending';
		$this->persist(register: $register, schema: $schema, uuid: $uuid, data: $entry);

		$approvedBy = (string)($entry['approvedBy'] ?? '');
		$approvedAt = (string)($entry['approvedAt'] ?? '');

		$success = $this->wipService->dispatchWipEvent(
			timeEntry: $entry,
			approvedBy: $approvedBy,
			approvedAt: $approvedAt
		);

		if ($success === true) {
			$entry['wipSyncStatus'] = 'synced';
			$entry['wipSyncedAt'] = $this->wipService->now();
			$this->persist(register: $register, schema: $schema, uuid: $uuid, data: $entry);

			return new JSONResponse(
				[
					'status' => 'synced',
					'wipSyncStatus' => 'synced',
					'wipSyncedAt' => $entry['wipSyncedAt'],
				]
			);
		}

		$entry['wipSyncStatus'] = 'failed';
		$this->persist(register: $register, schema: $schema, uuid: $uuid, data: $entry);

		return new JSONResponse(
			[
				'status' => 'failed',
				'wipSyncStatus' => 'failed',
				'message' => 'WIP dispatch failed.',
			],
			502
		);
	}//end retry()

	/**
	 * Fetch a time entry's data by UUID.
	 *
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 * @param string $uuid The time entry UUID.
	 *
	 * @return array<string, mixed>|null The time entry data, or null when not found.
	 */
	private function fetchEntry(string $register, string $schema, string $uuid): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$object = $objectService->find(id: $uuid, register: $register, schema: $schema);
			if ($object === null) {
				return null;
			}

			return $object->getObject();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to load time entry for WIP retry',
				['exception' => $e->getMessage(), 'uuid' => $uuid]
			);
			return null;
		}//end try
	}//end fetchEntry()

	/**
	 * Persist the mutated time entry data back to OpenRegister.
	 *
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 * @param string $uuid The time entry UUID.
	 * @param array<string, mixed> $data The mutated time entry data.
	 *
	 * @return void
	 */
	private function persist(string $register, string $schema, string $uuid, array $data): void {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$objectService->saveObject(
				object: $data,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $uuid
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to persist time entry WIP retry status',
				['exception' => $e->getMessage(), 'uuid' => $uuid]
			);
		}//end try
	}//end persist()
}//end class
