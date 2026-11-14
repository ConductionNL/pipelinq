<?php

/**
 * Pins the contact moment a worklog becomes, and the refusal without configuration.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-support-manual-entries
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\Pipelinq\Service\ActivityItemNormalizer;
use OCA\Pipelinq\Service\ActivityTimelineReader;
use OCA\Pipelinq\Service\ActivityTimelineService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use OCP\IDateTimeFormatter;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The saved payload per entity type and data shape, and the answer it normalises to.
 */
class ActivityWorklogGoldenTest extends TestCase {

	/**
	 * Saved payloads, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $saved = [];

	/**
	 * A service over a recording ticket service.
	 *
	 * @param string      $register The configured register.
	 * @param string|null $uid      The signed-in uid.
	 *
	 * @return ActivityTimelineService
	 */
	private function service(string $register, ?string $uid): ActivityTimelineService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'register' ? $register : $default
		);
		$tickets = $this->createMock(TicketService::class);
		$tickets->method('getSchemaId')->willReturn('ticket-s');
		$tickets->method('save')->willReturnCallback(
			function (string $type, array $payload): ObjectEntityInterface {
				$this->saved[] = [$type, $payload];
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn($payload);
				$entity->method('getUuid')->willReturn('cm-'.count($this->saved));
				return $entity;
			}
		);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$formatter = $this->createMock(IDateTimeFormatter::class);
		$formatter->method('formatDateTime')->willReturn('formatted');
		return new ActivityTimelineService(
			appConfig: $config,
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
			ticketService: $tickets,
			reader: $this->createMock(ActivityTimelineReader::class),
			itemNormalizer: new ActivityItemNormalizer(userManager: $this->createMock(IUserManager::class), dateTimeFormatter: $formatter),
		);
	}//end service()

	/**
	 * Each worklog saves exactly this contact moment.
	 *
	 * @return void
	 */
	public function testSavedPayloadsAreExact(): void {
		$service = $this->service(register: 'reg-1', uid: 'maria');
		$client  = $service->createWorklog(entityType: 'client', entityId: 'cl-1', data: ['duration' => 'PT30M', 'description' => 'Belde terug', 'date' => '2026-10-01T09:00:00+00:00', 'agent' => 'someone-else']);
		$service->createWorklog(entityType: 'request', entityId: 'rq-1', data: ['duration' => 45, 'description' => '', 'date' => '2026-10-02T09:00:00+00:00']);
		$service->createWorklog(entityType: 'lead', entityId: 'ld-1', data: ['duration' => [], 'description' => null, 'date' => '2026-10-03T09:00:00+00:00']);
		$this->service(register: 'reg-1', uid: null)->createWorklog(entityType: 'client', entityId: 'cl-2', data: []);

		$base = ['channel' => 'worklog', 'direction' => 'internal'];
		$this->assertSame(
			[
				[TicketService::TYPE_CONTACTMOMENT, $base + ['description' => 'Belde terug', 'duration' => 'PT30M', 'occurredAt' => '2026-10-01T09:00:00+00:00', 'assignee' => 'maria', 'client' => 'cl-1']],
				[TicketService::TYPE_CONTACTMOMENT, $base + ['description' => '', 'duration' => '45', 'occurredAt' => '2026-10-02T09:00:00+00:00', 'assignee' => 'maria', 'parentTicket' => 'rq-1']],
				[TicketService::TYPE_CONTACTMOMENT, $base + ['description' => '', 'duration' => '', 'occurredAt' => '2026-10-03T09:00:00+00:00', 'assignee' => 'maria']],
			],
			array_slice($this->saved, 0, 3)
		);
		$last = $this->saved[3][1];
		$this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $last['occurredAt']));
		unset($last['occurredAt']);
		$this->assertSame($base + ['description' => '', 'duration' => '', 'assignee' => '', 'client' => 'cl-2'], $last);
		$this->assertSame('cm-1', $client['id'] ?? null);
	}//end testSavedPayloadsAreExact()

	/**
	 * Without a register nothing is saved and the same refusal is thrown.
	 *
	 * @return void
	 */
	public function testUnconfiguredIsRefused(): void {
		try {
			$this->service(register: '', uid: 'maria')->createWorklog(entityType: 'client', entityId: 'cl-1', data: []);
			$this->fail('expected a refusal');
		} catch (RuntimeException $e) {
			$this->assertSame('Contactmoment register or schema not configured.', $e->getMessage());
		}

		$this->assertSame([], $this->saved);
	}//end testUnconfiguredIsRefused()
}//end class
