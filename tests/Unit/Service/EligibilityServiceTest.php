<?php

/**
 * Unit tests for EligibilityService — skill-match, gap steps, availability intersection.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/appointment-booking-03-skill-routing-eligibility/specs/appointment-booking/spec.md#req-apt-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\AvailabilityService;
use OCA\Pipelinq\Service\EligibilityService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for EligibilityService.
 *
 * Mocks `ObjectService` (the OR data path) and uses a real
 * {@see AvailabilityService} instance fed through the same mock so the
 * availability-intersection test exercises the genuine composition path.
 */
class EligibilityServiceTest extends TestCase {

	/**
	 * Service requiring `color-certified`.
	 *
	 * @var array<string, mixed>
	 */
	private const SERVICE_COLOR_REQUIRED = [
		'@self' => ['id' => 'svc-color'],
		'name' => 'Color treatment',
		'durationMinutes' => 60,
		'requiredSkills' => ['color-certified'],
		'multiStep' => [],
	];

	/**
	 * Service with no skill requirements.
	 *
	 * @var array<string, mixed>
	 */
	private const SERVICE_NO_SKILL = [
		'@self' => ['id' => 'svc-trim'],
		'name' => 'Trim',
		'durationMinutes' => 15,
		'requiredSkills' => [],
		'multiStep' => [],
	];

	/**
	 * Multi-step service: color (skill) -> gap (no resource) -> cut (any).
	 *
	 * @var array<string, mixed>
	 */
	private const SERVICE_MULTISTEP = [
		'@self' => ['id' => 'svc-color-cut'],
		'name' => 'Color + cut',
		'durationMinutes' => 120,
		'requiredSkills' => [],
		'multiStep' => [
			['durationMinutes' => 30, 'skillRequired' => 'color-certified', 'resourceType' => 'staff', 'allowGap' => false],
			['durationMinutes' => 60, 'skillRequired' => '',                'resourceType' => '',      'allowGap' => true],
			['durationMinutes' => 30, 'skillRequired' => '',                'resourceType' => 'staff', 'allowGap' => false],
		],
	];

	/**
	 * Certified stylist Sarah.
	 *
	 * @var array<string, mixed>
	 */
	private const RESOURCE_SARAH = [
		'@self' => ['id' => 'res-sarah'],
		'name' => 'Sarah',
		'type' => 'staff',
		'skills' => ['color-certified', 'cut'],
		'workingHours' => [
			['day' => 'monday', 'openTime' => '09:00', 'closeTime' => '17:30'],
		],
		'vacations' => [],
	];

	/**
	 * Certified stylist Mia.
	 *
	 * @var array<string, mixed>
	 */
	private const RESOURCE_MIA = [
		'@self' => ['id' => 'res-mia'],
		'name' => 'Mia',
		'type' => 'staff',
		'skills' => ['color-certified'],
		'workingHours' => [
			['day' => 'monday', 'openTime' => '09:00', 'closeTime' => '17:30'],
		],
		'vacations' => [],
	];

	/**
	 * Uncertified junior stylist Tom — no skills.
	 *
	 * @var array<string, mixed>
	 */
	private const RESOURCE_TOM = [
		'@self' => ['id' => 'res-tom'],
		'name' => 'Tom',
		'type' => 'staff',
		'skills' => [],
		'workingHours' => [
			['day' => 'monday', 'openTime' => '09:00', 'closeTime' => '17:30'],
		],
		'vacations' => [],
	];

	/**
	 * Build an EligibilityService backed by a configurable ObjectService mock.
	 *
	 * Both the service-under-test AND the wired AvailabilityService share the
	 * same mock so the availability-intersection test exercises the genuine
	 * composition path.
	 *
	 * @param ObjectServiceInterface $objectService The mock.
	 *
	 * @return EligibilityService
	 */
	private function buildService(ObjectServiceInterface $objectService): EligibilityService {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			callback: static function (string $app, string $key, string $default = ''): string {
				$values = [
					'register' => 'pipelinq',
					'resource_schema' => 'resource',
					'booking_schema' => 'booking',
					'service_schema' => 'service',
					'availability_cache_schema' => 'availability-cache',
				];
				return ($values[$key] ?? $default);
			}
		);

		$cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$availability = new AvailabilityService(
			appConfig: $appConfig,
			cacheFactory: $cacheFactory,
			logger: $logger,
			objectService: $objectService,
		);

		return new EligibilityService(
			appConfig: $appConfig,
			availabilityService: $availability,
			logger: $logger,
			objectService: $objectService,
		);
	}//end buildService()

	/**
	 * Stub the ObjectService::find call to return the right entity per id.
	 *
	 * Uses `mixed ...$args` so the closure tolerates the real OR signature
	 * (which has 7 params: id, _extend, files, register, schema, _rbac,
	 * _multitenancy) AND the unit-test stub signature (id, register, schema).
	 * In both cases args[0] is the id and the schema is in either args[2]
	 * (stub) or args[4] (real OR).
	 *
	 * @param ObjectServiceInterface $mock The mock to wire.
	 * @param array<string, array<string, mixed>> $bySchema Per-schema id->entity map.
	 *
	 * @return void
	 */
	private function wireFind(ObjectServiceInterface $mock, array $bySchema): void {
		$mock->method('find')->willReturnCallback(
			callback: static function (mixed ...$args) use ($bySchema): ?array {
				$id = (string)($args[0] ?? '');
				$schema = '';
				if (isset($args[4]) === true && is_string($args[4]) === true) {
					// Real OR signature: register at args[3], schema at args[4].
					$schema = (string)$args[4];
				} elseif (isset($args[2]) === true && is_string($args[2]) === true) {
					// Pipelinq stub signature: register at args[1], schema at args[2].
					$schema = (string)$args[2];
				}

				$bucket = ($bySchema[$schema] ?? []);
				return ($bucket[$id] ?? null);
			}
		);
	}//end wireFind()

	/**
	 * Stub the ObjectService::findAll call to return the right collection per schema.
	 *
	 * Uses `mixed ...$args` for the same reason as {@see wireFind()}; the
	 * first positional arg is the `$config` array in every signature.
	 *
	 * @param ObjectServiceInterface $mock The mock to wire.
	 * @param array<string, array<int, array<string, mixed>>> $bySchema Per-schema row list.
	 *
	 * @return void
	 */
	private function wireFindAll(ObjectServiceInterface $mock, array $bySchema): void {
		$mock->method('findAll')->willReturnCallback(
			callback: static function (mixed ...$args) use ($bySchema): array {
				$config = ($args[0] ?? []);
				if (is_array($config) === false) {
					return [];
				}

				$filters = ($config['filters'] ?? []);
				$schema = (string)($filters['schema'] ?? '');
				$rows = ($bySchema[$schema] ?? []);

				if (isset($filters['resourceId']) === true) {
					$needle = (string)$filters['resourceId'];
					$matched = [];
					foreach ($rows as $row) {
						$self = (is_array($row['@self'] ?? null) === true) ? $row['@self'] : [];
						$id = (string)($self['id'] ?? ($row['resourceId'] ?? ''));
						if ($id === $needle || ($row['resourceId'] ?? '') === $needle) {
							$matched[] = $row;
						}
					}

					return $matched;
				}

				return $rows;
			}
		);
	}//end wireFindAll()

	/**
	 * REQ-APT-004 scenario 1: only certified stylists are eligible.
	 *
	 * @return void
	 */
	public function testEligibleResourcesExcludesUncertifiedForSkillRequiredService(): void {
		$mock = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->wireFind(mock: $mock, bySchema: ['service' => ['svc-color' => self::SERVICE_COLOR_REQUIRED]]);
		$this->wireFindAll(mock: $mock, bySchema: ['resource' => [self::RESOURCE_SARAH, self::RESOURCE_MIA, self::RESOURCE_TOM]]);

		$service = $this->buildService(objectService: $mock);
		$eligible = $service->getEligibleResources(serviceId: 'svc-color');

		$names = array_map(static fn (array $r): string => (string)$r['name'], $eligible);

		$this->assertContains(needle: 'Sarah', haystack: $names);
		$this->assertContains(needle: 'Mia', haystack: $names);
		$this->assertNotContains(needle: 'Tom', haystack: $names, message: 'Tom has no skills so cannot do color');
		$this->assertCount(expectedCount: 2, haystack: $eligible);

	}//end testEligibleResourcesExcludesUncertifiedForSkillRequiredService()

	/**
	 * REQ-APT-004 scenario 2: a resource with no skills is eligible for a
	 * service that requires no skills.
	 *
	 * @return void
	 */
	public function testResourceWithNoSkillsIsEligibleForNoSkillService(): void {
		$mock = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->wireFind(mock: $mock, bySchema: ['service' => ['svc-trim' => self::SERVICE_NO_SKILL]]);
		$this->wireFindAll(mock: $mock, bySchema: ['resource' => [self::RESOURCE_TOM]]);

		$service = $this->buildService(objectService: $mock);
		$eligible = $service->getEligibleResources(serviceId: 'svc-trim');

		$this->assertCount(expectedCount: 1, haystack: $eligible);
		$this->assertSame(expected: 'Tom', actual: (string)$eligible[0]['name']);

	}//end testResourceWithNoSkillsIsEligibleForNoSkillService()

	/**
	 * REQ-APT-004 scenario 3: multi-step services apply step-specific skill
	 * filters; gap steps require no resource; any-stylist steps accept all.
	 *
	 * @return void
	 */
	public function testMultiStepServiceAppliesStepSpecificSkillFilters(): void {
		$mock = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->wireFind(mock: $mock, bySchema: ['service' => ['svc-color-cut' => self::SERVICE_MULTISTEP]]);
		$this->wireFindAll(mock: $mock, bySchema: ['resource' => [self::RESOURCE_SARAH, self::RESOURCE_MIA, self::RESOURCE_TOM]]);

		$service = $this->buildService(objectService: $mock);
		$steps = $service->getEligibleResourcesPerStep(serviceId: 'svc-color-cut');

		$this->assertCount(expectedCount: 3, haystack: $steps);

		// Step 1: color-certified — only Sarah + Mia.
		$this->assertSame(expected: 'color-certified', actual: $steps[0]['skillRequired']);
		$this->assertFalse(condition: $steps[0]['allowGap']);
		$names0 = array_map(static fn (array $r): string => (string)$r['name'], $steps[0]['eligibleResources']);
		$this->assertContains(needle: 'Sarah', haystack: $names0);
		$this->assertContains(needle: 'Mia', haystack: $names0);
		$this->assertNotContains(needle: 'Tom', haystack: $names0);

		// Step 2: gap step — no resource required.
		$this->assertTrue(condition: $steps[1]['allowGap']);
		$this->assertSame(expected: [], actual: $steps[1]['eligibleResources']);

		// Step 3: any stylist — all 3.
		$this->assertSame(expected: '', actual: $steps[2]['skillRequired']);
		$this->assertFalse(condition: $steps[2]['allowGap']);
		$this->assertCount(expectedCount: 3, haystack: $steps[2]['eligibleResources']);

	}//end testMultiStepServiceAppliesStepSpecificSkillFilters()

	/**
	 * Empty-set rule: a resource with no skills is excluded when a service
	 * requires any skill, and the matchesSkillRequirements primitive returns
	 * false directly. This guards the ADR-012 single-seam invariant.
	 *
	 * @return void
	 */
	public function testMatchesSkillRequirementsAppliesSubsetSemantics(): void {
		$mock = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$service = $this->buildService(objectService: $mock);

		$this->assertTrue(condition: $service->matchesSkillRequirements(resource: ['skills' => []], requiredSkills: []));
		$this->assertTrue(condition: $service->matchesSkillRequirements(resource: ['skills' => ['a']], requiredSkills: []));
		$this->assertTrue(condition: $service->matchesSkillRequirements(resource: ['skills' => ['a', 'b']], requiredSkills: ['a']));
		$this->assertTrue(condition: $service->matchesSkillRequirements(resource: ['skills' => ['a', 'b']], requiredSkills: ['a', 'b']));

		$this->assertFalse(condition: $service->matchesSkillRequirements(resource: ['skills' => []], requiredSkills: ['a']));
		$this->assertFalse(condition: $service->matchesSkillRequirements(resource: ['skills' => ['b']], requiredSkills: ['a']));
		$this->assertFalse(condition: $service->matchesSkillRequirements(resource: ['skills' => ['a']], requiredSkills: ['a', 'b']));

	}//end testMatchesSkillRequirementsAppliesSubsetSemantics()

	/**
	 * Section 1 task 5: eligibility is intersected with availability so an
	 * eligible-but-unavailable resource (e.g. wrong weekday) is excluded.
	 *
	 * Sarah works Monday only; Mia works Monday only. Querying for a Sunday
	 * (2026-06-07) should return zero qualified resources even though both
	 * are skill-eligible.
	 *
	 * @return void
	 */
	public function testEligibleResourcesForSlotExcludesUnavailableResources(): void {
		$mock = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->wireFind(
			mock: $mock,
			bySchema: [
				'service' => ['svc-color' => self::SERVICE_COLOR_REQUIRED],
				'resource' => [
					'res-sarah' => self::RESOURCE_SARAH,
					'res-mia' => self::RESOURCE_MIA,
				],
			]
		);
		$this->wireFindAll(
			mock: $mock,
			bySchema: [
				'resource' => [self::RESOURCE_SARAH, self::RESOURCE_MIA],
				'booking' => [],
			]
		);

		$service = $this->buildService(objectService: $mock);

		// 2026-06-07 is a Sunday — neither stylist works.
		$sunday = $service->getEligibleResourcesForSlot(serviceId: 'svc-color', date: '2026-06-07', durationMinutes: 60);
		$this->assertSame(expected: [], actual: $sunday);

		// 2026-06-01 is a Monday — both work, both skill-qualified.
		$monday = $service->getEligibleResourcesForSlot(serviceId: 'svc-color', date: '2026-06-01', durationMinutes: 60);
		$this->assertCount(expectedCount: 2, haystack: $monday);

	}//end testEligibleResourcesForSlotExcludesUnavailableResources()

	/**
	 * Empty serviceId / unknown service yields an empty list — defensive guard.
	 *
	 * @return void
	 */
	public function testEmptyAndUnknownServiceIdReturnsEmptyList(): void {
		$mock = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->wireFind(mock: $mock, bySchema: ['service' => []]);
		$this->wireFindAll(mock: $mock, bySchema: ['resource' => [self::RESOURCE_SARAH]]);

		$service = $this->buildService(objectService: $mock);

		$this->assertSame(expected: [], actual: $service->getEligibleResources(serviceId: ''));
		$this->assertSame(expected: [], actual: $service->getEligibleResources(serviceId: 'svc-missing'));
		$this->assertSame(expected: [], actual: $service->getEligibleResourcesPerStep(serviceId: ''));
		$this->assertSame(expected: [], actual: $service->getEligibleResourcesForSlot(serviceId: '', date: '2026-06-01', durationMinutes: 30));

	}//end testEmptyAndUnknownServiceIdReturnsEmptyList()
}//end class
