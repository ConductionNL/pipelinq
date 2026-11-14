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
