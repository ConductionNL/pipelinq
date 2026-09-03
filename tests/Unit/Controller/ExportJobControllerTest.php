<?php

/**
 * Unit tests for ExportJobController.
 *
 * Two tiers. The first pins the wire contract of all thirteen BI-export
 * endpoints against mocked services: the shared access gate (401
 * unauthenticated, 403 for a caller outside the export role — ADR-005), the
 * response envelopes, and the mapping of the services' OCS exceptions onto
 * 404 / 422 / 500 without leaking exception text.
 *
 * The second tier wires the REAL ExportJobService and ExportDestinationService
 * onto a mocked OpenRegister ObjectService, because the shapes that actually
 * lose data live below the controller: a partial update that reads-modifies-
 * writes an incomplete record silently nulls every field the client did not
 * resend (saveObject is PUT-semantic), and a destination that stores what the
 * client posted would put warehouse credentials in a list every export analyst
 * can read.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Adapter\ExportSinkRegistry;
use OCA\Pipelinq\Controller\ExportJobController;
use OCA\Pipelinq\Service\Export\CronExpressionHelper;
use OCA\Pipelinq\Service\Export\ExportAccessPolicy;
use OCA\Pipelinq\Service\Export\ExportDataService;
use OCA\Pipelinq\Service\Export\ExportDestinationService;
use OCA\Pipelinq\Service\Export\ExportJobService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ExportJobController.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One or more contract tests per
 *  endpoint across a thirteen-endpoint controller.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Mirrors the controller's own
 *  collaborator set plus the real services used by the wired tier.
 */
class ExportJobControllerTest extends TestCase {
	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * Mock job service.
	 *
	 * @var ExportJobService&MockObject
	 */
	private ExportJobService $jobs;

	/**
	 * Mock destination service.
	 *
	 * @var ExportDestinationService&MockObject
	 */
	private ExportDestinationService $destinations;

	/**
	 * Mock access policy.
	 *
	 * @var ExportAccessPolicy&MockObject
	 */
	private ExportAccessPolicy $policy;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The controller under test.
	 *
	 * @var ExportJobController
	 */
	private ExportJobController $controller;

	/**
	 * A complete stored job, as OpenRegister would return it.
	 *
	 * @var array<string, mixed>
	 */
	private const STORED_JOB = [
		'id' => 'job-1',
		'name' => 'Nightly warehouse load',
		'destinationId' => 'dest-1',
		'sourceSchemas' => ['client', 'deal'],
		'format' => 'csv',
		'mode' => 'incremental',
		'incrementalWatermarkColumn' => 'updated_at',
		'scheduleCron' => '0 2 * * *',
		'columnAllowlist' => ['id', 'name'],
		'partitionBy' => 'as_of_date',
		'rowFilterExpression' => 'status != "archived"',
		'enabled' => true,
		'createdBy' => 'analyst',
		'createdAt' => '2026-01-01T00:00:00+00:00',
	];

	/**
	 * A complete stored destination, as OpenRegister would return it.
	 *
	 * @var array<string, mixed>
	 */
	private const STORED_DESTINATION = [
		'id' => 'dest-1',
		'name' => 'Warehouse bucket',
		'type' => 's3',
		'connectorSourceId' => 'oc-source-7',
		'pathTemplate' => 'exports/{schema}/{date}',
		'compression' => 'gzip',
		'encryptionEnabled' => true,
		'validationStatus' => 'valid',
		'lastValidatedAt' => '2026-01-01T00:00:00+00:00',
	];

	/**
	 * Build the contract-tier controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->jobs = $this->createMock(ExportJobService::class);
		$this->destinations = $this->createMock(ExportDestinationService::class);
		$this->policy = $this->createMock(ExportAccessPolicy::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new ExportJobController($this->request,
			$this->jobs,
			$this->destinations,
			$this->policy,
			$this->userSession,
			$this->translator(),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A pass-through localization mock.
	 *
	 * @return IL10N&MockObject The translator.
	 */
	private function translator(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return $l10n;
	}//end translator()

	/**
	 * Stub the acting user (or none) and the export-role decision.
	 *
	 * @param string|null $uid The acting UID, or null for no session.
	 * @param bool $isAdmin Whether the user holds the export role.
	 *
	 * @return void
	 */
	private function authenticate(?string $uid, bool $isAdmin = true): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);
			return;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->policy->method('isExportAdmin')->willReturn($isAdmin);
	}//end authenticate()

	/**
	 * Wrap a fixture row as the entity ObjectService now returns.
	 *
	 * Since ADR-084 `find()` returns `?ObjectEntityInterface` and
	 * `saveObject()` returns a non-nullable one, so a mock configured with a
	 * bare array is rejected by PHPUnit before the test body runs. The export
	 * services read the payload back with `jsonSerialize()`, which this
	 * entity answers with the row it was given.
	 *
	 * @param array<string, mixed> $row The fixture row.
	 *
	 * @return ObjectEntityInterface The wrapped row.
	 */
	private static function entity(array $row): ObjectEntityInterface {
		$entity = new ObjectEntity();
		$entity->setUuid((string)($row['id'] ?? ''));
		$entity->setObject($row);

		return $entity;
	}//end entity()

	/**
	 * Build a controller backed by the REAL export services and a mocked
	 * OpenRegister ObjectService.
	 *
	 * @param ObjectServiceInterface&MockObject $objects The mocked object service.
	 * @param array<string, mixed> $body The request body parameters.
	 *
	 * @return ExportJobController The wired controller.
	 */
	private function wiredController(ObjectServiceInterface $objects, array $body = []): ExportJobController {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objects): object {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objects;
				}

				throw new \RuntimeException('Not registered: ' . $id);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				$values = [
					'register' => 'pipelinq',
					'exportJob_schema' => 'exportJob',
					'exportDestination_schema' => 'exportDestination',
				];
				return ($values[$key] ?? $default);
			}
		);

		// A sink registry with no adapters: testConnection() then records an
		// "invalid" status without opening a network connection.
		$destinationService = new ExportDestinationService(
			container: $container,
			appConfig: $appConfig,
			objectService: $objects,
			sinks: new ExportSinkRegistry([]),
			logger: $this->createMock(LoggerInterface::class),
		);

		$data = $this->createMock(ExportDataService::class);
		$data->method('schemaExists')->willReturn(true);

		$jobService = new ExportJobService(
			container: $container,
			appConfig: $appConfig,
			objectService: $objects,
			destinations: $destinationService,
			data: $data,
			cron: new CronExpressionHelper(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($body);

		$policy = $this->createMock(ExportAccessPolicy::class);
		$policy->method('isExportAdmin')->willReturn(true);

		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('analyst');
		$session->method('getUser')->willReturn($user);

		return new ExportJobController($request,
			$jobService,
			$destinationService,
			$policy,
			$session,
			$this->translator(),
			$this->createMock(LoggerInterface::class)
		);
	}//end wiredController()

	/**
	 * The shared gate refuses an unauthenticated caller before any service is
	 * touched.
	 *
	 * @return void
	 */
	public function testListJobsRequiresAuthentication(): void {
		$this->authenticate(null);
		$this->jobs->expects($this->never())->method('listJobs');

		$response = $this->controller->listJobs();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}//end testListJobsRequiresAuthentication()

	/**
	 * The shared gate refuses a caller without the export role.
	 *
	 * @return void
	 */
	public function testListJobsForbiddenWithoutExportRole(): void {
		$this->authenticate('regular-user', isAdmin: false);
		$this->jobs->expects($this->never())->method('listJobs');

		$response = $this->controller->listJobs();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Insufficient permissions'], $response->getData());
	}//end testListJobsForbiddenWithoutExportRole()

	/**
	 * The job list is returned under a `jobs` key.
	 *
	 * @return void
	 */
	public function testListJobsReturnsJobs(): void {
		$this->authenticate('analyst');
		$this->jobs->method('listJobs')->willReturn([self::STORED_JOB]);

		$response = $this->controller->listJobs();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['jobs'], array_keys($data));
		$this->assertCount(1, $data['jobs']);
		$this->assertSame('Nightly warehouse load', $data['jobs'][0]['name']);
	}//end testListJobsReturnsJobs()

	/**
	 * A job detail is returned under a `job` key.
	 *
	 * @return void
	 */
	public function testShowJobReturnsJob(): void {
		$this->authenticate('analyst');
		$this->jobs->method('getJob')->with(id: 'job-1')->willReturn(self::STORED_JOB);

		$response = $this->controller->showJob('job-1');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['job'], array_keys($data));
		$this->assertSame('job-1', $data['job']['id']);
		$this->assertSame('dest-1', $data['job']['destinationId']);
		$this->assertSame(['client', 'deal'], $data['job']['sourceSchemas']);
	}//end testShowJobReturnsJob()

	/**
	 * An unknown job id is a 404 carrying the service's message, never a 500.
	 *
	 * @return void
	 */
	public function testShowJobNotFound(): void {
		$this->authenticate('analyst');
		$this->jobs->method('getJob')->willThrowException(new OCSNotFoundException('Export job not found.'));

		$response = $this->controller->showJob('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Export job not found.'], $response->getData());
	}//end testShowJobNotFound()

	/**
	 * A created job is attributed to the session user — never to an identity
	 * taken from the request body — and comes back disabled.
	 *
	 * @return void
	 */
	public function testCreateJobAttributesToSessionUser(): void {
		$this->authenticate('analyst');
		$this->request->method('getParams')->willReturn(
			['name' => 'New job', 'createdBy' => 'someone-else', 'id' => 'forged', '_route' => 'pipelinq.exportJob.createJob']
		);
		$this->jobs->expects($this->once())
			->method('createJob')
			->with(
				data: ['name' => 'New job', 'createdBy' => 'someone-else'],
				userId: 'analyst'
			)
			->willReturn(['id' => 'job-2', 'name' => 'New job', 'enabled' => false, 'createdBy' => 'analyst']);

		$response = $this->controller->createJob();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('job-2', $data['job']['id']);
		$this->assertFalse($data['job']['enabled'], 'A new job must start disabled.');
		$this->assertSame('analyst', $data['job']['createdBy']);
	}//end testCreateJobAttributesToSessionUser()

	/**
	 * A validation failure becomes 422 with the field-level message.
	 *
	 * @return void
	 */
	public function testCreateJobRejectsInvalidConfiguration(): void {
		$this->authenticate('analyst');
		$this->request->method('getParams')->willReturn(['name' => 'New job', 'format' => 'xlsx']);
		$this->jobs->method('createJob')->willThrowException(new OCSBadRequestException("Unsupported format 'xlsx'."));

		$response = $this->controller->createJob();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => "Unsupported format 'xlsx'."], $response->getData());
	}//end testCreateJobRejectsInvalidConfiguration()

	/**
	 * An update returns the updated job, and the route id is not forwarded as
	 * a body field.
	 *
	 * @return void
	 */
	public function testUpdateJobReturnsUpdatedJob(): void {
		$this->authenticate('analyst');
		$this->request->method('getParams')->willReturn(['id' => 'job-1', 'name' => 'Renamed', '_route' => 'x']);
		$this->jobs->expects($this->once())
			->method('updateJob')
			->with(id: 'job-1', data: ['name' => 'Renamed'])
			->willReturn(['id' => 'job-1', 'name' => 'Renamed', 'enabled' => false]);

		$response = $this->controller->updateJob('job-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Renamed', $response->getData()['job']['name']);
		$this->assertFalse($response->getData()['job']['enabled'], 'An edited job must be re-tested before it runs again.');
	}//end testUpdateJobReturnsUpdatedJob()

	/**
	 * Updating an unknown job is a 404.
	 *
	 * @return void
	 */
	public function testUpdateJobNotFound(): void {
		$this->authenticate('analyst');
		$this->request->method('getParams')->willReturn(['name' => 'Renamed']);
		$this->jobs->method('updateJob')->willThrowException(new OCSNotFoundException('Export job not found.'));

		$response = $this->controller->updateJob('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testUpdateJobNotFound()

	/**
	 * Deleting a job acknowledges with `{deleted: true}`.
	 *
	 * @return void
	 */
	public function testDeleteJobReturnsDeleted(): void {
		$this->authenticate('analyst');
		$this->jobs->expects($this->once())->method('deleteJob')->with(id: 'job-1');

		$response = $this->controller->deleteJob('job-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['deleted' => true], $response->getData());
	}//end testDeleteJobReturnsDeleted()

	/**
	 * Deleting an unknown job is a 404 and never reports a successful delete.
	 *
	 * @return void
	 */
	public function testDeleteJobNotFound(): void {
		$this->authenticate('analyst');
		$this->jobs->method('deleteJob')->willThrowException(new OCSNotFoundException('Export job not found.'));

		$response = $this->controller->deleteJob('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertArrayNotHasKey('deleted', $response->getData());
	}//end testDeleteJobNotFound()

	/**
	 * A test run reports the validation outcome and the per-schema sample
	 * without uploading anything.
	 *
	 * @return void
	 */
	public function testTestRunReturnsSampleResult(): void {
		$this->authenticate('analyst');
		$this->jobs->method('getJob')->with(id: 'job-1')->willReturn(self::STORED_JOB);
		$this->jobs->expects($this->once())
			->method('testRun')
			->with(job: self::STORED_JOB)
			->willReturn(
				[
					'success' => true,
					'sample_rows' => 12,
					'errors' => [],
					'samples' => [['schema' => 'client', 'rows' => 12, 'format' => 'csv', 'dropped_columns' => [], 'preview' => 'id,name']],
				]
			);

		$response = $this->controller->testRun('job-1');
		$result = $response->getData()['result'];

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($result['success']);
		$this->assertSame(12, $result['sample_rows']);
		$this->assertSame([], $result['errors']);
		$this->assertSame('client', $result['samples'][0]['schema']);
	}//end testTestRunReturnsSampleResult()

	/**
	 * A test run only ever names a job by its route id — no client-supplied
	 * host, endpoint or URL is read from the request on this path.
	 *
	 * @return void
	 */
	public function testTestRunTakesNoClientSuppliedTarget(): void {
		$this->authenticate('analyst');
		$this->request->expects($this->never())->method('getParam');
		$this->request->expects($this->never())->method('getParams');
		$this->jobs->method('getJob')->with(id: 'job-1')->willReturn(self::STORED_JOB);
		$this->jobs->method('testRun')->willReturn(
			['success' => false, 'sample_rows' => 0, 'errors' => ['No source schemas selected.'], 'samples' => []]
		);

		$response = $this->controller->testRun('job-1');
		$result = $response->getData()['result'];

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($result['success']);
		$this->assertSame(['No source schemas selected.'], $result['errors']);
	}//end testTestRunTakesNoClientSuppliedTarget()

	/**
	 * A test run against an unknown job is a 404.
	 *
	 * @return void
	 */
	public function testTestRunNotFound(): void {
		$this->authenticate('analyst');
		$this->jobs->method('getJob')->willThrowException(new OCSNotFoundException('Export job not found.'));
		$this->jobs->expects($this->never())->method('testRun');

		$response = $this->controller->testRun('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testTestRunNotFound()

	/**
	 * Enabling a job returns the enabled record.
	 *
	 * @return void
	 */
	public function testEnableJobReturnsEnabledJob(): void {
		$this->authenticate('analyst');
		$this->jobs->expects($this->once())
			->method('enableJob')
			->with(id: 'job-1')
			->willReturn(['id' => 'job-1', 'enabled' => true]);

		$response = $this->controller->enableJob('job-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['job']['enabled']);
	}//end testEnableJobReturnsEnabledJob()

	/**
	 * A job whose destination has not passed a connection test cannot be
	 * enabled: 422 with the reason, and no enabled record in the body.
	 *
	 * @return void
	 */
	public function testEnableJobRejectedForUnvalidatedDestination(): void {
		$this->authenticate('analyst');
		$this->jobs->method('enableJob')
			->willThrowException(new OCSBadRequestException('Destination is invalid. Test connection first.'));

		$response = $this->controller->enableJob('job-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'Destination is invalid. Test connection first.'], $response->getData());
		$this->assertArrayNotHasKey('job', $response->getData());
	}//end testEnableJobRejectedForUnvalidatedDestination()

	/**
	 * Disabling a job returns the disabled record.
	 *
	 * @return void
	 */
	public function testDisableJobReturnsDisabledJob(): void {
		$this->authenticate('analyst');
		$this->jobs->expects($this->once())
			->method('disableJob')
			->with(id: 'job-1')
			->willReturn(['id' => 'job-1', 'enabled' => false]);

		$response = $this->controller->disableJob('job-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($response->getData()['job']['enabled']);
	}//end testDisableJobReturnsDisabledJob()

	/**
	 * Disabling is refused with 403 when the service denies it, and the
	 * service's own message is preserved.
	 *
	 * @return void
	 */
	public function testDisableJobForbiddenByService(): void {
		$this->authenticate('analyst');
		$this->jobs->method('disableJob')->willThrowException(new OCSForbiddenException('Job is owned by another tenant.'));

		$response = $this->controller->disableJob('job-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Job is owned by another tenant.'], $response->getData());
	}//end testDisableJobForbiddenByService()

	/**
	 * An unexpected failure is answered 500 with a generic message; neither
	 * the exception text nor any infrastructure detail reaches the client.
	 *
	 * @return void
	 */
	public function testUnexpectedFailureIsMasked(): void {
		$this->authenticate('analyst');
		$this->jobs->method('listJobs')
			->willThrowException(new \RuntimeException('SQLSTATE[08006] could not connect to warehouse.internal:5432'));

		$response = $this->controller->listJobs();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'An unexpected error occurred'], $response->getData());
		$this->assertStringNotContainsString('warehouse.internal', json_encode($response->getData()));
	}//end testUnexpectedFailureIsMasked()

	/**
	 * An unauthenticated caller cannot list destinations.
	 *
	 * @return void
	 */
	public function testListDestinationsRequiresAuthentication(): void {
		$this->authenticate(null);
		$this->destinations->expects($this->never())->method('listDestinations');

		$response = $this->controller->listDestinations();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testListDestinationsRequiresAuthentication()

	/**
	 * The destination list is returned under a `destinations` key and carries
	 * only configuration — never the warehouse credentials, which live in the
	 * referenced OpenConnector source.
	 *
	 * @return void
	 */
	public function testListDestinationsExposesNoCredentials(): void {
		$this->authenticate('analyst');
		$this->destinations->method('listDestinations')->willReturn([self::STORED_DESTINATION]);

		$response = $this->controller->listDestinations();
		$data = $response->getData();
		$body = (string)json_encode($data);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['destinations'], array_keys($data));
		$this->assertSame('oc-source-7', $data['destinations'][0]['connectorSourceId']);
		foreach (['password', 'secret', 'accessKey', 'secretKey', 'credentials', 'privateKey'] as $key) {
			$this->assertArrayNotHasKey($key, $data['destinations'][0]);
		}

		$this->assertStringNotContainsString('BEGIN PRIVATE KEY', $body);
	}//end testListDestinationsExposesNoCredentials()

	/**
	 * Creating a destination returns the stored record.
	 *
	 * @return void
	 */
	public function testCreateDestinationReturnsCreatedDestination(): void {
		$this->authenticate('analyst');
		$this->request->method('getParams')->willReturn(['name' => 'Warehouse bucket', 'type' => 's3']);
		$this->destinations->expects($this->once())
			->method('createDestination')
			->with(data: ['name' => 'Warehouse bucket', 'type' => 's3'])
			->willReturn(self::STORED_DESTINATION);

		$response = $this->controller->createDestination();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['destination'], array_keys($data));
		$this->assertSame('s3', $data['destination']['type']);
		$this->assertSame('valid', $data['destination']['validationStatus']);
	}//end testCreateDestinationReturnsCreatedDestination()

	/**
	 * An unsupported destination type is refused with 422.
	 *
	 * @return void
	 */
	public function testCreateDestinationRejectsUnsupportedType(): void {
		$this->authenticate('analyst');
		$this->request->method('getParams')->willReturn(['name' => 'x', 'type' => 'ftp']);
		$this->destinations->method('createDestination')
			->willThrowException(new OCSBadRequestException("Unsupported destination type 'ftp'."));

		$response = $this->controller->createDestination();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => "Unsupported destination type 'ftp'."], $response->getData());
	}//end testCreateDestinationRejectsUnsupportedType()

	/**
	 * Updating a destination returns the re-probed record.
	 *
	 * @return void
	 */
	public function testUpdateDestinationReturnsUpdatedDestination(): void {
		$this->authenticate('analyst');
		$this->request->method('getParams')->willReturn(['id' => 'dest-1', 'compression' => 'zstd']);
		$this->destinations->expects($this->once())
			->method('updateDestination')
			->with(id: 'dest-1', data: ['compression' => 'zstd'])
			->willReturn(array_merge(self::STORED_DESTINATION, ['compression' => 'zstd']));

		$response = $this->controller->updateDestination('dest-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('zstd', $response->getData()['destination']['compression']);
	}//end testUpdateDestinationReturnsUpdatedDestination()

	/**
	 * Updating an unknown destination is a 404.
	 *
	 * @return void
	 */
	public function testUpdateDestinationNotFound(): void {
		$this->authenticate('analyst');
		$this->request->method('getParams')->willReturn(['compression' => 'zstd']);
		$this->destinations->method('updateDestination')
			->willThrowException(new OCSNotFoundException('Export destination not found.'));

		$response = $this->controller->updateDestination('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Export destination not found.'], $response->getData());
	}//end testUpdateDestinationNotFound()

	/**
	 * Deleting a destination acknowledges with `{deleted: true}`.
	 *
	 * @return void
	 */
	public function testDeleteDestinationReturnsDeleted(): void {
		$this->authenticate('analyst');
		$this->destinations->expects($this->once())->method('deleteDestination')->with(id: 'dest-1');

		$response = $this->controller->deleteDestination('dest-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['deleted' => true], $response->getData());
	}//end testDeleteDestinationReturnsDeleted()

	/**
	 * Deleting an unknown destination is a 404, never a false acknowledgement.
	 *
	 * @return void
	 */
	public function testDeleteDestinationNotFound(): void {
		$this->authenticate('analyst');
		$this->destinations->method('deleteDestination')
			->willThrowException(new OCSNotFoundException('Export destination not found.'));

		$response = $this->controller->deleteDestination('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertArrayNotHasKey('deleted', $response->getData());
	}//end testDeleteDestinationNotFound()

	/**
	 * The connection test reports a boolean verdict under `valid`, and reads
	 * no host, endpoint or URL from the request — the target is resolved from
	 * the stored destination and its OpenConnector source only.
	 *
	 * @return void
	 */
	public function testTestDestinationReportsVerdictAndIgnoresRequestBody(): void {
		$this->authenticate('analyst');
		$this->request->expects($this->never())->method('getParam');
		$this->request->expects($this->never())->method('getParams');
		$this->destinations->expects($this->once())
			->method('testConnection')
			->with(id: 'dest-1')
			->willReturn(true);

		$response = $this->controller->testDestination('dest-1');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['valid'], array_keys($data));
		$this->assertTrue($data['valid']);
	}//end testTestDestinationReportsVerdictAndIgnoresRequestBody()

	/**
	 * A failing probe is a successful request reporting `valid: false` — the
	 * endpoint answers the question, it does not error.
	 *
	 * @return void
	 */
	public function testTestDestinationReportsUnreachableAsFalse(): void {
		$this->authenticate('analyst');
		$this->destinations->method('testConnection')->willReturn(false);

		$response = $this->controller->testDestination('dest-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['valid' => false], $response->getData());
	}//end testTestDestinationReportsUnreachableAsFalse()

	/**
	 * Probing an unknown destination is a 404.
	 *
	 * @return void
	 */
	public function testTestDestinationNotFound(): void {
		$this->authenticate('analyst');
		$this->destinations->method('testConnection')
			->willThrowException(new OCSNotFoundException('Export destination not found.'));

		$response = $this->controller->testDestination('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testTestDestinationNotFound()

	/**
	 * Wired against the real service: renaming a job must not erase the rest
	 * of its configuration. saveObject is PUT-semantic, so any field missing
	 * from the persisted payload is nulled in the store.
	 *
	 * @return void
	 */
	public function testUpdateJobPreservesFieldsTheClientDidNotResend(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn(self::entity(self::STORED_JOB));

		$persisted = null;
		$objects->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				static function (array $object, ...$rest) use (&$persisted): ObjectEntityInterface {
					$persisted = $object;
					return self::entity($object);
				}
			);

		$controller = $this->wiredController($objects, ['id' => 'job-1', 'name' => 'Renamed nightly load']);
		$response = $controller->updateJob('job-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertIsArray($persisted);
		$this->assertSame('Renamed nightly load', $persisted['name']);

		foreach (['destinationId', 'sourceSchemas', 'format', 'mode', 'incrementalWatermarkColumn', 'columnAllowlist', 'partitionBy', 'rowFilterExpression', 'createdBy', 'createdAt'] as $field) {
			$this->assertArrayHasKey($field, $persisted, $field . ' was dropped by a partial update.');
			$this->assertSame(self::STORED_JOB[$field], $persisted[$field], $field . ' was changed by a partial update.');
		}

		// An edited job is forced back to disabled until it is re-tested.
		$this->assertFalse($persisted['enabled']);
		$this->assertFalse($response->getData()['job']['enabled']);
	}//end testUpdateJobPreservesFieldsTheClientDidNotResend()

	/**
	 * Wired against the real service: changing one destination field must not
	 * erase the others.
	 *
	 * @return void
	 */
	public function testUpdateDestinationPreservesFieldsTheClientDidNotResend(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn(self::entity(self::STORED_DESTINATION));

		$writes = [];
		$objects->method('saveObject')->willReturnCallback(
			static function (array $object, ...$rest) use (&$writes): ObjectEntityInterface {
				$writes[] = $object;
				return self::entity($object);
			}
		);

		$controller = $this->wiredController($objects, ['id' => 'dest-1', 'compression' => 'zstd']);
		$response = $controller->updateDestination('dest-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertNotSame([], $writes);
		$persisted = $writes[0];

		$this->assertSame('zstd', $persisted['compression']);
		foreach (['name', 'type', 'connectorSourceId', 'pathTemplate', 'encryptionEnabled'] as $field) {
			$this->assertArrayHasKey($field, $persisted, $field . ' was dropped by a partial update.');
			$this->assertSame(self::STORED_DESTINATION[$field], $persisted[$field], $field . ' was changed by a partial update.');
		}
	}//end testUpdateDestinationPreservesFieldsTheClientDidNotResend()

	/**
	 * Wired against the real service: a client cannot smuggle a warehouse
	 * credential onto the destination object, where every export analyst
	 * could read it back out of the list endpoint.
	 *
	 * @return void
	 */
	public function testCreateDestinationDoesNotPersistClientSuppliedCredentials(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn(self::entity(self::STORED_DESTINATION));

		$writes = [];
		$objects->method('saveObject')->willReturnCallback(
			static function (array $object, ...$rest) use (&$writes): ObjectEntityInterface {
				$writes[] = $object;
				return self::entity(array_merge($object, ['id' => 'dest-1']));
			}
		);

		$body = array_merge(
			self::STORED_DESTINATION,
			[
				'password' => 'hunter2',
				'secret' => 's3cr3t',
				'accessKey' => 'AKIAEXAMPLE',
				'secretKey' => 'wJalrEXAMPLEKEY',
				'credentials' => ['user' => 'root'],
				'privateKey' => '-----BEGIN PRIVATE KEY-----',
			]
		);

		$controller = $this->wiredController($objects, $body);
		$response = $controller->createDestination();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertNotSame([], $writes);

		foreach ($writes as $write) {
			foreach (['password', 'secret', 'accessKey', 'secretKey', 'credentials', 'privateKey'] as $key) {
				$this->assertArrayNotHasKey($key, $write, $key . ' reached the destination object.');
			}
		}

		$this->assertStringNotContainsString('hunter2', (string)json_encode($response->getData()));
	}//end testCreateDestinationDoesNotPersistClientSuppliedCredentials()

	/**
	 * The destination payload filter is documented as dropping "any
	 * client-supplied secret-bearing keys". The commonest credential field
	 * names for the supported warehouse types are token-shaped, and they are
	 * not covered.
	 *
	 * @return void
	 */
	public function testCreateDestinationDropsTokenShapedCredentials(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn(self::entity(self::STORED_DESTINATION));

		$writes = [];
		$objects->method('saveObject')->willReturnCallback(
			static function (array $object, ...$rest) use (&$writes): ObjectEntityInterface {
				$writes[] = $object;
				return self::entity(array_merge($object, ['id' => 'dest-1']));
			}
		);

		$body = array_merge(
			self::STORED_DESTINATION,
			[
				'token' => 'ghp_example',
				'apiKey' => 'key-example',
				'connectionString' => 'AccountKey=abc',
				'sasToken' => 'sv=2020',
				'clientSecret' => 'cs-example',
			]
		);

		$this->wiredController($objects, $body)->createDestination();

		foreach ($writes as $write) {
			foreach (['token', 'apiKey', 'connectionString', 'sasToken', 'clientSecret'] as $key) {
				$this->assertArrayNotHasKey($key, $write, $key . ' reached the destination object.');
			}
		}
	}//end testCreateDestinationDropsTokenShapedCredentials()

	/**
	 * Wired against the real service: a deleted job is gone from the list.
	 * deleteObject is a soft delete, so a list query that does not exclude
	 * tombstoned rows would keep serving it.
	 *
	 * @return void
	 */
	public function testDeleteJobRemovesItFromTheList(): void {
		$live = [self::STORED_JOB];

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn(self::entity(self::STORED_JOB));
		$objects->expects($this->once())
			->method('deleteObject')
			->willReturnCallback(
				static function (...$args) use (&$live): bool {
					// Model the store's own behaviour: a tombstoned row is
					// excluded from subsequent reads.
					$live = [];
					return true;
				}
			);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $rbac = true, bool $multitenancy = true) use (&$live): array {
				// The list must never opt back into tombstoned rows, which is
				// the only way a deleted job could resurface.
				$this->assertArrayNotHasKey('_includeDeleted', $config);
				$this->assertArrayNotHasKey('_includeDeleted', ($config['filters'] ?? []));
				$this->assertArrayNotHasKey('includeDeleted', $config);

				return $live;
			}
		);

		$controller = $this->wiredController($objects);

		$this->assertCount(1, $controller->listJobs()->getData()['jobs']);

		$deleteResponse = $controller->deleteJob('job-1');
		$this->assertSame(Http::STATUS_OK, $deleteResponse->getStatus());
		$this->assertSame(['deleted' => true], $deleteResponse->getData());

		$listResponse = $controller->listJobs();
		$this->assertSame(Http::STATUS_OK, $listResponse->getStatus());
		$this->assertSame([], $listResponse->getData()['jobs'], 'A deleted job must not reappear in the list.');
	}//end testDeleteJobRemovesItFromTheList()
}//end class
