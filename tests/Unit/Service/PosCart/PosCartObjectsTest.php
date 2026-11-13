<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Unit tests for PosCartObjects, the till's OpenRegister access.
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\PosCart;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\PosCart\PosCartObjects;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Sales and lines are read and written in the configured register and
 * schemas, outside RBAC and multitenancy; a missing sale is "not found".
 *
 * @covers \OCA\Pipelinq\Service\PosCart\PosCartObjects
 * @uses   \OCA\Pipelinq\Util\PayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 */
final class PosCartObjectsTest extends TestCase {

	/**
	 * The ObjectService double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The subject.
	 *
	 * @var PosCartObjects
	 */
	private PosCartObjects $subject;

	/**
	 * Build the subject over a configured register and schemas.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ([$app, $key]) {
				['pipelinq', 'register'] => 'reg-1',
				['pipelinq', 'posTransaction_schema'] => 'sch-tx',
				['pipelinq', 'posTransactionLine_schema'] => 'sch-line',
				default => $default,
			}
		);
		$this->subject = new PosCartObjects($this->objects, $config);

	}//end setUp()

	/**
	 * An OpenRegister object carrying the given body.
	 *
	 * @param array<string, mixed> $body The object body.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $body): ObjectEntityInterface {
		$entity = $this->createStub(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn($body);
		return $entity;

	}//end entity()

	/**
	 * Only the configured line schema is the line schema.
	 *
	 * @return void
	 */
	public function testIsLineSchemaMatchesOnlyTheConfiguredLineSchema(): void {
		$this->assertTrue($this->subject->isLineSchema('sch-line'));
		$this->assertFalse($this->subject->isLineSchema('sch-tx'));
		$this->assertFalse($this->subject->isLineSchema(''));

	}//end testIsLineSchemaMatchesOnlyTheConfiguredLineSchema()

	/**
	 * Without a configured line schema nothing is the line schema, not even ''.
	 *
	 * @return void
	 */
	public function testIsLineSchemaIsFalseWhenNotConfigured(): void {
		$subject = new PosCartObjects($this->objects, $this->createMock(IAppConfig::class));

		$this->assertFalse($subject->isLineSchema(''));

	}//end testIsLineSchemaIsFalseWhenNotConfigured()

	/**
	 * A sale is read by id in the sale schema, outside RBAC and multitenancy.
	 *
	 * @return void
	 */
	public function testTransactionIsReadFromTheSaleSchema(): void {
		$this->objects->expects($this->once())->method('find')
			->with('tx-1', [], false, 'reg-1', 'sch-tx', false, false)
			->willReturn($this->entity(['id' => 'tx-1', 'priceMode' => 'inclusive']));

		$this->assertSame(['id' => 'tx-1', 'priceMode' => 'inclusive'], $this->subject->transaction('tx-1'));

	}//end testTransactionIsReadFromTheSaleSchema()

	/**
	 * A blank id is not found without asking OpenRegister; an unknown id is
	 * not found either.
	 *
	 * @return void
	 */
	public function testBlankOrUnknownTransactionIsNotFound(): void {
		$this->objects->expects($this->once())->method('find')->with('tx-gone')->willReturn(null);

		foreach (['  ', 'tx-gone'] as $id) {
			try {
				$this->subject->transaction($id);
				$this->fail('A missing sale must be reported as not found.');
			} catch (OCSNotFoundException $exception) {
				$this->assertSame('Transaction not found', $exception->getMessage());
			}
		}

	}//end testBlankOrUnknownTransactionIsNotFound()

	/**
	 * The price mode is the sale's string priceMode; anything else, a missing
	 * sale or a failing read, is null.
	 *
	 * @return void
	 */
	public function testPriceModeOfReadsTheSaleOrIsNull(): void {
		$inclusive = $this->entity(['priceMode' => 'inclusive']);
		$numeric = $this->entity(['priceMode' => 1]);
		$this->objects->method('find')->willReturnCallback(
			static fn (int|string $id): ?ObjectEntityInterface => match ($id) {
				'tx-incl' => $inclusive,
				'tx-num' => $numeric,
				'tx-throw' => throw new RuntimeException('database gone'),
				default => null,
			}
		);

		$this->assertSame('inclusive', $this->subject->priceModeOf('tx-incl'));
		$this->assertNull($this->subject->priceModeOf('tx-num'));
		$this->assertNull($this->subject->priceModeOf('tx-missing'));
		$this->assertNull($this->subject->priceModeOf('tx-throw'));

	}//end testPriceModeOfReadsTheSaleOrIsNull()

	/**
	 * Lines are listed by sale in the line schema, re-indexed.
	 *
	 * @return void
	 */
	public function testLinesAreListedForTheSale(): void {
		$this->objects->expects($this->once())->method('findAll')
			->with(
				[
					'filters' => ['register' => 'reg-1', 'schema' => 'sch-line', 'transaction' => 'tx-1'],
					'limit' => 500,
				],
				false,
				false
			)
			->willReturn([3 => ['id' => 'l-1'], 7 => ['id' => 'l-2']]);

		$this->assertSame([['id' => 'l-1'], ['id' => 'l-2']], $this->subject->lines('tx-1'));

	}//end testLinesAreListedForTheSale()

	/**
	 * A line is saved under its own id without its @self block.
	 *
	 * @return void
	 */
	public function testSaveLineStripsSelfAndKeepsTheId(): void {
		$this->objects->expects($this->once())->method('saveObject')
			->with(
				['id' => 'l-1', 'quantity' => 2],
				[],
				'reg-1',
				'sch-line',
				'l-1',
				false,
				false
			);

		$this->subject->saveLine(['id' => 'l-1', 'quantity' => 2, '@self' => ['id' => 'l-1']]);

	}//end testSaveLineStripsSelfAndKeepsTheId()
}//end class
