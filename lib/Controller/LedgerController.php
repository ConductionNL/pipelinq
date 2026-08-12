<?php

/**
 * Pipelinq LedgerController.
 *
 * Admin-only manual re-dispatch of a failed Shillinq project-ledger sync. The
 * endpoint re-fetches the project, re-dispatches the creation event through the
 * ledger service and records the new outcome on the project.
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
 * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-005-03
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ShillinqLedgerService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Manual ledger re-dispatch controller.
 *
 * Gated with #[AuthorizedAdminSetting]; only Nextcloud administrators can
 * trigger a re-dispatch of a project to the Shillinq ledger.
 *
 * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-005-03
 */
class LedgerController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ShillinqLedgerService $ledgerService The Shillinq ledger service.
	 * @param ContainerInterface $container The DI container (OpenRegister ObjectService lookup).
	 * @param IAppConfig $appConfig The app configuration.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private ShillinqLedgerService $ledgerService,
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Manually re-dispatch a project to the Shillinq ledger.
	 *
	 * @param string $projectId The project UUID to re-dispatch.
	 *
	 * @return JSONResponse The new ledger sync status, or an error.
	 *
	 * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-005-03
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function retry(string $projectId): JSONResponse {
		if ($this->ledgerService->shouldDispatch() === false) {
			return new JSONResponse(['error' => 'Shillinq ledger integration is not configured.'], 400);
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'project_schema', '');
		if ($register === '' || $schema === '' || $projectId === '') {
			return new JSONResponse(['error' => 'Project ledger configuration is incomplete.'], 400);
		}

		$project = $this->fetchProject(register: $register, schema: $schema, uuid: $projectId);
		if ($project === null) {
			return new JSONResponse(['error' => 'Project not found.'], 404);
		}

		// Reset to pending before re-dispatch (REQ-PLG-005-03).
		$project['ledgerSyncStatus'] = 'pending';
		$this->persist(register: $register, schema: $schema, uuid: $projectId, data: $project);

		$success = $this->ledgerService->dispatchProjectEvent(project: $project, eventType: 'retried');

		if ($success === true) {
			$project['ledgerSyncStatus'] = 'synced';
			$project['ledgerSyncedAt'] = $this->now();
			$this->persist(register: $register, schema: $schema, uuid: $projectId, data: $project);

			return new JSONResponse(
				[
					'ledgerSyncStatus' => 'synced',
					'ledgerSyncedAt' => $project['ledgerSyncedAt'],
				]
			);
		}

		$project['ledgerSyncStatus'] = 'failed';
		$this->persist(register: $register, schema: $schema, uuid: $projectId, data: $project);

		return new JSONResponse(['ledgerSyncStatus' => 'failed', 'error' => 'Ledger dispatch failed.'], 502);
	}//end retry()

	/**
	 * Fetch a project's data by UUID.
	 *
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 * @param string $uuid The project UUID.
	 *
	 * @return array<string, mixed>|null The project data, or null when not found.
	 */
	private function fetchProject(string $register, string $schema, string $uuid): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$object = $objectService->find(id: $uuid, register: $register, schema: $schema);
			if ($object === null) {
				return null;
			}

			return $object->getObject();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to load project for ledger retry',
				['exception' => $e->getMessage(), 'uuid' => $uuid]
			);
			return null;
		}//end try
	}//end fetchProject()

	/**
	 * Persist the mutated project data back to OpenRegister.
	 *
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 * @param string $uuid The project UUID.
	 * @param array<string, mixed> $data The mutated project data.
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
				'Pipelinq: failed to persist project ledger retry status',
				['exception' => $e->getMessage(), 'uuid' => $uuid]
			);
		}//end try
	}//end persist()

	/**
	 * Current UTC timestamp in ISO 8601 format.
	 *
	 * @return string The ISO 8601 timestamp.
	 */
	private function now(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
	}//end now()
}//end class
