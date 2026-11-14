<?php

/**
 * Unit tests for ArticleUsageListener.
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-pipelinq-tells-portaliq-where-an-article-is-used
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\Pipelinq\Listener\ArticleUsageListener;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCA\Portaliq\Event\ArticleUsagesRequestedEvent;
use OCP\EventDispatcher\Event;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__).'/Support/Portaliq/ArticleUsagesRequestedEvent.php';

/**
 * pipelinq answers portaliq's usage question for its templates, blasts and
 * social posts, and writes nothing.
 */
class ArticleUsageListenerTest extends TestCase {

	/**
	 * A template naming the article and a blast on it are both reported,
	 * each linking into pipelinq; unrelated objects are not.
	 *
	 * @return void
	 */
	public function testATemplateAndItsBlastAreReported(): void {
		$store = new InMemoryListObjectStore(
			[
				'campaignTemplate' => [
					['uuid' => 't1', 'name' => 'Nieuwsbrief maart', 'articleIds' => ['other', 'art-1']],
					['uuid' => 't2', 'name' => 'Ander', 'articleIds' => ['other']],
				],
				'blast' => [
					['uuid' => 'b1', 'name' => 'Verzending maart', 'templateId' => 't1'],
					['uuid' => 'b2', 'name' => 'Andere verzending', 'templateId' => 't2'],
				],
			]
		);
		$event = new ArticleUsagesRequestedEvent('art-1', 'lente', '');

		$this->listener(store: $store)->handle($event);

		$this->assertSame(
			[
				['id' => 't1', 'title' => 'Nieuwsbrief maart', 'kind' => 'campaignTemplate', 'app' => 'pipelinq', 'url' => 'https://cloud.example/apps/pipelinq/templates'],
				['id' => 'b1', 'title' => 'Verzending maart', 'kind' => 'blast', 'app' => 'pipelinq', 'url' => 'https://cloud.example/apps/pipelinq/blasts/b1/monitor'],
			],
			$event->getUsages()
		);
		$this->assertSame([], $store->writes);
	}//end testATemplateAndItsBlastAreReported()

	/**
	 * A social post naming the article by slug, and a template still naming
	 * it by its old pipelinq id, are both reported.
	 *
	 * @return void
	 */
	public function testSlugAndLegacyIdAreMatched(): void {
		$store = new InMemoryListObjectStore(
			[
				'campaignTemplate' => [['uuid' => 't1', 'name' => 'Oud', 'articleIds' => ['old-1']]],
				'socialPost' => [
					['uuid' => 's1', 'body' => 'Lees ons lente-artikel', 'articleId' => 'lente'],
					['uuid' => 's2', 'body' => 'Iets anders', 'articleId' => 'zomer'],
				],
			]
		);
		$event = new ArticleUsagesRequestedEvent('art-1', 'lente', 'pipelinq:old-1');

		$this->listener(store: $store)->handle($event);

		$this->assertSame(
			[['t1', 'campaignTemplate'], ['s1', 'socialPost']],
			array_map(static fn (array $usage): array => [$usage['id'], $usage['kind']], $event->getUsages())
		);
		$this->assertSame('Lees ons lente-artikel', $event->getUsages()[1]['title']);
		$this->assertSame('https://cloud.example/apps/pipelinq/social-posts/s1', $event->getUsages()[1]['url']);
	}//end testSlugAndLegacyIdAreMatched()

	/**
	 * A legacyRef from another app is not read as a pipelinq id.
	 *
	 * @return void
	 */
	public function testAForeignLegacyRefIsIgnored(): void {
		$store = new InMemoryListObjectStore(['campaignTemplate' => [['uuid' => 't1', 'name' => 'X', 'articleIds' => ['old-1']]]]);
		$event = new ArticleUsagesRequestedEvent('art-1', '', 'elsewhere:old-1');

		$this->listener(store: $store)->handle($event);

		$this->assertSame([], $event->getUsages());
	}//end testAForeignLegacyRefIsIgnored()

	/**
	 * Any other event is ignored and nothing is read.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$store = $this->getMockBuilder(InMemoryListObjectStore::class)->onlyMethods(['findAll'])->getMock();
		$store->expects($this->never())->method('findAll');

		$this->listener(store: $store)->handle(new Event());
	}//end testAnotherEventIsIgnored()

	/**
	 * The event name the listener registers for is portaliq's class.
	 *
	 * @return void
	 */
	public function testTheEventNameIsPortaliqsClass(): void {
		$this->assertSame(ArticleUsagesRequestedEvent::class, ArticleUsageListener::EVENT);
	}//end testTheEventNameIsPortaliqsClass()

	/**
	 * The listener over a store.
	 *
	 * @param InMemoryListObjectStore $store The store.
	 *
	 * @return ArticleUsageListener The listener.
	 */
	private function listener(InMemoryListObjectStore $store): ArticleUsageListener {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			function (string $route, array $params = []): string {
				$this->assertSame('pipelinq.dashboard.page', $route);
				return 'https://cloud.example/apps/pipelinq/'.$params['path'];
			}
		);

		return new ArticleUsageListener($store, $urls, new NullLogger());
	}//end listener()
}//end class
