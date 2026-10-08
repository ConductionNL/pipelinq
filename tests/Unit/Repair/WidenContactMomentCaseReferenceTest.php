<?php

/**
 * Unit tests for WidenContactMomentCaseReference.
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
use OCA\Pipelinq\Repair\WidenContactMomentCaseReference;
use OCA\Pipelinq\Service\TicketService;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the case reference widening.
 */
class WidenContactMomentCaseReferenceTest extends TestCase {
	/**
	 * Build the step over a ticket service double.
	 *
	 * @param ObjectServiceInterface|null $objectService The object service to hand back.
	 *
	 * @return WidenContactMomentCaseReference The step under test.
	 */
	private function build(?ObjectServiceInterface $objectService = null): WidenContactMomentCaseReference {
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

		return new WidenContactMomentCaseReference(
			$ticketService,
			$groupManager,
			$this->createMock(LoggerInterface::class),
		);
	}//end build()

	/**
	 * A single reference becomes a one-element set, and its own primary.
	 *
	 * @return void
	 */
	public function testASingleReferenceBecomesAOneElementSet(): void {
		$fields = $this->build()->widenedFields(['caseReference' => 'case-a']);

		$this->assertSame(['case-a'], $fields['caseReferences']);
		$this->assertSame('case-a', $fields['primaryCaseReference']);
	}//end testASingleReferenceBecomesAOneElementSet()

	/**
	 * A row that already carries a set is left alone.
	 *
	 * @return void
	 */
	public function testARowWithASetIsLeftAlone(): void {
		$this->assertNull(
			$this->build()->widenedFields(['caseReference' => 'case-a', 'caseReferences' => ['case-a', 'case-b']])
		);
	}//end testARowWithASetIsLeftAlone()

	/**
	 * A contact moment with no case at all is not given one.
	 *
	 * @return void
	 */
	public function testARowWithNoCaseIsNotGivenOne(): void {
		$this->assertNull($this->build()->widenedFields(['title' => 'Gebeld']));
		$this->assertNull($this->build()->widenedFields(['caseReference' => '   ']));
	}//end testARowWithNoCaseIsNotGivenOne()

	/**
	 * Running the step twice writes once.
	 *
	 * @return void
	 */
	public function testRunningTwiceWritesOnce(): void {
		$stored = [
			['id' => 'a1', 'caseReference' => 'case-a'],
			['id' => 'b2', 'caseReferences' => ['case-b'], 'primaryCaseReference' => 'case-b'],
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

		$step = $this->build(objectService: $objectService);
		$output = $this->createMock(IOutput::class);

		$step->run($output);
		$step->run($output);

		$this->assertCount(1, $saved, 'The second run must write nothing.');
		$this->assertSame(['case-a'], $saved[0]['caseReferences']);
		$this->assertSame('case-a', $saved[0]['primaryCaseReference']);
	}//end testRunningTwiceWritesOnce()
}//end class
