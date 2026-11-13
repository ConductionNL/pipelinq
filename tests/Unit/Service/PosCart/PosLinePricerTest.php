<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\PosCart;

use OCA\Pipelinq\Service\PosCart\PosCartObjects;
use OCA\Pipelinq\Service\PosCart\PosLinePricer;
use OCA\Pipelinq\Service\PosTransactionService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A line is priced by the server in its sale's price mode, and only the
 * figures come back: the rest of the line is the caller's to keep.
 *
 * @covers \OCA\Pipelinq\Service\PosCart\PosLinePricer
 * @uses   \OCA\Pipelinq\Service\PosTransactionService
 * @uses   \OCA\Pipelinq\Lifecycle\PosAccessPolicy
 */
class PosLinePricerTest extends TestCase {
	/**
	 * The mocked sale store.
	 *
	 * @var PosCartObjects&MockObject
	 */
	private PosCartObjects $objects;

	private PosLinePricer $pricer;

	protected function setUp(): void {
		$this->objects = $this->createMock(PosCartObjects::class);
		$appConfig     = $this->createMock(IAppConfig::class);
		$pos           = new PosTransactionService(
			$this->createMock(ContainerInterface::class),
			$appConfig,
			new PosAccessPolicy(appConfig: $appConfig, groupManager: $this->createMock(IGroupManager::class)),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IEventDispatcher::class),
		);
		$this->pricer = new PosLinePricer(pos: $pos, objects: $this->objects);
	}//end setUp()

	public function testALineIsPricedInItsSalesModeAndOnlyTheFiguresComeBack(): void {
		$this->objects->expects($this->once())->method('priceModeOf')->with('tx-1')->willReturn('incl');

		$figures = $this->pricer->figures(line: ['id' => 'l-1', 'transaction' => 'tx-1', 'product' => 'p-7', 'quantity' => 2, 'unitPrice' => 12.1, 'taxRate' => 21]);

		$this->assertSame(['quantity' => 2.0, 'unitPrice' => 12.1, 'taxRate' => 21.0, 'discount' => 0.0, 'taxAmount' => 4.2, 'lineTotal' => 24.2], $figures);
	}//end testALineIsPricedInItsSalesModeAndOnlyTheFiguresComeBack()

	public function testALineWithoutATransactionAsksForTheEmptyOneAndFallsBackToExclusive(): void {
		$this->objects->expects($this->once())->method('priceModeOf')->with('')->willReturn(null);

		$figures = $this->pricer->figures(line: ['quantity' => 3, 'unitPrice' => 10, 'discount' => 10, 'taxRate' => 9]);

		$this->assertSame(['quantity' => 3.0, 'unitPrice' => 10.0, 'discount' => 10.0, 'taxRate' => 9.0, 'taxAmount' => 2.43, 'lineTotal' => 29.43], $figures);
	}//end testALineWithoutATransactionAsksForTheEmptyOneAndFallsBackToExclusive()

	public function testAnUnknownModeIsPricedExclusiveAndClientFiguresAreReplaced(): void {
		$figures = $this->pricer->figuresIn(line: ['quantity' => 1, 'unitPrice' => 100, 'taxRate' => 21, 'taxAmount' => 0, 'lineTotal' => 1], priceMode: 'gross');

		$this->assertSame(21.0, $figures['taxAmount']);
		$this->assertSame(121.0, $figures['lineTotal']);
		$this->assertArrayNotHasKey('net', $figures);
	}//end testAnUnknownModeIsPricedExclusiveAndClientFiguresAreReplaced()
}//end class
