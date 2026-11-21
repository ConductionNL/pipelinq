<?php

/**
 * Unit tests for LeadLinePricingListener.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/products-price-lists/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\Kernel\Contracts\CallerObjectReaderInterface;
use OCA\Pipelinq\Kernel\Contracts\ProductCatalogServiceInterface;
use OCA\Pipelinq\Listener\LeadLinePricingListener;
use OCA\Pipelinq\Service\LinePricing\LeadLinePricer;
use OCA\Pipelinq\Tests\Unit\Service\PosCart\PosSaleStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The listener prices lead lines before they are written, and nothing else.
 *
 * @covers \OCA\Pipelinq\Listener\LeadLinePricingListener
 * @uses   \OCA\Pipelinq\Service\LinePricing\LeadLinePricer
 */
class LeadLinePricingListenerTest extends TestCase {

	/**
	 * A new lead line gets its price merged into what is stored.
	 *
	 * @return void
	 */
	public function testANewLeadLineGetsItsPriceMergedIntoWhatIsStored(): void {
		$event = new ObjectCreatingEvent(PosSaleStore::makeEntity(object: ['lead' => 'l-1', 'product' => 'p-1', 'quantity' => 1], schema: 'lp'));
		$event->setModifiedData(['notes' => 'kept']);

		$this->listener(resolves: true)->handle(event: $event);

		$this->assertSame(
			expected: ['notes' => 'kept', 'unitPrice' => 95.0, 'priceSource' => 'price-list', 'priceListTitle' => 'Overheid 2027'],
			actual: $event->getModifiedData()
		);
	}//end testANewLeadLineGetsItsPriceMergedIntoWhatIsStored()

	/**
	 * An edit compares with the stored line.
	 *
	 * @return void
	 */
	public function testAnEditComparesWithTheStoredLine(): void {
		$old   = PosSaleStore::makeEntity(
			object: ['lead' => 'l-1', 'product' => 'p-1', 'quantity' => 1, 'unitPrice' => 95, 'priceSource' => 'price-list'],
			schema: 'lp'
		);
		$new   = PosSaleStore::makeEntity(
			object: ['lead' => 'l-1', 'product' => 'p-1', 'quantity' => 1, 'unitPrice' => 88, 'priceSource' => 'price-list'],
			schema: 'lp'
		);
		$event = new ObjectUpdatingEvent($new, $old);

		$this->listener(resolves: false)->handle(event: $event);

		$this->assertSame(expected: ['priceSource' => 'manual', 'priceListTitle' => null], actual: $event->getModifiedData());
	}//end testAnEditComparesWithTheStoredLine()

	/**
	 * Another schema is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent(PosSaleStore::makeEntity(object: ['product' => 'p-1'], schema: 'salesQuoteLine'));

		$this->listener(resolves: false)->handle(event: $event);

		$this->assertSame(expected: [], actual: $event->getModifiedData());
	}//end testAnotherSchemaIsLeftAlone()

	/**
	 * An event after the write is ignored.
	 *
	 * @return void
	 */
	public function testAnEventAfterTheWriteIsIgnored(): void {
		$event = new ObjectCreatedEvent(PosSaleStore::makeEntity(object: ['product' => 'p-1'], schema: 'lp'));

		$this->listener(resolves: false)->handle(event: $event);

		$this->addToAssertionCount(count: 1);
	}//end testAnEventAfterTheWriteIsIgnored()

	/**
	 * Stand-in for listener().
	 *
	 * @param bool $resolves The resolves.
	 *
	 * @return LeadLinePricingListener
	 */
	private function listener(bool $resolves): LeadLinePricingListener {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => match ($key) {
				'leadProduct_schema' => 'lp',
				default => '',
			}
		);
		$reader = $this->createMock(originalClassName: CallerObjectReaderInterface::class);
		$reader->method('read')->willReturn(['id' => 'x']);
		$catalog = $this->createMock(originalClassName: ProductCatalogServiceInterface::class);
		$expectation = $this->never();
		if ($resolves === true) {
			$expectation = $this->once();
		}

		$catalog->expects($expectation)->method('resolveEffectivePrice')
			->willReturn(['unitPrice' => 95.0, 'source' => 'price-list', 'priceListTitle' => 'Overheid 2027']);

		return new LeadLinePricingListener(pricer: new LeadLinePricer(catalog: $catalog, reader: $reader), appConfig: $appConfig);
	}//end listener()
}//end class
