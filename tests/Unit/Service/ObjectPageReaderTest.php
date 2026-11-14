<?php

/**
 * Unit tests for ObjectPageReader.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ObjectPageReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * ObjectPageReader reads every page of a findAll up to a row cap.
 */
class ObjectPageReaderTest extends TestCase {

	/**
	 * An object service over n rows that records each call.
	 *
	 * @param int  $rows  How many rows exist.
	 * @param bool $fails Whether findAll throws.
	 *
	 * @return object
	 */
	private static function objects(int $rows, bool $fails=false): object {
		return new class($rows, $fails) {
			/**
			 * The calls, as [config, rbac, multitenancy].
			 *
			 * @var list<array{0: array<string, mixed>, 1: bool, 2: bool}>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param int  $rows  The row count.
			 * @param bool $fails Whether to throw.
			 */
			public function __construct(private int $rows, private bool $fails) {
			}

			/**
			 * One page.
			 *
			 * @param array<string, mixed> $config        The query.
			 * @param bool                 $_rbac         RBAC on.
			 * @param bool                 $_multitenancy Multitenancy on.
			 *
			 * @return iterable<int, array<string, int>>
			 */
			public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): iterable {
				$this->calls[] = [$config, $_rbac, $_multitenancy];
				if ($this->fails === true) {
					throw new RuntimeException('store down');
				}

				$end = min($this->rows, ($config['offset'] + $config['limit']));
				$page = [];
				for ($n = $config['offset']; $n < $end; $n++) {
					$page['k' . $n] = ['n' => $n];
				}

				return new \ArrayIterator($page);
			}
		};
	}//end objects()

	/**
	 * Pages are read until a short page, with the reader's rights flag.
	 *
	 * @return void
	 */
	public function testPagesAreReadUntilAShortPage(): void {
		$objects = self::objects(rows: 250);
		$reader = new ObjectPageReader(objectService: $objects, withRights: false);

		$rows = $reader->readAll(filters: ['schema' => 'lead']);

		self::assertCount(250, $rows);
		self::assertSame(['n' => 249], $rows[249]);
		self::assertCount(3, $objects->calls);
		self::assertSame(['filters' => ['schema' => 'lead'], 'limit' => 100, 'offset' => 200], $objects->calls[2][0]);
		self::assertFalse($objects->calls[0][1]);
		self::assertFalse($objects->calls[0][2]);
		self::assertNull($reader->failure);
	}//end testPagesAreReadUntilAShortPage()

	/**
	 * Reading stops at the row cap.
	 *
	 * @return void
	 */
	public function testReadingStopsAtTheCap(): void {
		$objects = self::objects(rows: 10000);
		$reader = new ObjectPageReader(objectService: $objects, withRights: true);
		$reader->pageSize = 50;
		$reader->maxRows = 120;

		self::assertCount(150, $reader->readAll(filters: []));
		self::assertCount(3, $objects->calls);
		self::assertTrue($objects->calls[0][1]);
	}//end testReadingStopsAtTheCap()

	/**
	 * A full last page costs one more empty read.
	 *
	 * @return void
	 */
	public function testAnExactMultipleReadsOneEmptyPage(): void {
		$objects = self::objects(rows: 200);

		self::assertCount(200, (new ObjectPageReader(objectService: $objects, withRights: true))->readAll(filters: []));
		self::assertCount(3, $objects->calls);
	}//end testAnExactMultipleReadsOneEmptyPage()

	/**
	 * A failure stops reading and is kept on the reader.
	 *
	 * @return void
	 */
	public function testAFailureIsKept(): void {
		$reader = new ObjectPageReader(objectService: self::objects(rows: 5, fails: true), withRights: true);

		self::assertSame([], $reader->readAll(filters: []));
		self::assertInstanceOf(RuntimeException::class, $reader->failure);
		self::assertSame('store down', $reader->failure->getMessage());
	}//end testAFailureIsKept()

	/**
	 * A non-iterable answer ends the read without a failure.
	 *
	 * @return void
	 */
	public function testANonIterableAnswerEndsTheRead(): void {
		$objects = new class {
			/**
			 * Not a page.
			 *
			 * @return string
			 */
			public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): string {
				return 'nope';
			}
		};
		$reader = new ObjectPageReader(objectService: $objects, withRights: true);

		self::assertSame([], $reader->readAll(filters: []));
		self::assertNull($reader->failure);
	}//end testANonIterableAnswerEndsTheRead()
}//end class
