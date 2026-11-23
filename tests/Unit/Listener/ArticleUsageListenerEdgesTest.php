<?php

/**
 * The edges of ArticleUsageListener: empty questions, a failing store and
 * oddly shaped objects.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-pipelinq-tells-portaliq-where-an-article-is-used
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\Pipelinq\Listener\ArticleUsageListener;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCA\Portaliq\Event\ArticleUsagesRequestedEvent;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once dirname(__DIR__).'/Support/Portaliq/ArticleUsagesRequestedEvent.php';

/**
 * A question without any name reads nothing, a failing store is logged and
 * answers nothing, a non-list articleIds never matches, and a usage without a
 * name, title, subject or body is titled by its id.
 *
 * @covers \OCA\Pipelinq\Listener\ArticleUsageListener
 */
class ArticleUsageListenerEdgesTest extends TestCase {

	/**
	 * An event whose id, slug and legacy ref are all blank reads no objects.
	 *
	 * @return void
	 */
	public function testAQuestionWithoutNamesReadsNothing(): void {
		$store = $this->getMockBuilder(InMemoryListObjectStore::class)->onlyMethods(['findAll'])->getMock();
		$store->expects($this->never())->method('findAll');
		$event = new ArticleUsagesRequestedEvent('  ', '', 'pipelinq:');

		$this->listener(store: $store, logger: $this->createMock(LoggerInterface::class))->handle($event);

		$this->assertSame([], $event->getUsages());
	}//end testAQuestionWithoutNamesReadsNothing()

	/**
	 * A store that throws is logged as a warning with its reason, and no
	 * usage is reported.
	 *
	 * @return void
	 */
	public function testAFailingStoreIsLoggedAndAnswersNothing(): void {
		$store = $this->getMockBuilder(InMemoryListObjectStore::class)->onlyMethods(['findAll'])->getMock();
		$store->method('findAll')->willThrowException(new RuntimeException('register offline'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Pipelinq: article usages could not be listed',
			['reason' => 'register offline']
		);
		$event = new ArticleUsagesRequestedEvent('art-1', '', '');

		$this->listener(store: $store, logger: $logger)->handle($event);

		$this->assertSame([], $event->getUsages());
	}//end testAFailingStoreIsLoggedAndAnswersNothing()

	/**
	 * A template whose articleIds is a string, not a list, is not a usage
	 * even when the string is the article id.
	 *
	 * @return void
	 */
	public function testAScalarArticleIdsNeverMatches(): void {
		$store = new InMemoryListObjectStore(
			['campaignTemplate' => [['uuid' => 't1', 'name' => 'Los', 'articleIds' => 'art-1']]]
		);

		$this->assertSame([], $this->listener(store: $store, logger: $this->createMock(LoggerInterface::class))->usages(names: ['art-1']));
	}//end testAScalarArticleIdsNeverMatches()

	/**
	 * A template without any title field is titled by its id; a non-array
	 * @self contributes no identities, so a blast is matched on the template's
	 * own uuid only.
	 *
	 * @return void
	 */
	public function testAnUntitledTemplateFallsBackToItsIdAndAScalarSelfIsIgnored(): void {
		$store = new InMemoryListObjectStore(
			[
				'campaignTemplate' => [['uuid' => 't9', '@self' => 'broken', 'name' => '  ', 'articleIds' => ['art-1']]],
				'blast' => [
					['uuid' => 'b1', 'templateId' => 't9'],
					['uuid' => 'b2', 'templateId' => 'broken'],
				],
			]
		);

		$usages = $this->listener(store: $store, logger: $this->createMock(LoggerInterface::class))->usages(names: ['art-1']);

		$this->assertSame(
			[
				['id' => 't9', 'title' => 't9', 'kind' => 'campaignTemplate', 'url' => 'https://cloud.example/apps/pipelinq/templates'],
				['id' => 'b1', 'title' => 'b1', 'kind' => 'blast', 'url' => 'https://cloud.example/apps/pipelinq/blasts/b1/monitor'],
			],
			$usages
		);
	}//end testAnUntitledTemplateFallsBackToItsIdAndAScalarSelfIsIgnored()

	/**
	 * The listener over a store.
	 *
	 * @param InMemoryListObjectStore $store  The store.
	 * @param LoggerInterface         $logger The logger.
	 *
	 * @return ArticleUsageListener The listener.
	 */
	private function listener(InMemoryListObjectStore $store, LoggerInterface $logger): ArticleUsageListener {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params = []): string => 'https://cloud.example/apps/pipelinq/'.$params['path']
		);

		return new ArticleUsageListener($store, $urls, $logger);
	}//end listener()
}//end class
