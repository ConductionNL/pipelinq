<?php

/**
 * Unit tests for LeadService.
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

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCA\Pipelinq\Mcp\McpAnswer;
use OCA\Pipelinq\Mcp\McpTransition;
use OCA\Pipelinq\Service\LeadService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * Tests for LeadService.
 *
 * Behaviour cases are ported from the deleted `PipelinqToolProviderTest`
 * (pipelinq.createLead / pipelinq.pipelineForecast sections) — the only
 * change is that `createLead()` no longer aliases `winProbability`
 * (pipelinq #381, resolved by `plq-mcp-provider-surgery`).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class LeadServiceTest extends TestCase {

	/**
	 * Nil UUID used for every client fixture reference.
	 *
	 * @var string
	 */
	private const NIL_UUID = '00000000-0000-0000-0000-000000000000';

	/**
	 * The DI container mock.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * The app config mock (register / lead_schema resolution).
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the service under test.
	 *
	 * @return LeadService
	 */
	private function buildService(): LeadService {
		return new LeadService(
			container: $this->container,
			appConfig: $this->appConfig,
			mcp: new McpAnswer(logger: $this->logger),
		);
	}//end buildService()

	/**
	 * Stub IAppConfig::getValueString with a fixed key => value map; any key
	 * not in the map resolves to the caller's default (empty string).
	 *
	 * @param array<string, string> $values Map of app-config key => value.
	 *
	 * @return void
	 */
	private function stubAppConfig(array $values): void {
		$this->appConfig->method('getValueString')->willReturnCallback(
			static function (string $appId, string $key, string $default = '') use ($values): string {
				return $values[$key] ?? $default;
			}
		);
	}//end stubAppConfig()

	/**
	 * Build an ObjectService mock and wire it as the container's resolved service.
	 *
	 * The declared return type is the CONTRACT: the double is a mock of
	 * `ObjectServiceInterface`, so a helper still declaring OpenRegister's
	 * concrete `Service\ObjectService` raises a TypeError on return (ADR-084).
	 *
	 * @return ObjectServiceInterface&MockObject
	 */
	private function mockObjectService(): ObjectServiceInterface {
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->container->method('get')->willReturn($objectService);

		return $objectService;
	}//end mockObjectService()

	// =========================================================================
	// #[McpTool] attribute presence
	// =========================================================================

	/**
	 * createLead() carries #[McpTool] with the expected name and description.
	 *
	 * @return void
	 */
	public function testCreateLeadHasMcpToolAttribute(): void {
		$method = new ReflectionMethod(LeadService::class, 'createLead');
		$attributes = $method->getAttributes(McpTool::class);

		$this->assertCount(expectedCount: 1, haystack: $attributes);

		/** @var McpTool $instance */
		$instance = $attributes[0]->newInstance();
		$this->assertSame(expected: 'createLead', actual: $instance->name);
		$this->assertStringContainsString(needle: 'title', haystack: (string)$instance->description);
		$this->assertTrue(condition: $method->isPublic());

		// ADR-063 hints/scope: createLead writes a brand-new object (uuid
		// always null) — never destructive, never idempotent.
		$this->assertFalse(condition: $instance->readOnlyHint);
		$this->assertFalse(condition: $instance->destructiveHint);
		$this->assertFalse(condition: $instance->idempotentHint);
		$this->assertSame(expected: 'create', actual: $instance->scope);
	}//end testCreateLeadHasMcpToolAttribute()

	/**
	 * pipelineForecast() carries #[McpTool] with the expected name and description.
	 *
	 * @return void
	 */
	public function testPipelineForecastHasMcpToolAttribute(): void {
		$method = new ReflectionMethod(LeadService::class, 'pipelineForecast');
		$attributes = $method->getAttributes(McpTool::class);

		$this->assertCount(expectedCount: 1, haystack: $attributes);

		/** @var McpTool $instance */
		$instance = $attributes[0]->newInstance();
		$this->assertSame(expected: 'pipelineForecast', actual: $instance->name);
		$this->assertStringContainsString(needle: 'stage', haystack: (string)$instance->description);
		$this->assertTrue(condition: $method->isPublic());

		// ADR-063 hints/scope: pure read/aggregation over already-materialised data.
		$this->assertTrue(condition: $instance->readOnlyHint);
		$this->assertFalse(condition: $instance->destructiveHint);
		$this->assertTrue(condition: $instance->idempotentHint);
		$this->assertSame(expected: 'read', actual: $instance->scope);
	}//end testPipelineForecastHasMcpToolAttribute()

	// =========================================================================
	// pipelineForecast()
	// =========================================================================

	/**
	 * pipelineForecast() returns not_configured when the register / lead
	 * schema are unset.
	 *
	 * @return void
	 */
	public function testPipelineForecastWithoutConfigReturnsNotConfigured(): void {
		$this->stubAppConfig(values: []);

		$result = $this->buildService()->pipelineForecast();

		$this->assertSame(expected: 'not_configured', actual: $result['error']['code']);
	}//end testPipelineForecastWithoutConfigReturnsNotConfigured()

	/**
	 * pipelineForecast() reads only open leads, groups by stage (ordered by
	 * stageOrder), sums value and the already-materialised weightedValue
	 * (never recomputed), and returns a grand total.
	 *
	 * @return void
	 */
	public function testPipelineForecastGroupsByStageAndSumsWeightedValue(): void {
		$this->stubAppConfig(values: ['register' => 'reg-1', 'lead_schema' => 'sch-lead']);

		$captured = [];
		$objectService = $this->mockObjectService();
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use (&$captured): array {
				$captured = $config;
				return [
					['title' => 'Data-governance retainer', 'stage' => 'proposal', 'stageOrder' => 2, 'value' => 18000, 'weightedValue' => 9000],
					['title' => 'KCC self-service portal', 'stage' => 'qualification', 'stageOrder' => 1, 'value' => 45000, 'weightedValue' => 22500],
				];
			}
		);

		$result = $this->buildService()->pipelineForecast();

		$this->assertSame(expected: 'open', actual: $captured['filters']['status']);

		$this->assertSame(expected: 'qualification', actual: $result['stages'][0]['stage']);
		$this->assertSame(expected: 1, actual: $result['stages'][0]['leadCount']);
		$this->assertSame(expected: 45000.0, actual: $result['stages'][0]['value']);
		$this->assertSame(expected: 22500.0, actual: $result['stages'][0]['weightedValue']);

		$this->assertSame(expected: 'proposal', actual: $result['stages'][1]['stage']);
		$this->assertSame(expected: 18000.0, actual: $result['stages'][1]['value']);

		$this->assertSame(expected: 2, actual: $result['total']['leadCount']);
		$this->assertSame(expected: 63000.0, actual: $result['total']['value']);
		$this->assertSame(expected: 31500.0, actual: $result['total']['weightedValue']);
	}//end testPipelineForecastGroupsByStageAndSumsWeightedValue()

	// =========================================================================
	// createLead()
	// =========================================================================

	/**
	 * createLead() without a title returns invalid_arguments and writes nothing.
	 *
	 * @return void
	 */
	public function testCreateLeadWithoutTitleReturnsInvalidArguments(): void {
		$objectService = $this->mockObjectService();
		$objectService->expects($this->never())->method('saveObject');

		$result = $this->buildService()->createLead(title: '');

		$this->assertSame(expected: 'invalid_arguments', actual: $result['error']['code']);
	}//end testCreateLeadWithoutTitleReturnsInvalidArguments()

	/**
	 * createLead() with a title writes through ObjectService->saveObject on
	 * the configured lead schema and returns the created lead including its
	 * server-computed qualificationScore and declarative winProbability,
	 * unmodified by any alias (pipelinq #381).
	 *
	 * @return void
	 */
	public function testCreateLeadWritesLeadAndReturnsMaterialisedCalculations(): void {
		$this->stubAppConfig(values: ['register' => 'reg-1', 'lead_schema' => 'sch-lead']);

		$captured = [];
		$objectService = $this->mockObjectService();
		$objectService->method('saveObject')->willReturnCallback(
			static function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions()) use (&$captured): ObjectEntityInterface {
				$extend = $options->extend ?? [];
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				$captured = [
					'object' => $object,
					'register' => $register,
					'schema' => $schema,
					'uuid' => $uuid,
				];

				// saveObject() returns an ENTITY (ADR-084). The materialised
				// calculations come back on the stored payload, which is what
				// LeadService::toArray() reads through getObject().
				$entity = new ObjectEntity();
				$entity->setUuid(self::NIL_UUID);
				$entity->setObject(array_merge($object, ['qualificationScore' => 45, 'winProbability' => 36.0]));
				return $entity;
			}
		);

		$result = $this->buildService()->createLead(
			title: 'KCC self-service portal',
			client: self::NIL_UUID,
			value: 45000,
			source: 'referral',
		);

		$this->assertArrayNotHasKey(key: 'error', array: $result);
		$this->assertSame(expected: 'KCC self-service portal', actual: $captured['object']['title']);
		$this->assertSame(expected: self::NIL_UUID, actual: $captured['object']['client']);
		$this->assertSame(expected: 45000.0, actual: $captured['object']['value']);
		$this->assertSame(expected: 'referral', actual: $captured['object']['source']);
		$this->assertArrayNotHasKey(key: 'winProbability', array: $captured['object']);
		$this->assertSame(expected: 'reg-1', actual: $captured['register']);
		$this->assertSame(expected: 'sch-lead', actual: $captured['schema']);
		$this->assertNull(actual: $captured['uuid']);
		$this->assertSame(expected: 45, actual: $result['lead']['qualificationScore']);
		$this->assertSame(expected: 36.0, actual: $result['lead']['winProbability']);
	}//end testCreateLeadWritesLeadAndReturnsMaterialisedCalculations()

	/**
	 * createLead() denied by RBAC (no `create` permission on the lead
	 * schema) maps to a forbidden envelope, not a success.
	 *
	 * @return void
	 */
	public function testCreateLeadDeniedByRbacReturnsForbidden(): void {
		$this->stubAppConfig(values: ['register' => 'reg-1', 'lead_schema' => 'sch-lead']);

		$objectService = $this->mockObjectService();
		$objectService->method('saveObject')->willThrowException(
			new \Exception('User is not authorized to create this object (permission denied)')
		);

		$result = $this->buildService()->createLead(title: 'KCC self-service portal');

		$this->assertSame(expected: 'forbidden', actual: $result['error']['code']);
	}//end testCreateLeadDeniedByRbacReturnsForbidden()
	// =========================================================================
	// updateLead / winLead / loseLead (mcp-full-action-coverage)
	// =========================================================================

	/**
	 * A stored lead entity with the given fields.
	 *
	 * @param array<string, mixed> $fields The stored lead.
	 *
	 * @return ObjectEntity
	 */
	private function storedLead(array $fields): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid(self::NIL_UUID);
		$entity->setObject($fields);

		return $entity;
	}//end storedLead()

	/**
	 * The object service with a stored lead, recording what saveObject() receives.
	 *
	 * @param array<string, mixed>      $stored   The stored lead.
	 * @param array<string, mixed>|null $captured Receives the saved object and uuid.
	 *
	 * @return ObjectServiceInterface&MockObject
	 */
	private function objectServiceWithLead(array $stored, ?array &$captured): ObjectServiceInterface {
		$objectService = $this->mockObjectService();
		$objectService->method('find')->willReturn($this->storedLead(fields: $stored));
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions()) use (&$captured): ObjectEntityInterface {
				$captured = ['object' => $object, 'uuid' => $options->uuid, 'schema' => $options->target->schema];
				return $this->storedLead(fields: $object);
			}
		);

		return $objectService;
	}//end objectServiceWithLead()

	/**
	 * A stage change stamps stageEnteredAt and saves the merged lead under its own uuid.
	 *
	 * @return void
	 */
	public function testUpdateLeadStageChangeStampsStageEnteredAt(): void {
		$this->stubAppConfig(values: ['register' => 'reg-1', 'lead_schema' => 'sch-lead']);
		$captured = null;
		$this->objectServiceWithLead(
			stored: ['title' => 'Portal', 'stage' => 'qualified', 'stageEnteredAt' => '2026-01-01T00:00:00+00:00', 'value' => 1000],
			captured: $captured
		);

		$before = time();
		$result = $this->buildService()->updateLead(id: self::NIL_UUID, stage: 'proposal');

		$this->assertArrayNotHasKey(key: 'error', array: $result);
		$this->assertSame(expected: self::NIL_UUID, actual: $captured['uuid']);
		$this->assertSame(expected: 'sch-lead', actual: $captured['schema']);
		$this->assertSame(expected: 'proposal', actual: $captured['object']['stage']);
		$this->assertSame(expected: 'Portal', actual: $captured['object']['title'], message: 'unchanged fields are kept');
		$this->assertGreaterThanOrEqual(expected: $before, actual: strtotime($captured['object']['stageEnteredAt']));
		$this->assertSame(expected: 'proposal', actual: $result['lead']['stage']);
	}//end testUpdateLeadStageChangeStampsStageEnteredAt()

	/**
	 * An update that leaves the stage alone leaves stageEnteredAt alone, also when the same stage is sent.
	 *
	 * @return void
	 */
	public function testUpdateLeadWithoutStageChangeKeepsStageEnteredAt(): void {
		$this->stubAppConfig(values: ['register' => 'reg-1', 'lead_schema' => 'sch-lead']);
		$captured = null;
		$this->objectServiceWithLead(
			stored: ['title' => 'Portal', 'stage' => 'qualified', 'stageEnteredAt' => '2026-01-01T00:00:00+00:00'],
			captured: $captured
		);

		$this->buildService()->updateLead(id: self::NIL_UUID, value: 2500.0, stage: 'qualified');

		$this->assertSame(expected: 2500.0, actual: $captured['object']['value']);
		$this->assertSame(expected: '2026-01-01T00:00:00+00:00', actual: $captured['object']['stageEnteredAt']);
	}//end testUpdateLeadWithoutStageChangeKeepsStageEnteredAt()

	/**
	 * A status argument is refused, naming the transition tools, before anything is read.
	 *
	 * @return void
	 */
	public function testUpdateLeadRefusesStatus(): void {
		$this->stubAppConfig(values: ['register' => 'reg-1', 'lead_schema' => 'sch-lead']);
		$objectService = $this->mockObjectService();
		$objectService->expects($this->never())->method('find');
		$objectService->expects($this->never())->method('saveObject');

		$result = $this->buildService()->updateLead(id: self::NIL_UUID, title: 'Portal', status: 'won');

		$this->assertSame(expected: 'invalid_arguments', actual: $result['error']['code']);
		$this->assertStringContainsString(needle: 'winLead', haystack: $result['error']['message']);
		$this->assertStringContainsString(needle: 'loseLead', haystack: $result['error']['message']);
	}//end testUpdateLeadRefusesStatus()

	/**
	 * An update without an id, or without a field to change, is invalid.
	 *
	 * @return void
	 */
	public function testUpdateLeadWithoutIdOrFieldsIsInvalid(): void {
		$this->stubAppConfig(values: ['register' => 'reg-1', 'lead_schema' => 'sch-lead']);
		$objectService = $this->mockObjectService();
		$objectService->expects($this->never())->method('saveObject');

		$noId = $this->buildService()->updateLead(id: ' ', title: 'Portal');
		$noFields = $this->buildService()->updateLead(id: self::NIL_UUID, title: '  ');

		$this->assertSame(expected: 'Required argument id is missing.', actual: $noId['error']['message']);
		$this->assertSame(expected: 'Give at least one field to change.', actual: $noFields['error']['message']);
	}//end testUpdateLeadWithoutIdOrFieldsIsInvalid()

	/**
	 * A lead the caller cannot find is not_found, and nothing is saved.
	 *
	 * @return void
	 */
	public function testUpdateLeadOfMissingLeadIsNotFound(): void {
		$this->stubAppConfig(values: ['register' => 'reg-1', 'lead_schema' => 'sch-lead']);
		$objectService = $this->mockObjectService();
		$objectService->method('find')->willReturn(null);
		$objectService->expects($this->never())->method('saveObject');

		$result = $this->buildService()->updateLead(id: self::NIL_UUID, title: 'Portal');

		$this->assertSame(expected: ['error' => ['code' => 'not_found', 'message' => 'No lead with that id.']], actual: $result);
	}//end testUpdateLeadOfMissingLeadIsNotFound()

	/**
	 * A save the RBAC refuses is forbidden.
	 *
	 * @return void
	 */
	public function testUpdateLeadDeniedByRbacIsForbidden(): void {
		$this->stubAppConfig(values: ['register' => 'reg-1', 'lead_schema' => 'sch-lead']);
		$objectService = $this->mockObjectService();
		$objectService->method('find')->willReturn($this->storedLead(fields: ['title' => 'Portal']));
		$objectService->method('saveObject')->willThrowException(new \Exception('permission denied'));

		$result = $this->buildService()->updateLead(id: self::NIL_UUID, title: 'Portal 2');

		$this->assertSame(expected: 'forbidden', actual: $result['error']['code']);
	}//end testUpdateLeadDeniedByRbacIsForbidden()

	/**
	 * winLead and loseLead drive the lead's declared win and lose transitions.
	 *
	 * @return void
	 */
	public function testWinAndLoseLeadRunTheDeclaredTransitions(): void {
		$calls = [];
		$engine = new class($calls) {
			/**
			 * Constructor.
			 *
			 * @param list<array{0: string, 1: string}> $calls The recorded calls.
			 */
			public function __construct(private array &$calls) {
			}

			/**
			 * The engine's transition().
			 *
			 * @param string $objectId The lead.
			 * @param string $action   The transition.
			 *
			 * @return ObjectEntity The moved lead.
			 */
			public function transition(string $objectId, string $action): ObjectEntity {
				$this->calls[] = [$objectId, $action];
				$moved = new ObjectEntity();
				$status = 'lost';
				if ($action === 'win') {
					$status = 'won';
				}

				$moved->setObject(['status' => $status]);
				return $moved;
			}
		};
		$engines = $this->createMock(originalClassName: ContainerInterface::class);
		$engines->method('get')->willReturn($engine);
		$service = new LeadService(
			container: $this->container,
			appConfig: $this->appConfig,
			mcp: new McpAnswer(logger: $this->logger),
			transitions: new McpTransition(container: $engines, mcp: new McpAnswer(logger: $this->logger), logger: $this->logger),
		);

		$won = $service->winLead(id: self::NIL_UUID);
		$lost = $service->loseLead(id: self::NIL_UUID);

		$this->assertSame(expected: 'won', actual: $won['lead']['status']);
		$this->assertSame(expected: 'lost', actual: $lost['lead']['status']);
		$this->assertSame(expected: [[self::NIL_UUID, 'win'], [self::NIL_UUID, 'lose']], actual: $calls);
	}//end testWinAndLoseLeadRunTheDeclaredTransitions()

	/**
	 * Without the transition helper the tools answer not_configured rather than failing.
	 *
	 * @return void
	 */
	public function testWinLeadWithoutTransitionsIsNotConfigured(): void {
		$result = $this->buildService()->winLead(id: self::NIL_UUID);

		$this->assertSame(expected: 'not_configured', actual: $result['error']['code']);
	}//end testWinLeadWithoutTransitionsIsNotConfigured()

	/**
	 * The three new tools carry the attribute with the scope, reach and hints the spec names.
	 *
	 * @return void
	 */
	public function testNewLeadToolsDeclareScopeReachAndHints(): void {
		$expected = [
			'updateLead' => ['scope' => 'update', 'destructive' => false],
			'winLead' => ['scope' => 'update', 'destructive' => true],
			'loseLead' => ['scope' => 'update', 'destructive' => true],
		];
		foreach ($expected as $method => $want) {
			$attribute = (new ReflectionMethod(LeadService::class, $method))->getAttributes(McpTool::class)[0]->newInstance();
			$this->assertSame(expected: $method, actual: $attribute->name);
			$this->assertSame(expected: $want['scope'], actual: $attribute->scope);
			$this->assertSame(expected: 'instance', actual: $attribute->reach);
			$this->assertSame(expected: $want['destructive'], actual: $attribute->destructiveHint, message: $method);
			$this->assertTrue(condition: $attribute->idempotentHint, message: $method);
			$this->assertFalse(condition: $attribute->readOnlyHint, message: $method);
		}
	}//end testNewLeadToolsDeclareScopeReachAndHints()
}//end class
