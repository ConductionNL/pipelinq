<?php

/**
 * LeadRotationService: sharing new leads in turn.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\LeadRotationService;
use OCA\Pipelinq\Service\RoutingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The fewest leads in 30 days wins; a cap, a pause or unavailability excludes;
 * a tie goes to the oldest last lead; nobody eligible writes only the reason;
 * a lead with an assignee is left alone.
 *
 * @covers \OCA\Pipelinq\Service\LeadRotationService
 *
 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md
 */
class LeadRotationServiceTest extends TestCase {

	/**
	 * The fixed "now" of every test, relative to which lead ages are given.
	 *
	 * @var string
	 */
	private const NOW = '2026-06-30T12:00:00+00:00';

	/**
	 * The profiles the skill match returns.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $profiles = [];

	/**
	 * Lead ages in days per assignee.
	 *
	 * @var array<string, array<int, int>>
	 */
	private array $leadAges = [];

	/**
	 * The app config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = ['register' => 'reg', 'lead_schema' => 'leads'];

	/**
	 * The findAll() queries seen.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The routing service mock.
	 *
	 * @var RoutingService&MockObject
	 */
	private RoutingService&MockObject $routing;

	/**
	 * The object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->routing = $this->createMock(RoutingService::class);
		$this->routing->method('findMatchingAgents')->willReturnCallback(
			fn (string $category): array => ($category === 'solar' ? $this->profiles : [])
		);
		$this->routing->method('filterByAvailability')->willReturnCallback(
			static fn (array $profiles): array => array_values(array_filter($profiles, static fn (array $p): bool => ($p['isAvailable'] ?? true) !== false))
		);

		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$this->queries[] = $config;
				$ages = ($this->leadAges[$config['filters']['assignee']] ?? []);
				return array_map(static fn (int $days): ObjectEntity => self::lead(daysAgo: $days), $ages);
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return LeadRotationService
	 */
	private function service(): LeadRotationService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn(new DateTimeImmutable(self::NOW));

		return new LeadRotationService(
			routing: $this->routing,
			objectService: $this->objects,
			appConfig: $appConfig,
			timeFactory: $clock,
			logger: $this->logger,
		);
	}//end service()

	/**
	 * A lead entity created this many days before NOW.
	 *
	 * @param int $daysAgo The age in days.
	 *
	 * @return ObjectEntity
	 */
	private static function lead(int $daysAgo): ObjectEntity {
		$created = (new DateTimeImmutable(self::NOW))->modify('-' . $daysAgo . ' days')->format(DATE_ATOM);
		$entity = new class($created) extends ObjectEntity {
			/**
			 * Constructor.
			 *
			 * @param string $created When the lead was created.
			 */
			public function __construct(private string $created) {
			}//end __construct()

			/**
			 * The payload with production's @self.created.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				$data = parent::jsonSerialize();
				$data['@self']['created'] = $this->created;
				return $data;
			}//end jsonSerialize()
		};
		$entity->setObject(['title' => 'Lead']);
		return $entity;
	}//end lead()

	/**
	 * A Solar profile.
	 *
	 * @param string               $userId The colleague.
	 * @param array<string, mixed> $extra  Extra profile fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function profile(string $userId, array $extra = []): array {
		return (['userId' => $userId, 'matchedSkill' => 'Solar'] + $extra);
	}//end profile()

	/**
	 * Rotation is off unless switched on.
	 *
	 * @return void
	 */
	public function testRotationIsOffUnlessSwitchedOn(): void {
		$this->assertFalse($this->service()->isEnabled());

		$this->config[LeadRotationService::ENABLED_KEY] = 'true';
		$this->assertTrue($this->service()->isEnabled());

		$this->config[LeadRotationService::ENABLED_KEY] = 'yes';
		$this->assertFalse($this->service()->isEnabled());
	}//end testRotationIsOffUnlessSwitchedOn()

	/**
	 * The colleague with the fewest leads in 30 days gets the lead, with the
	 * reason naming the count, the cap and the skill; leads older than 30
	 * days do not count.
	 *
	 * @return void
	 */
	public function testTheFewestLeadsWins(): void {
		$this->profiles = [self::profile('anna', ['leadCapPer30Days' => 10]), self::profile('bram', ['leadCapPer30Days' => 10])];
		$this->leadAges = ['anna' => [1, 2, 3], 'bram' => [1, 2, 45, 60, 90]];

		$lead = $this->service()->assign(lead: ['title' => 'Roof', 'category' => 'solar', 'assignee' => '']);

		$this->assertSame('bram', $lead['assignee']);
		$this->assertSame('rotation: fewest leads in 30 days (2 of cap 10), skill Solar', $lead['assignmentReason']);
		$this->assertSame('Roof', $lead['title']);
		$this->assertSame(
			['filters' => ['register' => 'reg', 'schema' => 'leads', 'assignee' => 'anna'], 'limit' => 999],
			$this->queries[0]
		);
	}//end testTheFewestLeadsWins()

	/**
	 * A colleague at their cap is passed over.
	 *
	 * @return void
	 */
	public function testACappedColleagueIsPassedOver(): void {
		$this->profiles = [self::profile('anna', ['leadCapPer30Days' => 2]), self::profile('bram', ['leadCapPer30Days' => '10'])];
		$this->leadAges = ['anna' => [1, 2], 'bram' => [1, 2, 3, 4]];

		$lead = $this->service()->assign(lead: ['category' => 'solar']);

		$this->assertSame('bram', $lead['assignee']);
		$this->assertSame('rotation: fewest leads in 30 days (4 of cap 10), skill Solar', $lead['assignmentReason']);
	}//end testACappedColleagueIsPassedOver()

	/**
	 * A paused or unavailable colleague is passed over; without a cap the
	 * reason says so.
	 *
	 * @return void
	 */
	public function testAPausedOrUnavailableColleagueIsPassedOver(): void {
		$this->profiles = [
			self::profile('anna', ['pauseLeadAssignment' => true]),
			self::profile('cees', ['isAvailable' => false]),
			self::profile('bram', ['leadCapPer30Days' => 0]),
		];
		$this->leadAges = ['bram' => [1, 2, 3]];

		$lead = $this->service()->assign(lead: ['category' => 'solar']);

		$this->assertSame('bram', $lead['assignee']);
		$this->assertSame('rotation: fewest leads in 30 days (3 of no cap), skill Solar', $lead['assignmentReason']);
	}//end testAPausedOrUnavailableColleagueIsPassedOver()

	/**
	 * On equal counts the colleague whose last lead is oldest gets the lead.
	 *
	 * @return void
	 */
	public function testATieGoesToTheOldestLastLead(): void {
		$this->profiles = [self::profile('anna'), self::profile('bram'), self::profile('cees')];
		$this->leadAges = ['anna' => [1, 9], 'bram' => [5, 6], 'cees' => [2, 3]];

		$this->assertSame('bram', $this->service()->assign(lead: ['category' => 'solar'])['assignee']);
	}//end testATieGoesToTheOldestLastLead()

	/**
	 * A colleague without leads comes before one with an old lead on a tie,
	 * and equal colleagues are taken by name.
	 *
	 * @return void
	 */
	public function testAColleagueWithoutLeadsComesFirst(): void {
		$this->profiles = [self::profile('cees'), self::profile('bram'), self::profile('anna')];
		$this->leadAges = ['anna' => [45]];

		$this->assertSame('anna', $this->service()->assign(lead: ['category' => 'solar'])['assignee']);
	}//end testAColleagueWithoutLeadsComesFirst()

	/**
	 * Three leads with two colleagues are shared two and one.
	 *
	 * @return void
	 */
	public function testThreeLeadsAreSharedInTurn(): void {
		$this->profiles = [self::profile('anna'), self::profile('bram')];
		$service = $this->service();

		$given = [];
		for ($i = 0; $i < 3; $i++) {
			$userId = $service->assign(lead: ['category' => 'solar'])['assignee'];
			$given[] = $userId;
			$this->leadAges[$userId][] = 0;
		}

		$this->assertSame(['anna', 'bram', 'anna'], $given);
	}//end testThreeLeadsAreSharedInTurn()

	/**
	 * When nobody is eligible the lead stays unassigned with the reason.
	 *
	 * @return void
	 */
	public function testNobodyEligibleWritesOnlyTheReason(): void {
		$this->profiles = [self::profile('anna', ['pauseLeadAssignment' => true]), self::profile('', [])];

		$lead = $this->service()->assign(lead: ['title' => 'Roof', 'category' => 'solar']);

		$this->assertSame(['title' => 'Roof', 'category' => 'solar', 'assignmentReason' => 'rotation found no eligible colleague'], $lead);
	}//end testNobodyEligibleWritesOnlyTheReason()

	/**
	 * A lead without a matching skill stays unassigned with the reason.
	 *
	 * @return void
	 */
	public function testALeadWithoutAMatchingSkillStaysUnassigned(): void {
		$this->profiles = [self::profile('anna')];

		$lead = $this->service()->assign(lead: ['category' => 'wind']);

		$this->assertArrayNotHasKey('assignee', $lead);
		$this->assertSame(LeadRotationService::NO_ELIGIBLE_REASON, $lead['assignmentReason']);
	}//end testALeadWithoutAMatchingSkillStaysUnassigned()

	/**
	 * A lead that already has an assignee is left alone.
	 *
	 * @return void
	 */
	public function testALeadWithAnAssigneeIsLeftAlone(): void {
		$this->profiles = [self::profile('anna')];
		$this->routing->expects($this->never())->method('findMatchingAgents');

		$this->assertNull($this->service()->assign(lead: ['category' => 'solar', 'assignee' => 'rep']));
	}//end testALeadWithAnAssigneeIsLeftAlone()

	/**
	 * A failing count is logged and counts as no leads.
	 *
	 * @return void
	 */
	public function testAFailingCountIsLoggedAndCountsAsNone(): void {
		$this->profiles = [self::profile('anna')];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willThrowException(new RuntimeException('down'));
		$this->objects = $objects;
		$this->logger->expects($this->once())->method('error')->with(
			'LeadRotationService: failed to count recent leads',
			['exception' => 'down', 'userId' => 'anna']
		);

		$lead = $this->service()->assign(lead: ['category' => 'solar']);

		$this->assertSame('rotation: fewest leads in 30 days (0 of no cap), skill Solar', $lead['assignmentReason']);
	}//end testAFailingCountIsLoggedAndCountsAsNone()

	/**
	 * Without the lead schema configured nobody's leads are read.
	 *
	 * @return void
	 */
	public function testWithoutTheLeadSchemaNoLeadsAreRead(): void {
		$this->config = [];
		$this->profiles = [self::profile('anna')];

		$this->assertSame('anna', $this->service()->assign(lead: ['category' => 'solar'])['assignee']);
		$this->assertSame([], $this->queries);
	}//end testWithoutTheLeadSchemaNoLeadsAreRead()
}//end class
