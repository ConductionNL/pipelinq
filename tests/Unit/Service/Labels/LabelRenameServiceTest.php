<?php

/**
 * Renaming a label rewrites it on the items that carry it.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Labels
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#requirement-an-administrator-manages-the-labels-on-beheer-marketing
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Labels;

use OCA\Pipelinq\BackgroundJob\LabelRenameJob;
use OCA\Pipelinq\Service\Labels\LabelRenameException;
use OCA\Pipelinq\Service\Labels\LabelRenameService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Pipelinq\Service\Labels\LabelRenameService
 */
class LabelRenameServiceTest extends TestCase {

	/**
	 * Objects by schema slug, keyed by id: the fake OpenRegister's store.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Every write the fake received, as [schema, id, payload, rbac].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>, 3: bool}>
	 */
	private array $writes = [];

	/**
	 * The session's user id; null for no session.
	 *
	 * @var string|null
	 */
	private ?string $userId = 'admin';

	/**
	 * Whether the session's user is an administrator.
	 *
	 * @var boolean
	 */
	private bool $isAdmin = true;

	/**
	 * The job list mock.
	 *
	 * @var IJobList&MockObject
	 */
	private IJobList $jobList;

	/**
	 * A service over the fake store.
	 *
	 * @return LabelRenameService
	 */
	private function service(): LabelRenameService {
		$objectService = new class($this) {
			/**
			 * @param LabelRenameServiceTest $test The test holding the store.
			 */
			public function __construct(private LabelRenameServiceTest $test) {
			}

			/**
			 * @param string $id The id.
			 * @param mixed ...$args Register, schema and flags.
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, mixed ...$args): ?array {
				return $this->test->storeFind(schema: (string)$args['schema'], id: $id);
			}

			/**
			 * @param array<string, mixed> $config The query.
			 * @param mixed ...$args The flags.
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config, mixed ...$args): array {
				return $this->test->storeFindAll(config: $config);
			}

			/**
			 * @param array<string, mixed> $object The payload.
			 * @param mixed ...$args Register, schema, uuid and flags.
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, mixed ...$args): array {
				return $this->test->storeSave(schema: (string)$args['schema'], id: (string)$args['uuid'], payload: $object, rbac: (bool)($args['_rbac'] ?? true));
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnArgument(2);

		$userSession = $this->createMock(IUserSession::class);
		$user = null;
		if ($this->userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($this->userId);
		}

		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $uid === $this->userId && $this->isAdmin);

		return new LabelRenameService(
			container: $container,
			appConfig: $appConfig,
			userSession: $userSession,
			groupManager: $groupManager,
			jobList: $this->jobList,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * Seed the store: five labels, five leads on "ondernemers", one client and one article.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->jobList = $this->createMock(IJobList::class);
		foreach (['afval', 'duurzaam', 'parkeren', 'ondernemers', 'evenementen'] as $i => $name) {
			$this->store['label']['l'.$i] = ['id' => 'l'.$i, 'name' => $name];
		}

		for ($i = 1; $i <= 5; $i++) {
			$this->store['lead']['lead'.$i] = ['id' => 'lead'.$i, 'title' => 'Lead '.$i, 'labels' => ['ondernemers', 'duurzaam']];
		}

		$this->store['lead']['lead6'] = ['id' => 'lead6', 'title' => 'Lead 6', 'labels' => ['afval']];
		$this->store['client']['c1'] = ['id' => 'c1', 'name' => 'Bakkerij Smit', 'labels' => ['ondernemers']];
		$this->store['article']['a1'] = ['id' => 'a1', 'title' => 'Ondernemersfonds', 'tags' => ['ondernemers', 'ondernemers centrum']];
	}//end setUp()

	/**
	 * Five leads labelled "ondernemers" carry "ondernemers centrum" after the rename.
	 *
	 * @return void
	 */
	public function testARenamedLabelFollowsItsItems(): void {
		$result = $this->service()->rename(labelId: 'l3', name: 'ondernemers centrum');

		$this->assertSame('ondernemers centrum', $this->store['label']['l3']['name']);
		for ($i = 1; $i <= 5; $i++) {
			$this->assertSame(['ondernemers centrum', 'duurzaam'], $this->store['lead']['lead'.$i]['labels']);
		}

		$this->assertSame(['afval'], $this->store['lead']['lead6']['labels']);
		$this->assertSame(['ondernemers centrum'], $this->store['client']['c1']['labels']);
		$this->assertSame(['ondernemers centrum'], $this->store['article']['a1']['tags'], 'an article on both names keeps one');
		$this->assertSame(['id' => 'l3', 'name' => 'ondernemers centrum', 'rewritten' => 7, 'queued' => false], $result);
	}//end testARenamedLabelFollowsItsItems()

	/**
	 * Somebody who is not an administrator is refused before anything is written.
	 *
	 * @return void
	 */
	public function testANonAdministratorIsRefusedBeforeAnyWrite(): void {
		$this->isAdmin = false;
		$service = $this->service();

		try {
			$service->rename(labelId: 'l3', name: 'ondernemers centrum');
			$this->fail('the rename went through');
		} catch (LabelRenameException $e) {
			$this->assertSame(403, $e->getStatus());
		}

		$this->assertSame([], $this->writes);
	}//end testANonAdministratorIsRefusedBeforeAnyWrite()

	/**
	 * Without a session the rename is refused too.
	 *
	 * @return void
	 */
	public function testNoSessionIsRefused(): void {
		$this->userId = null;
		$this->expectExceptionObject(new LabelRenameException(message: 'Only an administrator can rename a label.', status: 403));
		$this->service()->rename(labelId: 'l3', name: 'x');
	}//end testNoSessionIsRefused()

	/**
	 * A name another label already has, in any case, is refused.
	 *
	 * @return void
	 */
	public function testANameOnTheListIsRefused(): void {
		try {
			$this->service()->rename(labelId: 'l3', name: ' Duurzaam ');
			$this->fail('the rename went through');
		} catch (LabelRenameException $e) {
			$this->assertSame(409, $e->getStatus());
		}

		$this->assertSame([], $this->writes);
	}//end testANameOnTheListIsRefused()

	/**
	 * An empty name and an unknown label are refused.
	 *
	 * @return void
	 */
	public function testAnEmptyNameAndAnUnknownLabelAreRefused(): void {
		$service = $this->service();
		foreach ([['l3', '  ', 400], ['nope', 'nieuw', 404]] as [$id, $name, $status]) {
			try {
				$service->rename(labelId: $id, name: $name);
				$this->fail('the rename went through');
			} catch (LabelRenameException $e) {
				$this->assertSame($status, $e->getStatus());
			}
		}

		$this->assertSame([], $this->writes);
	}//end testAnEmptyNameAndAnUnknownLabelAreRefused()

	/**
	 * A change in case only renames the label and its items.
	 *
	 * @return void
	 */
	public function testACaseChangeRenames(): void {
		$result = $this->service()->rename(labelId: 'l1', name: 'Duurzaam');

		$this->assertSame('Duurzaam', $this->store['label']['l1']['name']);
		$this->assertSame(['ondernemers', 'Duurzaam'], $this->store['lead']['lead1']['labels']);
		$this->assertSame(5, $result['rewritten']);
	}//end testACaseChangeRenames()

	/**
	 * The writes go through OpenRegister as the caller, with its access check on.
	 *
	 * @return void
	 */
	public function testTheWritesKeepOpenRegistersAccessCheck(): void {
		$this->service()->rename(labelId: 'l3', name: 'ondernemers centrum');

		$this->assertNotSame([], $this->writes);
		foreach ($this->writes as [$schema, $id, , $rbac]) {
			$this->assertTrue($rbac, "{$schema} {$id} was written past the access check");
		}
	}//end testTheWritesKeepOpenRegistersAccessCheck()

	/**
	 * Past 200 items the rest goes to a background job for the same administrator.
	 *
	 * @return void
	 */
	public function testPastTwoHundredItemsTheRestIsQueued(): void {
		for ($i = 7; $i <= 260; $i++) {
			$this->store['lead']['lead'.$i] = ['id' => 'lead'.$i, 'labels' => ['ondernemers']];
		}

		$this->jobList->expects($this->once())->method('add')->with(
			LabelRenameJob::class,
			['from' => 'ondernemers', 'to' => 'ondernemers centrum', 'userId' => 'admin']
		);

		$result = $this->service()->rename(labelId: 'l3', name: 'ondernemers centrum');

		$this->assertSame(200, $result['rewritten']);
		$this->assertTrue($result['queued']);
	}//end testPastTwoHundredItemsTheRestIsQueued()

	/**
	 * The job's pass rewrites every remaining item for an administrator, and nothing for anybody else.
	 *
	 * @return void
	 */
	public function testTheQueuedPassRewritesTheRestForAnAdministratorOnly(): void {
		for ($i = 7; $i <= 260; $i++) {
			$this->store['lead']['lead'.$i] = ['id' => 'lead'.$i, 'labels' => ['ondernemers']];
		}

		$this->isAdmin = false;
		$this->assertSame(0, $this->service()->rewriteAll(from: 'ondernemers', to: 'ondernemers centrum', userId: 'admin'));
		$this->assertSame([], $this->writes);

		$this->isAdmin = true;
		$this->assertSame(261, $this->service()->rewriteAll(from: 'ondernemers', to: 'ondernemers centrum', userId: 'admin'));
		$this->assertSame(['ondernemers centrum'], $this->store['lead']['lead260']['labels']);
	}//end testTheQueuedPassRewritesTheRestForAnAdministratorOnly()

	/**
	 * Fake find.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id The id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function storeFind(string $schema, string $id): ?array {
		return ($this->store[$schema][$id] ?? null);
	}//end storeFind()

	/**
	 * Fake findAll: register and schema in the filters, one array-contains filter, a limit.
	 *
	 * @param array<string, mixed> $config The query.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function storeFindAll(array $config): array {
		$filters = $config['filters'];
		$schema = (string)$filters['schema'];
		unset($filters['register'], $filters['schema']);

		$rows = [];
		foreach (($this->store[$schema] ?? []) as $row) {
			foreach ($filters as $field => $value) {
				if (in_array($value, (array)($row[$field] ?? []), true) === false) {
					continue 2;
				}
			}

			$rows[] = $row;
		}

		return array_slice($rows, 0, (int)($config['limit'] ?? 1000));
	}//end storeFindAll()

	/**
	 * Fake save.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id The id.
	 * @param array<string, mixed> $payload The payload.
	 * @param boolean $rbac Whether the access check was on.
	 *
	 * @return array<string, mixed>
	 */
	public function storeSave(string $schema, string $id, array $payload, bool $rbac): array {
		$this->writes[] = [$schema, $id, $payload, $rbac];
		$this->store[$schema][$id] = $payload;
		return $payload;
	}//end storeSave()
}//end class
