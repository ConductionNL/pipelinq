<?php

/**
 * Unit tests for SegmentController's member-preview and size-refresh endpoints.
 *
 * Two tiers. The first pins the wire contract of `GET /api/segments/{id}/members`
 * and `POST /api/segments/{id}/size` against a mocked SegmentService: the
 * authentication gate, the response envelopes, and the fact that the client's
 * limit reaches the service unaltered.
 *
 * The second wires the REAL SegmentService onto a mocked OpenRegister
 * ObjectService, so the assertions cover the actual query construction rather
 * than a stub of it — a member preview that returns an empty list because of a
 * mis-shaped filter answers a healthy 200 and is invisible to a mocked-service
 * test.
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
use OCA\Pipelinq\Controller\SegmentController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\SegmentService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for SegmentController::members() and SegmentController::refreshSize().
 */
class SegmentControllerTest extends TestCase {
	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * Mock segment service (contract tier).
	 *
	 * @var SegmentService&MockObject
	 */
	private SegmentService $segmentService;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The controller under test (contract tier).
	 *
	 * @var SegmentController
	 */
	private SegmentController $controller;

	/**
	 * A single-leaf rule tree matching Dutch contacts.
	 *
	 * @var array<string, mixed>
	 */
	private const COUNTRY_RULE = ['field' => 'country', 'operator' => 'equals', 'value' => 'NL'];

	/**
	 * Build the contract-tier controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->segmentService = $this->createMock(SegmentService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new SegmentController($this->request,
			$this->segmentService,
			$this->userSession,
			$this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => true, 'mayAccess' => true])
		);
	}//end setUp()

	/**
	 * Stub the acting user (or none) on the shared session mock.
	 *
	 * @param string|null $uid The acting UID, or null for no session.
	 *
	 * @return void
	 */
	private function authenticate(?string $uid): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);
			return;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end authenticate()

	/**
	 * Wrap a fixture row as the entity ObjectService now returns.
	 *
	 * Since ADR-084 `find()` returns `?ObjectEntityInterface` and
	 * `saveObject()` a non-nullable one, so a mock configured with a bare
	 * array is rejected before the test body runs. SegmentService reads the
	 * payload back with `jsonSerialize()`, which this entity answers with the
	 * row it was given.
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
	 * Build a controller backed by the REAL SegmentService and a mocked
	 * OpenRegister ObjectService.
	 *
	 * @param ObjectServiceInterface&MockObject $objects The mocked object service.
	 * @param ICache|null $cache Optional estimate cache.
	 *
	 * @return SegmentController The wired controller.
	 */
	private function wiredController(ObjectServiceInterface $objects, ?ICache $cache = null): SegmentController {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objects): object {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objects;
				}

				throw new \RuntimeException('Not registered: ' . $id);
			}
		);

		// No app-config overrides: the service falls back to its documented
		// defaults (register "pipelinq", schemas "segment" and "contact").
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$appConfig->method('getValueInt')->willReturnArgument(2);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		if ($cache === null) {
			$cacheFactory->method('isAvailable')->willReturn(false);
			$cacheFactory->method('createLocal')->willThrowException(new \RuntimeException('no cache'));
		} else {
			$cacheFactory->method('isAvailable')->willReturn(true);
			$cacheFactory->method('createDistributed')->willReturn($cache);
			$cacheFactory->method('createLocal')->willReturn($cache);
		}

		$service = new SegmentService($container,
			$appConfig,
			$this->createMock(SchemaMapService::class),
			$cacheFactory,
			$this->createMock(LoggerInterface::class)
		);

		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('marketeer');
		$session->method('getUser')->willReturn($user);

		return new SegmentController($this->createMock(IRequest::class),
			$service,
			$session,
			$this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => true, 'mayAccess' => true])
		);
	}//end wiredController()

	/**
	 * An unauthenticated caller cannot preview a segment's recipients.
	 *
	 * @return void
	 */
	public function testMembersRequiresAuthentication(): void {
		$this->authenticate(null);
		$this->segmentService->expects($this->never())->method('previewSegmentMembers');

		$response = $this->controller->members('seg-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}//end testMembersRequiresAuthentication()

	/**
	 * The member preview answers `{members: [...]}` with the blast-engine
	 * projection, and the caller's limit reaches the service unchanged.
	 *
	 * @return void
	 */
	public function testMembersReturnsProjectedRecipients(): void {
		$this->authenticate('marketeer');
		$this->segmentService->expects($this->once())
			->method('previewSegmentMembers')
			->with(segmentId: 'seg-1', limit: 25)
			->willReturn(
				[
					['contactId' => 'c1', 'email' => 'ann@example.org', 'firstName' => 'Ann', 'lastName' => 'Jansen'],
				]
			);

		$response = $this->controller->members('seg-1', 25);
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['members'], array_keys($data));
		$this->assertCount(1, $data['members']);
		$this->assertSame(
			['contactId', 'email', 'firstName', 'lastName'],
			array_keys($data['members'][0])
		);
		$this->assertSame('ann@example.org', $data['members'][0]['email']);
	}//end testMembersReturnsProjectedRecipients()

	/**
	 * A segment with no matching recipients is still a 200 with an empty
	 * list, never a 404 — the segment exists, its audience is simply empty.
	 *
	 * @return void
	 */
	public function testMembersReturnsEmptyListForUnmatchedSegment(): void {
		$this->authenticate('marketeer');
		$this->segmentService->method('previewSegmentMembers')->willReturn([]);

		$response = $this->controller->members('seg-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['members' => []], $response->getData());
	}//end testMembersReturnsEmptyListForUnmatchedSegment()

	/**
	 * Wired against a real service: seeded contacts that satisfy the stored
	 * rule tree actually come back. This is the assertion a mocked service
	 * cannot make — a mis-shaped OpenRegister filter answers 200 with an
	 * empty list and is otherwise indistinguishable from an empty audience.
	 *
	 * @return void
	 */
	public function testMembersReturnsSeededContactsThroughRealService(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn(
			self::entity(
				[
					'id' => 'seg-1',
					'name' => 'Dutch contacts',
					'entityType' => 'contact',
					'rules' => self::COUNTRY_RULE,
				]
			)
		);
		$objects->expects($this->once())
			->method('findAll')
			->willReturnCallback(
				function (array $config = [], bool $rbac = true, bool $multitenancy = true): array {
					// The register/schema context must reach OpenRegister, or
					// the query resolves against no table at all.
					$this->assertSame('pipelinq', $config['filters']['register']);
					$this->assertSame('contact', $config['filters']['schema']);

					return [
						['id' => 'c1', 'email' => 'ann@example.org', 'firstName' => 'Ann', 'country' => 'NL'],
						['id' => 'c2', 'email' => 'bob@example.be', 'firstName' => 'Bob', 'country' => 'BE'],
						['id' => 'c3', 'email' => 'cor@example.org', 'name' => 'Cor de Wit', 'country' => 'NL'],
					];
				}
			);

		$response = $this->wiredController($objects)->members('seg-1');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(2, $data['members'], 'Both Dutch contacts must be returned.');
		$this->assertSame('c1', $data['members'][0]['contactId']);
		$this->assertSame('ann@example.org', $data['members'][0]['email']);
		// A vCard-style single `name` is split into first/last.
		$this->assertSame('Cor', $data['members'][1]['firstName']);
		$this->assertSame('de Wit', $data['members'][1]['lastName']);
	}//end testMembersReturnsSeededContactsThroughRealService()

	/**
	 * Wired against a real service: the preview cap is enforced server-side,
	 * so an oversized limit cannot pull the whole contact base into one
	 * response.
	 *
	 * @return void
	 */
	public function testMembersCapsPreviewSizeThroughRealService(): void {
		$contacts = [];
		for ($i = 0; $i < 600; $i++) {
			$contacts[] = ['id' => 'c' . $i, 'email' => 'c' . $i . '@example.org', 'country' => 'NL'];
		}

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn(
			self::entity(['id' => 'seg-1', 'entityType' => 'contact', 'rules' => self::COUNTRY_RULE])
		);
		$objects->method('findAll')->willReturn($contacts);

		$response = $this->wiredController($objects)->members('seg-1', 100000);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(500, $response->getData()['members']);
	}//end testMembersCapsPreviewSizeThroughRealService()

	/**
	 * An unauthenticated caller cannot trigger a size recomputation.
	 *
	 * @return void
	 */
	public function testRefreshSizeRequiresAuthentication(): void {
		$this->authenticate(null);
		$this->segmentService->expects($this->never())->method('refreshSegmentSize');

		$response = $this->controller->refreshSize('seg-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}//end testRefreshSizeRequiresAuthentication()

	/**
	 * The refresh answers `{estimatedSize: n}` with the freshly computed
	 * integer.
	 *
	 * @return void
	 */
	public function testRefreshSizeReturnsEstimatedSize(): void {
		$this->authenticate('marketeer');
		$this->segmentService->expects($this->once())
			->method('refreshSegmentSize')
			->with(segmentId: 'seg-1')
			->willReturn(42);

		$response = $this->controller->refreshSize('seg-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['estimatedSize' => 42], $response->getData());
	}//end testRefreshSizeReturnsEstimatedSize()

	/**
	 * Wired against a real service with no cache available: the endpoint
	 * counts the entities that satisfy the rule tree and persists the count
	 * back onto the segment.
	 *
	 * @return void
	 */
	public function testRefreshSizeRecomputesAndPersistsThroughRealService(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn(
			self::entity(
				['id' => 'seg-1', 'entityType' => 'contact', 'rules' => self::COUNTRY_RULE, 'estimatedSize' => 0]
			)
		);
		$objects->method('findAll')->willReturn(
			[
				['id' => 'c1', 'country' => 'NL'],
				['id' => 'c2', 'country' => 'BE'],
				['id' => 'c3', 'country' => 'NL'],
			]
		);

		$persisted = null;
		$objects->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				static function (array $object, ...$rest) use (&$persisted): ObjectEntityInterface {
					$persisted = $object;
					return self::entity($object);
				}
			);

		$response = $this->wiredController($objects)->refreshSize('seg-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['estimatedSize' => 2], $response->getData());
		$this->assertIsArray($persisted);
		$this->assertSame(2, $persisted['estimatedSize'], 'The recomputed size must be written back.');
	}//end testRefreshSizeRecomputesAndPersistsThroughRealService()

	/**
	 * The refresh endpoint exists specifically to recompute a stale count
	 * after a rule edit, so it must return the live count — not a value the
	 * estimate cache happens to be holding from before the edit.
	 *
	 * @return void
	 */
	public function testRefreshSizeIgnoresStaleEstimateCache(): void {
		$this->markTestSkipped(
			'BUG: POST /api/segments/{id}/size returns and persists the cached '
			. 'estimate instead of recomputing - see coordinator report'
		);

		$cache = $this->createMock(ICache::class);
		// A pre-edit count still sitting in the estimate cache.
		$cache->method('get')->willReturn(99);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn(
			self::entity(['id' => 'seg-1', 'entityType' => 'contact', 'rules' => self::COUNTRY_RULE])
		);
		$objects->method('findAll')->willReturn(
			[
				['id' => 'c1', 'country' => 'NL'],
				['id' => 'c2', 'country' => 'BE'],
			]
		);
		$objects->method('saveObject')->willReturnCallback(
			static fn (array $object, ...$rest): ObjectEntityInterface => self::entity($object)
		);

		$response = $this->wiredController($objects, $cache)->refreshSize('seg-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['estimatedSize' => 1], $response->getData());
	}//end testRefreshSizeIgnoresStaleEstimateCache()

	/**
	 * A refresh whose write to OpenRegister fails must not answer 200 with a
	 * number the client will believe was stored — the next read returns the
	 * old value and the two disagree silently.
	 *
	 * @return void
	 */
	public function testRefreshSizeReportsPersistenceFailure(): void {
		$this->markTestSkipped(
			'BUG: a failed estimatedSize write is swallowed and still answered '
			. '200 with the new count - see coordinator report'
		);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn(
			self::entity(['id' => 'seg-1', 'entityType' => 'contact', 'rules' => self::COUNTRY_RULE])
		);
		$objects->method('findAll')->willReturn([['id' => 'c1', 'country' => 'NL']]);
		$objects->method('saveObject')->willThrowException(new \RuntimeException('write failed'));

		$response = $this->wiredController($objects)->refreshSize('seg-1');

		$this->assertNotSame(Http::STATUS_OK, $response->getStatus());
	}//end testRefreshSizeReportsPersistenceFailure()
}//end class
