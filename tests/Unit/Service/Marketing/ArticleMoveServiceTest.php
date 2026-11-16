<?php

/**
 * Unit tests for ArticleMoveService.
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
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-existing-pipelinq-articles-move-to-portaliq-once
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\ArticleMoveService;
use OCA\Pipelinq\Service\Marketing\PortaliqArticleStore;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryRegisters;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The move copies every article once, rewrites the references and reports.
 */
class ArticleMoveServiceTest extends TestCase {

	/**
	 * pipelinq's register.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $pipelinq;

	/**
	 * portaliq's register.
	 *
	 * @var InMemoryRegisters
	 */
	private InMemoryRegisters $portaliq;

	/**
	 * Set up three pipelinq articles, a template naming one by slug and a
	 * social post naming another by id.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->pipelinq = new InMemoryListObjectStore(
			[
				'article' => [
					['uuid' => 'old-1', 'title' => 'Lente', 'slug' => 'lente', 'body' => '## Lente', 'status' => 'published', 'author' => 'marieke', 'publishedAt' => '2026-03-01T09:00:00+00:00', 'agentAuthored' => false, 'tags' => ['seizoen'], 'heroImage' => 'app:pipelinq/marketing/lente.jpg'],
					['uuid' => 'old-2', 'title' => 'Zomer', 'slug' => 'zomer', 'status' => 'draft'],
					['uuid' => 'old-3', 'title' => 'Herfst', 'slug' => 'herfst', 'status' => 'archived'],
				],
				'campaignTemplate' => [
					['uuid' => 't1', 'name' => 'Nieuwsbrief', 'articleIds' => ['lente', 'unknown']],
					['uuid' => 't2', 'name' => 'Zonder artikelen', 'articleIds' => []],
				],
				'socialPost' => [
					['uuid' => 's1', 'body' => 'Zomer!', 'articleId' => 'old-2'],
				],
			]
		);
		$this->portaliq = new InMemoryRegisters();
	}//end setUp()

	/**
	 * Running the move twice moves nothing the second time (the spec scenario).
	 *
	 * @return void
	 */
	public function testRunningTheMoveTwiceMovesNothingTheSecondTime(): void {
		$first  = $this->move(installed: true)->run();
		$second = $this->move(installed: true)->run();

		$moved = $this->portaliq->rowsOf(register: 'portaliq', schema: 'article');
		$this->assertSame(['pipelinq:old-1', 'pipelinq:old-2', 'pipelinq:old-3'], array_column($moved, 'legacyRef'));
		$this->assertSame(['status' => 'done', 'moved' => 3, 'alreadyMoved' => 0, 'failed' => 0, 'references' => 2, 'renamedSlugs' => []], $first);
		$this->assertSame(['status' => 'done', 'moved' => 0, 'alreadyMoved' => 3, 'failed' => 0, 'references' => 0, 'renamedSlugs' => []], $second);

		$byLegacy = array_column($moved, 'uuid', 'legacyRef');
		$this->assertSame([$byLegacy['pipelinq:old-1'], 'unknown'], $this->pipelinq->find(schemaSlug: 'campaignTemplate', id: 't1')['articleIds']);
		$this->assertSame($byLegacy['pipelinq:old-2'], $this->pipelinq->find(schemaSlug: 'socialPost', id: 's1')['articleId']);
		$this->assertSame($byLegacy['pipelinq:old-3'], $this->pipelinq->find(schemaSlug: 'article', id: 'old-3')['migratedTo']);
		$this->assertSame(1, ($this->pipelinq->writes['campaignTemplate'] ?? 0));
	}//end testRunningTheMoveTwiceMovesNothingTheSecondTime()

	/**
	 * Every listed property is copied, and nothing else.
	 *
	 * @return void
	 */
	public function testEveryPropertyIsCopied(): void {
		$this->move(installed: true)->run();

		$copy = $this->portaliq->rowsOf(register: 'portaliq', schema: 'article')[0];
		unset($copy['uuid']);
		$this->assertSame(
			['title' => 'Lente', 'slug' => 'lente', 'body' => '## Lente', 'heroImage' => 'app:pipelinq/marketing/lente.jpg', 'tags' => ['seizoen'], 'status' => 'published', 'author' => 'marieke', 'publishedAt' => '2026-03-01T09:00:00+00:00', 'agentAuthored' => false, 'legacyRef' => 'pipelinq:old-1'],
			$copy
		);
	}//end testEveryPropertyIsCopied()

	/**
	 * A slug portaliq already holds gets the suffix and the report names it.
	 *
	 * @return void
	 */
	public function testACollidingSlugGetsTheSuffix(): void {
		$this->portaliq->put(register: 'portaliq', schema: 'article', row: ['uuid' => 'own', 'title' => 'Eigen', 'slug' => 'zomer']);

		$result = $this->move(installed: true)->run();

		$this->assertSame(['zomer'], $result['renamedSlugs']);
		$this->assertSame(['zomer', 'lente', 'zomer-pipelinq', 'herfst'], array_column($this->portaliq->rowsOf(register: 'portaliq', schema: 'article'), 'slug'));
	}//end testACollidingSlugGetsTheSuffix()

	/**
	 * Without portaliq nothing is read, copied or rewritten.
	 *
	 * @return void
	 */
	public function testWithoutPortaliqNothingMoves(): void {
		$result = $this->move(installed: false)->run();

		$this->assertSame(['status' => 'portaliq-missing', 'moved' => 0, 'alreadyMoved' => 0, 'failed' => 0, 'references' => 0, 'renamedSlugs' => []], $result);
		$this->assertSame([], $this->portaliq->saves);
		$this->assertSame([], $this->pipelinq->writes);
	}//end testWithoutPortaliqNothingMoves()

	/**
	 * Without portaliq's register nothing moves either.
	 *
	 * @return void
	 */
	public function testWithoutTheArticleSchemaNothingMoves(): void {
		$this->portaliq->missing = ['portaliq'];

		$this->assertSame('portaliq-missing', $this->move(installed: true)->run()['status']);
		$this->assertSame([], $this->pipelinq->writes);
	}//end testWithoutTheArticleSchemaNothingMoves()

	/**
	 * A refused copy is counted as failed, its article is not marked and its
	 * references are left alone.
	 *
	 * @return void
	 */
	public function testARefusedCopyIsCountedAndLeavesTheReferences(): void {
		$this->portaliq->failingSaves = ['portaliq'];

		$result = $this->move(installed: true)->run();

		$this->assertSame(['failures', 0, 3], [$result['status'], $result['moved'], $result['failed']]);
		$this->assertSame(['lente', 'unknown'], $this->pipelinq->find(schemaSlug: 'campaignTemplate', id: 't1')['articleIds']);
		$this->assertArrayNotHasKey('migratedTo', $this->pipelinq->find(schemaSlug: 'article', id: 'old-1'));
	}//end testARefusedCopyIsCountedAndLeavesTheReferences()

	/**
	 * The move over the two registers.
	 *
	 * @param bool $installed Whether portaliq is installed.
	 *
	 * @return ArticleMoveService The move.
	 */
	private function move(bool $installed): ArticleMoveService {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $installed === true && $app === 'portaliq');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->portaliq);

		return new ArticleMoveService($this->pipelinq, new PortaliqArticleStore($container, $apps, new NullLogger()), new NullLogger());
	}//end move()
}//end class
