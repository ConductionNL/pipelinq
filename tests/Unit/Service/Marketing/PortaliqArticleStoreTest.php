<?php

/**
 * Unit tests for PortaliqArticleStore.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-pipelinq-reads-marketing-articles-from-portaliq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\PortaliqArticleStore;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryRegisters;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Reads and writes stay in register `portaliq`, schema `article`, and only
 * while portaliq is installed.
 */
class PortaliqArticleStoreTest extends TestCase {

	/**
	 * The in-memory registers.
	 *
	 * @var InMemoryRegisters
	 */
	private InMemoryRegisters $objects;

	/**
	 * How often the container was asked for the ObjectService.
	 *
	 * @var int
	 */
	private int $lookups = 0;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new InMemoryRegisters();
		$this->lookups = 0;
	}//end setUp()

	/**
	 * Without portaliq nothing is read and OpenRegister is never asked.
	 *
	 * @return void
	 */
	public function testWithoutPortaliqNothingIsReadOrWritten(): void {
		$this->objects->put(register: 'portaliq', schema: 'article', row: ['uuid' => 'a1', 'status' => 'published']);
		$store = $this->store(installed: false);

		$this->assertFalse($store->isAvailable());
		$this->assertFalse($store->isReady());
		$this->assertNull($store->find(identity: 'a1'));
		$this->assertSame([], $store->findAll());
		$this->assertNull($store->create(payload: ['title' => 'X']));
		$this->assertSame(0, $this->lookups);
		$this->assertSame([], $this->objects->saves);
	}//end testWithoutPortaliqNothingIsReadOrWritten()

	/**
	 * find() answers by id, by slug and by legacyRef, from portaliq only.
	 *
	 * @return void
	 */
	public function testFindAnswersByIdSlugAndLegacyRef(): void {
		$this->objects->put(register: 'portaliq', schema: 'article', row: ['uuid' => 'a1', 'slug' => 'eerste', 'legacyRef' => 'pipelinq:old-1']);
		$this->objects->put(register: 'pipelinq', schema: 'article', row: ['uuid' => 'p1', 'slug' => 'pipelinq-only']);
		$store = $this->store(installed: true);

		$this->assertSame('a1', $store->find(identity: 'a1')['uuid']);
		$this->assertSame('a1', $store->find(identity: ' eerste ')['uuid']);
		$this->assertSame('a1', $store->find(identity: 'old-1')['uuid']);
		$this->assertSame('a1', $store->find(identity: 'pipelinq:old-1')['uuid']);
		$this->assertNull($store->find(identity: 'p1'));
		$this->assertNull($store->find(identity: 'pipelinq-only'));
		$this->assertNull($store->find(identity: ''));
	}//end testFindAnswersByIdSlugAndLegacyRef()

	/**
	 * published() offers only the published articles.
	 *
	 * @return void
	 */
	public function testPublishedOffersOnlyPublishedArticles(): void {
		$this->objects->put(register: 'portaliq', schema: 'article', row: ['uuid' => 'a1', 'status' => 'published']);
		$this->objects->put(register: 'portaliq', schema: 'article', row: ['uuid' => 'a2', 'status' => 'draft']);
		$this->objects->put(register: 'portaliq', schema: 'page', row: ['uuid' => 'g1', 'status' => 'published']);

		$this->assertSame(['a1'], array_column($this->store(installed: true)->published(), 'uuid'));
	}//end testPublishedOffersOnlyPublishedArticles()

	/**
	 * A missing portaliq register reads as not ready and as no articles.
	 *
	 * @return void
	 */
	public function testAMissingRegisterIsNotReadyAndReadsEmpty(): void {
		$this->objects->missing = ['portaliq'];
		$store = $this->store(installed: true);

		$this->assertTrue($store->isAvailable());
		$this->assertFalse($store->isReady());
		$this->assertSame([], $store->findAll());
		$this->assertNull($store->find(identity: 'a1'));
	}//end testAMissingRegisterIsNotReadyAndReadsEmpty()

	/**
	 * create() writes to portaliq's article schema and answers null on a refusal.
	 *
	 * @return void
	 */
	public function testCreateWritesToPortaliqAndAnswersNullOnARefusal(): void {
		$store = $this->store(installed: true);

		$saved = $store->create(payload: ['title' => 'Nieuw', 'legacyRef' => 'pipelinq:x']);
		$this->assertSame('Nieuw', $saved['title']);
		$this->assertSame([['register' => 'portaliq', 'schema' => 'article', 'uuid' => null]], $this->objects->saves);
		$this->assertTrue($store->isReady());

		$this->objects->failingSaves = ['portaliq'];
		$this->assertNull($store->create(payload: ['title' => 'Geweigerd']));
		$this->assertCount(1, $this->objects->rowsOf(register: 'portaliq', schema: 'article'));
	}//end testCreateWritesToPortaliqAndAnswersNullOnARefusal()

	/**
	 * An app manager that throws reads as portaliq not being installed.
	 *
	 * @return void
	 */
	public function testAThrowingAppManagerReadsAsNotAvailable(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willThrowException(new RuntimeException('app store down'));
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$store = new PortaliqArticleStore($container, $apps, new NullLogger());

		$this->assertFalse($store->isAvailable());
		$this->assertSame([], $store->findAll());
	}//end testAThrowingAppManagerReadsAsNotAvailable()

	/**
	 * Without OpenRegister nothing is read or written, and each attempt is logged as a warning.
	 *
	 * @return void
	 */
	public function testAnUnavailableOpenRegisterReadsAndWritesNothing(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no openregister'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(3))->method('warning')->with(
			'Pipelinq: OpenRegister is unavailable for portaliq articles',
			['reason' => 'no openregister']
		);

		$store = new PortaliqArticleStore($container, $apps, $logger);

		$this->assertTrue($store->isAvailable());
		$this->assertSame([], $store->findAll());
		$this->assertNull($store->create(payload: ['title' => 'X']));
		$this->assertNull($store->find(identity: ''));
		$this->assertSame([], $store->published());
	}//end testAnUnavailableOpenRegisterReadsAndWritesNothing()

	/**
	 * idOf() reads uuid before id, at the top level before `@self`, and answers '' otherwise.
	 *
	 * @return void
	 */
	public function testIdOfReadsUuidOrIdAtTheTopOrUnderSelf(): void {
		$store = $this->store(installed: true);

		$this->assertSame('u1', $store->idOf(payload: ['uuid' => 'u1', 'id' => 7]));
		$this->assertSame('7', $store->idOf(payload: ['uuid' => '', 'id' => 7]));
		$this->assertSame('s1', $store->idOf(payload: ['@self' => ['id' => 's1']]));
		$this->assertSame('top', $store->idOf(payload: ['id' => 'top', '@self' => ['uuid' => 'self']]));
		$this->assertSame('', $store->idOf(payload: ['@self' => 'not an array', 'uuid' => ['x']]));
		$this->assertSame('', $store->idOf(payload: null));
	}//end testIdOfReadsUuidOrIdAtTheTopOrUnderSelf()

	/**
	 * Rows the service returns unfiltered are narrowed here, a non-array
	 * `@self` is no slug, and an empty saved answer is a failed create.
	 *
	 * @return void
	 */
	public function testRowsAreNarrowedHereAndAnEmptySaveIsAFailure(): void {
		$service = new class {
			/**
			 * Every row, whatever the filters.
			 *
			 * @param array<string, mixed> $config        Ignored.
			 * @param bool                 $_rbac         Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return [
					['uuid' => 'a1', 'status' => 'published', '@self' => 'broken', 'slug' => 'een'],
					['uuid' => 'a2', 'status' => 'draft', 'legacyRef' => 'other:x'],
				];
			}//end findAll()

			/**
			 * No article is found by id.
			 *
			 * @param mixed ...$args Ignored.
			 *
			 * @return null
			 */
			public function find(mixed ...$args): mixed {
				return null;
			}//end find()

			/**
			 * The save answers nothing.
			 *
			 * @param mixed ...$args Ignored.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(mixed ...$args): array {
				return [];
			}//end saveObject()
		};
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($service);
		$store = new PortaliqArticleStore($container, $apps, new NullLogger());

		$this->assertSame(['a1'], array_column($store->published(), 'uuid'));
		$this->assertSame(['a1', 'a2'], array_column($store->findAll(), 'uuid'));
		$this->assertSame('a1', $store->find(identity: 'een')['uuid']);
		$this->assertNull($store->find(identity: 'x'));
		$this->assertNull($store->create(payload: ['title' => 'Leeg']));
	}//end testRowsAreNarrowedHereAndAnEmptySaveIsAFailure()

	/**
	 * The store over the in-memory registers.
	 *
	 * @param bool $installed Whether portaliq is installed.
	 *
	 * @return PortaliqArticleStore The store.
	 */
	private function store(bool $installed): PortaliqArticleStore {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $installed === true && $app === 'portaliq');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				$this->lookups++;
				$this->assertSame('OCA\\OpenRegister\\Service\\ObjectService', $id);
				return $this->objects;
			}
		);

		return new PortaliqArticleStore($container, $apps, new NullLogger());
	}//end store()
}//end class
