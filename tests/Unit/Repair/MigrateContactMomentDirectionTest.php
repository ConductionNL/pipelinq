<?php

/**
 * Unit tests for MigrateContactMomentDirection.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Repair\MigrateContactMomentDirection;
use OCA\Pipelinq\Service\TicketService;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the contact moment direction migration.
 */
class MigrateContactMomentDirectionTest extends TestCase {
	/**
	 * Build the step over a ticket service double.
	 *
	 * `onlyMethods` is deliberate: a double that may invent a method the real
	 * TicketService lacks can only ever pass.
	 *
	 * @param ObjectServiceInterface|null $objectService The object service to hand back.
	 *
	 * @return array{0: MigrateContactMomentDirection, 1: TicketService} The step and its service double.
	 */
	private function build(?ObjectServiceInterface $objectService = null): array {
		$ticketService = $this->getMockBuilder(TicketService::class)
			->disableOriginalConstructor()
			->onlyMethods(['isConfigured', 'getObjectService', 'getRegisterId', 'getSchemaId', 'sanitizeForSave'])
			->getMock();

		$ticketService->method('isConfigured')->willReturn(true);
		$ticketService->method('getRegisterId')->willReturn('1');
		$ticketService->method('getSchemaId')->willReturn('7');
		$ticketService->method('sanitizeForSave')->willReturnArgument(0);

		if ($objectService !== null) {
			$ticketService->method('getObjectService')->willReturn($objectService);
		}

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn(null);

		$step = new MigrateContactMomentDirection(
			$ticketService,
			$groupManager,
			$this->createMock(LoggerInterface::class),
		);

		return [$step, $ticketService];
	}//end build()

	/**
	 * The Dutch and English spellings both map onto the enum.
	 *
	 * @return void
	 */
	public function testBothSpellingsMap(): void {
		[$step] = $this->build();

		$this->assertSame('inbound', $step->directionFor(['channelMetadata' => ['richting' => 'inkomend']]));
		$this->assertSame('outbound', $step->directionFor(['channelMetadata' => ['richting' => 'uitgaand']]));
		$this->assertSame('inbound', $step->directionFor(['channelMetadata' => ['direction' => 'inbound']]));
		$this->assertSame('outbound', $step->directionFor(['channelMetadata' => ['direction' => 'OUTBOUND']]));
	}//end testBothSpellingsMap()

	/**
	 * An envelope that says nothing usable falls back to internal.
	 *
	 * @return void
	 */
	public function testUnknownFallsBackToInternal(): void {
		[$step] = $this->build();

		$this->assertSame('internal', $step->directionFor(['channelMetadata' => ['richting' => 'zijwaarts']]));
		$this->assertSame('internal', $step->directionFor([]));
		$this->assertSame('internal', $step->directionFor(['channelMetadata' => 'not-an-object']));
	}//end testUnknownFallsBackToInternal()

	/**
	 * A row that already carries a direction is not rewritten.
	 *
	 * This is the idempotence guarantee: the step writes only where
	 * directionFor() answers non-null.
	 *
	 * @return void
	 */
	public function testAnExistingDirectionIsLeftAlone(): void {
		[$step] = $this->build();

		$this->assertNull($step->directionFor(['direction' => 'outbound', 'channelMetadata' => ['richting' => 'inkomend']]));
	}//end testAnExistingDirectionIsLeftAlone()

	/**
	 * A direction outside the enum is treated as unset and re-derived.
	 *
	 * @return void
	 */
	public function testAnInvalidDirectionIsRederived(): void {
		[$step] = $this->build();

		$this->assertSame(
			'inbound',
			$step->directionFor(['direction' => 'sideways', 'channelMetadata' => ['richting' => 'inkomend']])
		);
	}//end testAnInvalidDirectionIsRederived()

	/**
	 * Running the step twice writes once.
	 *
	 * The second pass reads the row as the first pass left it, so a correct
	 * step saves nothing the second time round.
	 *
	 * @return void
	 */
	public function testRunningTwiceWritesOnce(): void {
		$stored = [
			['id' => 'a1', 'channelMetadata' => ['richting' => 'inkomend']],
			['id' => 'b2', 'direction' => 'outbound'],
		];

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			static function () use (&$stored): array {
				return $stored;
			}
		);

		$saved = [];
		$objectService->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$stored, &$saved): ?object {
				$saved[] = $object;
				foreach ($stored as $index => $row) {
					if ($row['id'] === $object['id']) {
						$stored[$index] = $object;
					}
				}

				return null;
			}
		);

		[$step] = $this->build(objectService: $objectService);
		$output = $this->createMock(IOutput::class);

		$step->run($output);
		$step->run($output);

		$this->assertCount(1, $saved, 'The second run must write nothing.');
		$this->assertSame('a1', $saved[0]['id']);
		$this->assertSame('inbound', $saved[0]['direction']);
	}//end testRunningTwiceWritesOnce()

	/**
	 * An unconfigured surface is skipped rather than half-migrated.
	 *
	 * @return void
	 */
	public function testUnconfiguredSurfaceIsSkipped(): void {
		$ticketService = $this->getMockBuilder(TicketService::class)
			->disableOriginalConstructor()
			->onlyMethods(['isConfigured', 'getObjectService'])
			->getMock();
		$ticketService->method('isConfigured')->willReturn(false);
		$ticketService->expects($this->never())->method('getObjectService');

		$groupManager = $this->createMock(IGroupManager::class);
		$step = new MigrateContactMomentDirection(
			$ticketService,
			$groupManager,
			$this->createMock(LoggerInterface::class),
		);

		$step->run($this->createMock(IOutput::class));
	}//end testUnconfiguredSurfaceIsSkipped()
}//end class
