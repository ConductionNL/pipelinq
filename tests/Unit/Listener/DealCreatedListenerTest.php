<?php

/**
 * Unit tests for DealCreatedListener's deferred work: the forecast default and the lead rotation.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
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

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Listener\DealCreatedListener;
use OCA\Pipelinq\Service\ForecastDealService;
use OCA\Pipelinq\Service\LeadRotationService;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\SystemServiceAccount;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/RecordingDeferralService.php';

/**
 * A new lead gets its forecast default and, when rotation is on, its colleague, in one write.
 *
 * @covers \OCA\Pipelinq\Listener\DealCreatedListener
 * @uses   \OCA\Pipelinq\Service\SystemServiceAccount
 * @uses   \OCA\Pipelinq\Service\ForecastDealService
 * @uses   \OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph
 */
class DealCreatedListenerTest extends TestCase {

	/**
	 * The stored lead the deferred work re-reads.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $stored = null;

	/**
	 * Every save: the payload and the user it ran as.
	 *
	 * @var array<int, array{object: array<string, mixed>, actor: string|null}>
	 */
	private array $saves = [];

	/**
	 * The user id the session holds; null is nobody, as for a web form's lead.
	 *
	 * @var string|null
	 */
	private ?string $active = null;

	/**
	 * Rotation off: only the forecast default is written.
	 *
	 * @return void
	 */
	public function testWithRotationOffOnlyTheForecastDefaultIsWritten(): void {
		$this->stored = ['title' => 'Zonnepanelen', 'category' => 'solar'];
		$rotation = $this->rotation(enabled: false);
		$rotation->expects($this->never())->method('assign');

		$this->listener(rotation: $rotation)->runDeferredWork(['uuid' => 'lead-1']);

		$this->assertCount(1, $this->saves);
		$this->assertSame('pipeline', $this->saves[0]['object']['forecast_category']);
		$this->assertArrayNotHasKey('assignee', $this->saves[0]['object']);
	}//end testWithRotationOffOnlyTheForecastDefaultIsWritten()

	/**
	 * Spec scenario: rotation on, a lead without assignee gets one in the same write, as the system account when nobody is signed in.
	 *
	 * @return void
	 */
	public function testWithRotationOnTheLeadIsAssignedInTheSameWrite(): void {
		$this->stored = ['title' => 'Zonnepanelen', 'category' => 'solar', 'forecast_category' => 'pipeline'];
		$rotation = $this->rotation(enabled: true);
		$rotation->expects($this->once())->method('assign')->with($this->callback(static fn (array $lead): bool => $lead['category'] === 'solar' && isset($lead['assignee']) === false))
			->willReturn(['assignee' => 'anna', 'assignmentReason' => 'Rotation: fewest leads in 30 days (0, no cap), skill Solar.', 'assignedAt' => '2026-10-15T10:00:00+00:00']);

		$this->listener(rotation: $rotation)->runDeferredWork(['uuid' => 'lead-1']);

		$this->assertCount(1, $this->saves);
		$this->assertSame('anna', $this->saves[0]['object']['assignee']);
		$this->assertSame('pipeline', $this->saves[0]['object']['forecast_category']);
		$this->assertSame(SystemServiceAccount::USER_ID, $this->saves[0]['actor']);
		$this->assertNull($this->active, 'nobody is signed in again afterwards');
	}//end testWithRotationOnTheLeadIsAssignedInTheSameWrite()

	/**
	 * A signed-in colleague's lead is completed as that colleague.
	 *
	 * @return void
	 */
	public function testASignedInColleaguesLeadIsWrittenAsThem(): void {
		$this->active = 'carla';
		$this->stored = ['title' => 'Zonnepanelen'];

		$this->listener(rotation: $this->rotation(enabled: false))->runDeferredWork(['uuid' => 'lead-1']);

		$this->assertSame('carla', $this->saves[0]['actor']);
	}//end testASignedInColleaguesLeadIsWrittenAsThem()

	/**
	 * A redelivered job finds the reason already written and assigns nothing twice; nothing changed means no write.
	 *
	 * @return void
	 */
	public function testARedeliveryWritesNothing(): void {
		$this->stored = ['title' => 'Zonnepanelen', 'forecast_category' => 'pipeline', 'assignmentReason' => 'Rotation found no eligible colleague.'];
		$rotation = $this->rotation(enabled: true);
		$rotation->expects($this->never())->method('assign');

		$this->listener(rotation: $rotation)->runDeferredWork(['uuid' => 'lead-1']);

		$this->assertSame([], $this->saves);
	}//end testARedeliveryWritesNothing()

	/**
	 * A lead that is gone is left alone.
	 *
	 * @return void
	 */
	public function testAMissingLeadIsLeftAlone(): void {
		$this->stored = null;

		$this->listener(rotation: $this->rotation(enabled: true))->runDeferredWork(['uuid' => 'lead-1']);
		$this->listener(rotation: $this->rotation(enabled: true))->runDeferredWork([]);

		$this->assertSame([], $this->saves);
	}//end testAMissingLeadIsLeftAlone()

	/**
	 * A rotation double with the admin switch set.
	 *
	 * @param bool $enabled The switch.
	 *
	 * @return LeadRotationService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function rotation(bool $enabled): LeadRotationService {
		$rotation = $this->createMock(LeadRotationService::class);
		$rotation->method('isEnabled')->willReturn($enabled);
		return $rotation;
	}//end rotation()

	/**
	 * The listener over an in-memory OpenRegister holding one lead.
	 *
	 * @param LeadRotationService $rotation The rotation.
	 *
	 * @return DealCreatedListener
	 */
	private function listener(LeadRotationService $rotation): DealCreatedListener {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => 'pipelinq',
				'lead_schema' => 'lead',
				default => $default,
			}
		);

		$objects = new class($this) {
			/**
			 * @param DealCreatedListenerTest $test The test holding the stored lead.
			 */
			public function __construct(private DealCreatedListenerTest $test) {
			}

			/**
			 * @return ObjectEntity|null The stored lead.
			 */
			public function find(string $id, $register = null, $schema = null): ?ObjectEntity {
				return $this->test->storedEntity();
			}

			/**
			 * @param array<string, mixed> $object The payload.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, array $extend = [], $register = null, $schema = null, ?string $uuid = null): array {
				$this->test->recordSave(object: $object);
				return $object;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): IUser => $this->user(uid: $uid));
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->active === null ? null : $this->user(uid: $this->active));
		$session->method('setVolatileActiveUser')->willReturnCallback(function (?IUser $user): void {
			$this->active = $user?->getUID();
		});

		return new DealCreatedListener(
			schemaMapService: $this->createMock(SchemaMapService::class),
			dealService: new ForecastDealService($this->createMock(IAppConfig::class)),
			container: $container,
			appConfig: $appConfig,
			deferral: new RecordingDeferralService(),
			logger: $this->createMock(LoggerInterface::class),
			rotation: $rotation,
			account: new SystemServiceAccount(userManager: $users, userSession: $session, random: $this->createMock(ISecureRandom::class)),
		);
	}//end listener()

	/**
	 * The stored lead as an entity, or null.
	 *
	 * @return ObjectEntity|null
	 */
	public function storedEntity(): ?ObjectEntity {
		if ($this->stored === null) {
			return null;
		}

		$entity = new ObjectEntity();
		$entity->setUuid('lead-1');
		$entity->setObject($this->stored);
		return $entity;
	}//end storedEntity()

	/**
	 * Record a save with the user it ran as.
	 *
	 * @param array<string, mixed> $object The payload.
	 *
	 * @return void
	 */
	public function recordSave(array $object): void {
		$this->saves[] = ['object' => $object, 'actor' => $this->active];
	}//end recordSave()

	/**
	 * A user double.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()
}//end class
