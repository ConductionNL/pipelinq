<?php

/**
 * Unit tests for LeadRotationService.
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
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\LeadRotationService;
use OCA\Pipelinq\Service\RoutingService;
use OCA\Pipelinq\Service\TicketService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * New unassigned leads go in turn to the eligible colleague with the fewest leads in 30 days.
 *
 * @covers \OCA\Pipelinq\Service\LeadRotationService
 * @uses   \OCA\Pipelinq\Service\RoutingService
 */
class LeadRotationServiceTest extends TestCase {

	/**
	 * The moment every test runs at.
	 *
	 * @var string
	 */
	private const NOW = '2026-10-15T10:00:00+00:00';

	/**
	 * Agent profiles, keyed by user id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $profiles = [];

	/**
	 * Stored leads.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $leads = [];

	/**
	 * The admin switch.
	 *
	 * @var string
	 */
	private string $enabled = 'true';

	/**
	 * Whether reading leads throws.
	 *
	 * @var bool
	 */
	private bool $leadsFail = false;

	/**
	 * Anna and Bram both have skill Solar and no leads yet.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->profiles = [
			'anna' => ['userId' => 'anna', 'skills' => ['skill-solar'], 'isAvailable' => true],
			'bram' => ['userId' => 'bram', 'skills' => ['skill-solar'], 'isAvailable' => true],
		];
		$this->leads = [];
	}//end setUp()

	/**
	 * Spec scenario: three Solar leads are shared in turn, two and one, each with its reason.
	 *
	 * @return void
	 */
	public function testThreeLeadsAreSharedInTurn(): void {
		$service = $this->service();
		$given = [];
		foreach ([1, 2, 3] as $minute) {
			$fields = $service->assign(lead: ['title' => 'Zonnepanelen '.$minute, 'category' => 'solar']);
			$this->leads[] = ['assignee' => $fields['assignee'], 'assignedAt' => '2026-10-15T09:0'.$minute.':00+00:00'];
			$given[] = $fields['assignee'];
			$this->assertSame(self::NOW, $fields['assignedAt']);
			$this->assertStringStartsWith('Rotation: fewest leads in 30 days', $fields['assignmentReason']);
			$this->assertStringContainsString('skill Solar', $fields['assignmentReason']);
		}

		$this->assertSame(['anna', 'bram', 'anna'], $given, 'a tie goes to the colleague whose last assignment is oldest, then by name');
	}//end testThreeLeadsAreSharedInTurn()

	/**
	 * Spec scenario: a capped colleague is passed over.
	 *
	 * @return void
	 */
	public function testACappedColleagueIsPassedOver(): void {
		$this->profiles['anna']['leadCapPer30Days'] = 2;
		$this->leads = [
			['assignee' => 'anna', 'assignedAt' => '2026-10-01T09:00:00+00:00'],
			['assignee' => 'anna', 'assignedAt' => '2026-10-02T09:00:00+00:00'],
			['assignee' => 'bram', 'assignedAt' => '2026-10-03T09:00:00+00:00'],
			['assignee' => 'bram', 'assignedAt' => '2026-10-04T09:00:00+00:00'],
			['assignee' => 'bram', 'assignedAt' => '2026-10-05T09:00:00+00:00'],
		];

		$fields = $this->service()->assign(lead: ['category' => 'solar']);

		$this->assertSame('bram', $fields['assignee']);
		$this->assertSame('Rotation: fewest leads in 30 days (3, no cap), skill Solar.', $fields['assignmentReason']);
	}//end testACappedColleagueIsPassedOver()

	/**
	 * Only the last 30 days count, and the cap shows in the reason.
	 *
	 * @return void
	 */
	public function testOnlyTheLast30DaysCount(): void {
		$this->profiles['anna']['leadCapPer30Days'] = 10;
		$this->leads = [
			['assignee' => 'anna', 'assignedAt' => '2026-08-01T09:00:00+00:00'],
			['assignee' => 'anna', 'assignedAt' => '2026-08-02T09:00:00+00:00'],
			['assignee' => 'bram', 'assignedAt' => '2026-10-10T09:00:00+00:00'],
			['assignee' => 'anna', 'assignedAt' => 'not a date'],
			['assignee' => 'anna'],
		];

		$fields = $this->service()->assign(lead: ['category' => 'solar']);

		$this->assertSame('anna', $fields['assignee']);
		$this->assertSame('Rotation: fewest leads in 30 days (0, cap 10), skill Solar.', $fields['assignmentReason']);
	}//end testOnlyTheLast30DaysCount()

	/**
	 * Spec scenario: when everyone with the skill paused, the lead stays unassigned with the reason.
	 *
	 * @return void
	 */
	public function testEveryonePausedLeavesTheLeadUnassigned(): void {
		$this->profiles['anna']['pauseLeadAssignment'] = true;
		$this->profiles['bram']['pauseLeadAssignment'] = true;

		$this->assertSame(['assignmentReason' => 'Rotation found no eligible colleague.'], $this->service()->assign(lead: ['category' => 'solar']));
	}//end testEveryonePausedLeavesTheLeadUnassigned()

	/**
	 * An unavailable colleague, one without a user id, and a lead without a category get nobody.
	 *
	 * @return void
	 */
	public function testUnavailableOrUnknownColleaguesAreSkipped(): void {
		$this->profiles['anna']['isAvailable'] = false;
		$this->profiles['bram']['userId'] = '';

		$this->assertSame(['assignmentReason' => 'Rotation found no eligible colleague.'], $this->service()->assign(lead: ['category' => 'solar']));
		$this->assertSame(['assignmentReason' => 'Rotation found no eligible colleague.'], $this->service()->assign(lead: ['category' => '']));
	}//end testUnavailableOrUnknownColleaguesAreSkipped()

	/**
	 * Spec scenario: a lead made with an assignee is left alone.
	 *
	 * @return void
	 */
	public function testALeadWithAnAssigneeIsLeftAlone(): void {
		$this->assertSame([], $this->service()->assign(lead: ['category' => 'solar', 'assignee' => 'carla']));
	}//end testALeadWithAnAssigneeIsLeftAlone()

	/**
	 * When the leads cannot be read, every candidate counts as having none; nothing throws.
	 *
	 * @return void
	 */
	public function testUnreadableLeadsCountAsNone(): void {
		$this->leadsFail = true;

		$this->assertSame('anna', $this->service()->assign(lead: ['category' => 'solar'])['assignee']);
	}//end testUnreadableLeadsCountAsNone()

	/**
	 * The switch is off unless an admin set it to 'true'.
	 *
	 * @return void
	 */
	public function testTheSwitch(): void {
		$this->assertTrue($this->service()->isEnabled());
		$this->enabled = 'false';
		$this->assertFalse($this->service()->isEnabled());
		$this->enabled = '1';
		$this->assertFalse($this->service()->isEnabled());
	}//end testTheSwitch()

	/**
	 * What rotation writes validates against the lead schema as the install imports it; the profile fields exist on agentProfile.
	 *
	 * @return void
	 */
	public function testTheFieldsValidateAgainstTheRealSchemas(): void {
		$fields = $this->service()->assign(lead: ['category' => 'solar']);
		$lead = self::mergedSchema(slug: 'lead');

		$properties = [];
		foreach ($fields as $key => $value) {
			$this->assertArrayHasKey($key, $lead['properties'], $key.' must be declared or OpenRegister drops it');
			$properties[$key] = array_intersect_key($lead['properties'][$key], array_flip(['type', 'format', 'maxLength']));
			$this->assertArrayNotHasKey('readOnly', $lead['properties'][$key], $key.' is written on an existing lead');
		}

		$result = (new Validator())->validate(json_decode((string)json_encode($fields)), json_decode((string)json_encode(['type' => 'object', 'properties' => $properties])));
		$this->assertTrue($result->isValid());

		$profile = self::mergedSchema(slug: 'agentProfile')['properties'];
		$this->assertSame('integer', $profile['leadCapPer30Days']['type']);
		$this->assertSame('boolean', $profile['pauseLeadAssignment']['type']);

		// The control: a reason over 500 characters is refused.
		$properties['assignmentReason']['maxLength'] = 500;
		$long = (new Validator())->validate(json_decode((string)json_encode(['assignmentReason' => str_repeat('x', 501)])), json_decode((string)json_encode(['type' => 'object', 'properties' => $properties])));
		$this->assertFalse($long->isValid());
	}//end testTheFieldsValidateAgainstTheRealSchemas()

	/**
	 * A schema as the install imports it: the monolith with every register.d fragment merged on top, in filename order.
	 *
	 * @param string $slug The schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function mergedSchema(string $slug): array {
		$settings = dirname(__DIR__, 3) . '/lib/Settings';
		$merged = [];
		foreach (array_merge([$settings . '/pipelinq_register.json'], glob($settings . '/register.d/*.json')) as $file) {
			$fragment = (json_decode((string)file_get_contents($file), true)['components']['schemas'][$slug] ?? null);
			if (is_array($fragment) === true) {
				$merged = array_replace_recursive($merged, $fragment);
			}
		}

		return $merged;
	}//end mergedSchema()

	/**
	 * The service over the real RoutingService and an in-memory OpenRegister.
	 *
	 * @return LeadRotationService
	 */
	private function service(): LeadRotationService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => 'pipelinq',
				'lead_schema' => 'lead',
				'skill_schema' => 'skill',
				'agentProfile_schema' => 'agentProfile',
				LeadRotationService::ENABLED_KEY => $this->enabled,
				default => $default,
			}
		);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$filters = ($config['filters'] ?? []);
				return match ($filters['schema'] ?? '') {
					'skill' => [self::entity(uuid: 'skill-solar', payload: ['title' => 'Solar', 'categories' => ['solar'], 'isActive' => true])],
					'agentProfile' => array_map(static fn (array $profile): ObjectEntity => self::entity(uuid: 'profile-'.$profile['userId'], payload: $profile), array_values($this->profiles)),
					'lead' => $this->leadsFor(userId: (string)($filters['assignee'] ?? '')),
					default => [],
				};
			}
		);

		$tickets = $this->createMock(TicketService::class);
		$tickets->method('findByType')->willReturn([]);
		$routing = new RoutingService(appConfig: $appConfig, ticketService: $tickets, logger: $this->createMock(LoggerInterface::class), objectService: $objects);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable(self::NOW));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new LeadRotationService(
			appConfig: $appConfig,
			routing: $routing,
			objectService: $objects,
			time: $time,
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * The stored leads of one assignee, as entities; one array row and one row of another assignee prove the re-check.
	 *
	 * @param string $userId The assignee filter.
	 *
	 * @return array<int, mixed>
	 */
	private function leadsFor(string $userId): array {
		if ($this->leadsFail === true) {
			throw new RuntimeException('OpenRegister is down');
		}

		$rows = [];
		foreach ($this->leads as $index => $lead) {
			if (($lead['assignee'] ?? '') === $userId) {
				$rows[] = self::entity(uuid: 'lead-'.$index, payload: $lead);
			}
		}

		$rows[] = ['assignee' => 'someone-else', 'assignedAt' => self::NOW];
		$rows[] = 'not a row';
		return $rows;
	}//end leadsFor()

	/**
	 * Wrap a payload in an ObjectEntity, the shape findAll() returns.
	 *
	 * @param string               $uuid    The object UUID.
	 * @param array<string, mixed> $payload The object payload.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $uuid, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($payload);
		return $entity;
	}//end entity()
}//end class
