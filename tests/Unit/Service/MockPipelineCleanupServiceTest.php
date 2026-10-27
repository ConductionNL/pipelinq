<?php

/**
 * Unit tests for MockPipelineCleanupService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\MockPipelineCleanupService;
use OCA\Pipelinq\Service\PipelineStageData;
use OCA\Pipelinq\Service\SystemServiceAccount;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for MockPipelineCleanupService.
 */
class MockPipelineCleanupServiceTest extends TestCase {

	/**
	 * The ids the deletes were called with.
	 *
	 * @var list<string>
	 */
	private array $deleted = [];

	/**
	 * The findAll calls that counted objects on a pipeline, with their flags.
	 *
	 * @var list<array{filters: array<string, string>, rbac: bool, multitenancy: bool}>
	 */
	private array $usageReads = [];

	/**
	 * The shipped defaults, the demo seed's and the mock register's pipelines
	 * go when nothing is on them; the set's three pipelines stay.
	 *
	 * @return void
	 */
	public function testRemovesUnusedMocksAndKeepsTheSetsPipelines(): void {
		$stageData = new PipelineStageData();
		$sales     = $stageData->getSalesPipelineData();
		$service   = $stageData->getServiceRequestsPipelineData();

		$report = $this->service(pipelines: [
			['id' => 'p-sales', 'title' => $sales['title'], 'description' => $sales['description'], 'isDefault' => true],
			['id' => 'p-service', 'title' => $service['title'], 'description' => $service['description']],
			['id' => 'p-demo', 'title' => '[Demo] Sales pipeline', 'description' => 'Demo pipeline: standard commercial stages.'],
			['id' => 'p-voorbeeld', '@self' => ['slug' => 'pipeline-voorbeeld-title-2-2'], 'title' => 'Voorbeeld Title 2'],
			['id' => 'p-verkoop', 'title' => 'Verkoop', 'description' => 'De verkoop van diensten en producten aan bedrijven en instellingen in Zuiddrecht.'],
			['id' => 'p-vragen', 'title' => 'Vragen en meldingen', 'description' => 'Verzoeken en meldingen van inwoners.', 'isDefault' => true],
			['id' => 'p-evenementen', 'title' => 'Evenementen en vergunningen', 'description' => 'Aanvragen voor een evenementenvergunning.'],
		])->run();

		$this->assertSame(expected: ['p-sales', 'p-service', 'p-demo', 'p-voorbeeld'], actual: array_column($report['removed'], 'id'));
		$this->assertSame(expected: ['p-sales', 'p-service', 'p-demo', 'p-voorbeeld'], actual: $this->deleted);
		$this->assertSame(expected: [], actual: $report['kept']);
		$this->assertSame(expected: [['id' => 'p-vragen', 'title' => 'Vragen en meldingen']], actual: $report['defaults']);
	}//end testRemovesUnusedMocksAndKeepsTheSetsPipelines()

	/**
	 * A mock with a lead on it stays, is reported with what is on it, and stays
	 * among the defaults it was marked as.
	 *
	 * @return void
	 */
	public function testKeepsAMockThatHasObjectsOnIt(): void {
		$sales = (new PipelineStageData())->getSalesPipelineData();

		$report = $this->service(
			pipelines: [
				['id' => 'p-sales', 'title' => $sales['title'], 'description' => $sales['description'], 'isDefault' => true],
				['id' => 'p-vragen', 'title' => 'Vragen en meldingen', 'isDefault' => true],
			],
			usedBy: ['p-sales' => ['lead-schema']]
		)->run();

		$this->assertSame(expected: [], actual: $report['removed']);
		$this->assertSame(expected: [], actual: $this->deleted);
		$this->assertSame(expected: [['id' => 'p-sales', 'title' => 'Sales Pipeline', 'usedBy' => ['lead']]], actual: $report['kept']);
		$this->assertSame(expected: ['p-sales', 'p-vragen'], actual: array_column($report['defaults'], 'id'));
	}//end testKeepsAMockThatHasObjectsOnIt()

	/**
	 * The usage count reads past RBAC and organisation scoping, for leads and
	 * tickets both, so a hidden object still keeps its pipeline.
	 *
	 * @return void
	 */
	public function testTheUsageCountReadsLeadsAndTicketsUnscoped(): void {
		$demo = ['id' => 'p-demo', 'title' => '[Demo] Service pipeline', 'description' => 'Demo pipeline: service-desk handling stages.'];
		$this->service(pipelines: [$demo])->run();

		$this->assertSame(
			expected: [
				['filters' => ['register' => 'reg', 'schema' => 'lead-schema', 'pipeline' => 'p-demo'], 'rbac' => false, 'multitenancy' => false],
				['filters' => ['register' => 'reg', 'schema' => 'ticket-schema', 'pipeline' => 'p-demo'], 'rbac' => false, 'multitenancy' => false],
			],
			actual: $this->usageReads
		);
	}//end testTheUsageCountReadsLeadsAndTicketsUnscoped()

	/**
	 * A dry run reports what would go and deletes nothing.
	 *
	 * @return void
	 */
	public function testADryRunDeletesNothing(): void {
		$report = $this->service(pipelines: [['id' => 'p-voorbeeld', 'slug' => 'pipeline-voorbeeld-title-1-1']])->run(dryRun: true);

		$this->assertSame(expected: ['p-voorbeeld'], actual: array_column($report['removed'], 'id'));
		$this->assertSame(expected: [], actual: $this->deleted);
	}//end testADryRunDeletesNothing()

	/**
	 * A delete OpenRegister refuses is reported, not counted as removed.
	 *
	 * @return void
	 */
	public function testARefusedDeleteIsReported(): void {
		$report = $this->service(
			pipelines: [['id' => 'p-voorbeeld', 'slug' => 'pipeline-voorbeeld-title-3-3', 'title' => 'Voorbeeld Title 3']],
			deleteResult: false
		)->run();

		$this->assertSame(expected: [], actual: $report['removed']);
		$this->assertSame(expected: [['id' => 'p-voorbeeld', 'title' => 'Voorbeeld Title 3']], actual: $report['failed']);
	}//end testARefusedDeleteIsReported()

	/**
	 * Only the exact known title and description mark a mock: a renamed or
	 * re-described copy is somebody's own pipeline.
	 *
	 * @return void
	 */
	public function testOnlyTheExactKnownPipelinesAreMocks(): void {
		$service = $this->service(pipelines: []);
		$sales   = (new PipelineStageData())->getSalesPipelineData();

		$this->assertTrue(condition: $service->isMock(['title' => $sales['title'], 'description' => $sales['description']]));
		$this->assertTrue(condition: $service->isMock(['@self' => ['slug' => 'pipeline-voorbeeld-title-1-1'], 'title' => 'Renamed']));
		$this->assertFalse(condition: $service->isMock(['title' => $sales['title'], 'description' => 'Onze eigen verkoop.']));
		$this->assertFalse(condition: $service->isMock(['title' => 'Sales Pipeline 2', 'description' => $sales['description']]));
		$this->assertFalse(condition: $service->isMock(['title' => 'Verkoop', 'slug' => 'pipeline-verkoop']));
	}//end testOnlyTheExactKnownPipelinesAreMocks()

	/**
	 * Without a configured ticket schema the run refuses rather than deciding
	 * a pipeline is unused on half the evidence.
	 *
	 * @return void
	 */
	public function testAMissingSchemaRefusesTheRun(): void {
		$this->expectException(exception: \RuntimeException::class);
		$this->expectExceptionMessage(message: 'ticket_schema');

		$this->service(pipelines: [], config: ['ticket_schema' => ''])->run();
	}//end testAMissingSchemaRefusesTheRun()

	/**
	 * Build the service over an object service that holds the given pipelines.
	 *
	 * @param list<array<string, mixed>>  $pipelines    The pipelines in the register.
	 * @param array<string, list<string>> $usedBy       Per pipeline id, the schemas that have an object on it.
	 * @param bool                        $deleteResult What deleteObject answers.
	 * @param array<string, string>       $config       App config overrides.
	 *
	 * @return MockPipelineCleanupService The service.
	 */
	private function service(array $pipelines, array $usedBy=[], bool $deleteResult=true, array $config=[]): MockPipelineCleanupService {
		$values = array_merge(
			['register' => 'reg', 'pipeline_schema' => 'pipeline-schema', 'lead_schema' => 'lead-schema', 'ticket_schema' => 'ticket-schema'],
			$config
		);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default) => ($values[$key] ?? $default)
		);

		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac=true, bool $_multitenancy=true) use ($pipelines, $usedBy): array {
				$filters = $config['filters'];
				if ($filters['schema'] === 'pipeline-schema') {
					return $pipelines;
				}

				$this->usageReads[] = ['filters' => $filters, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				if (in_array($filters['schema'], ($usedBy[$filters['pipeline']] ?? []), true) === true) {
					return [['id' => 'on-'.$filters['pipeline']]];
				}

				return [];
			}
		);
		$objectService->method('deleteObject')->willReturnCallback(
			function (string $uuid) use ($deleteResult): bool {
				$this->deleted[] = $uuid;
				return $deleteResult;
			}
		);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(originalClassName: IUser::class));
		$users = $this->createMock(originalClassName: IUserManager::class);

		return new MockPipelineCleanupService(
			appConfig: $appConfig,
			stageData: new PipelineStageData(),
			objectService: $objectService,
			systemAccount: new SystemServiceAccount(
				userManager: $users,
				userSession: $session,
				random: $this->createMock(originalClassName: ISecureRandom::class)
			),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end service()
}//end class
