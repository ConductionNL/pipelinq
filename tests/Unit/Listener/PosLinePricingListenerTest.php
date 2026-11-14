<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\Listener\PosLinePricingListener;
use OCA\Pipelinq\Service\PosCart\PosCartObjects;
use OCA\Pipelinq\Service\PosCart\PosLinePricer;
use OCA\Pipelinq\Service\PosTransactionService;
use OCA\Pipelinq\Tests\Unit\Service\PosCart\PosSaleStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * A till line gets the server's figures before it is stored; other objects pass untouched.
 */
class PosLinePricingListenerTest extends TestCase {
	public function testAChangedLineGetsTheServerFiguresInItsSalePriceDisplay(): void {
		$line  = PosSaleStore::makeEntity(object: ['transaction' => 'tx-1', 'quantity' => 1, 'unitPrice' => 12.1, 'taxRate' => 21, 'lineTotal' => 1], schema: 'line');
		$event = new ObjectUpdatingEvent($line, null);

		$this->listener(priceMode: 'incl')->handle(event: $event);

		$this->assertEquals(['taxAmount' => 2.1, 'lineTotal' => 12.1], array_intersect_key($event->getModifiedData(), ['taxAmount' => 0, 'lineTotal' => 0]));
	}//end testAChangedLineGetsTheServerFiguresInItsSalePriceDisplay()

	public function testAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent(PosSaleStore::makeEntity(object: ['quantity' => 2], schema: 'salesQuoteLine'));

		$this->listener(priceMode: 'excl')->handle(event: $event);

		$this->assertSame([], $event->getModifiedData());
	}//end testAnotherSchemaIsLeftAlone()

	public function testAnEventAfterTheWriteIsIgnored(): void {
		$this->listener(priceMode: 'excl')->handle(event: new ObjectCreatedEvent(PosSaleStore::makeEntity(object: [], schema: 'line')));

		$this->addToAssertionCount(1);
	}//end testAnEventAfterTheWriteIsIgnored()

	/**
	 * A listener whose line schema is `line` and whose sale has the given price display.
	 *
	 * @param string $priceMode The sale's price display.
	 *
	 * @return PosLinePricingListener The listener.
	 */
	private function listener(string $priceMode): PosLinePricingListener {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key): string => ($key === 'posTransactionLine_schema' ? 'line' : 'tx'));
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn(PosSaleStore::makeEntity(object: ['priceMode' => $priceMode], schema: 'tx'));
		$pos = (new \ReflectionClass(PosTransactionService::class))->newInstanceWithoutConstructor();
		return new PosLinePricingListener(pricer: new PosLinePricer(pos: $pos, objects: new PosCartObjects(objects: $objects, appConfig: $appConfig)));
	}//end listener()
}//end class
