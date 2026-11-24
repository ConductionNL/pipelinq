<?php

/**
 * Unit tests for MenuStructure: how stored structure and module values read.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Settings
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Settings;

use OCA\Pipelinq\Kernel\Contracts\MenuStructureInterface;
use OCA\Pipelinq\Service\Settings\MenuStructure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two normalisers on their own, without the controller around them.
 */
#[CoversClass(MenuStructure::class)]
class MenuStructureNormaliseTest extends TestCase {

	/**
	 * Stored spellings of "full", in any case and with surrounding whitespace.
	 *
	 * @return array<string, array{string}>
	 */
	public static function fullSpellings(): array {
		return [
			'lower'      => ['full'],
			'upper'      => ['FULL'],
			'mixed'      => ['Full'],
			'whitespace' => ["  full\n"],
		];
	}//end fullSpellings()

	/**
	 * Every spelling of the word full selects the full structure.
	 *
	 * @param string $stored The stored value.
	 *
	 * @return void
	 */
	#[DataProvider('fullSpellings')]
	public function testFullInAnyCaseIsFull(string $stored): void {
		self::assertSame(MenuStructure::FULL, (new MenuStructure())->normalise($stored));
	}//end testFullInAnyCaseIsFull()

	/**
	 * Values that only look like full read as simple.
	 *
	 * @return array<string, array{string}>
	 */
	public static function notFull(): array {
		return [
			'empty'        => [''],
			'simple'       => ['simple'],
			'longer word'  => ['fully'],
			'inner space'  => ['fu ll'],
			'with a comma' => ['full,'],
		];
	}//end notFull()

	/**
	 * Anything other than the bare word reads as simple.
	 *
	 * @param string $stored The stored value.
	 *
	 * @return void
	 */
	#[DataProvider('notFull')]
	public function testAnythingElseIsSimple(string $stored): void {
		self::assertSame(MenuStructure::SIMPLE, (new MenuStructure())->normalise($stored));
	}//end testAnythingElseIsSimple()

	/**
	 * Modules come back in the fixed order, lower-cased and trimmed, without duplicates.
	 *
	 * @return void
	 */
	public function testModulesKeepTheFixedOrderWithoutDuplicates(): void {
		self::assertSame(
			'sales,pos,loyalty',
			(new MenuStructure())->normaliseModules(' LOYALTY , pos,sales,Sales,pos')
		);
	}//end testModulesKeepTheFixedOrderWithoutDuplicates()

	/**
	 * Unknown words and empty entries switch nothing on.
	 *
	 * @return void
	 */
	public function testUnknownAndEmptyEntriesAreDropped(): void {
		$structure = new MenuStructure();

		self::assertSame('', $structure->normaliseModules(''));
		self::assertSame('', $structure->normaliseModules(',,  ,'));
		self::assertSame('contracts', $structure->normaliseModules('contract,contracts,crm'));
	}//end testUnknownAndEmptyEntriesAreDropped()

	/**
	 * Every module stored at once returns the whole list in its own order.
	 *
	 * @return void
	 */
	public function testAllModulesReturnTheWholeList(): void {
		$stored = implode(',', array_reverse(MenuStructure::MODULES));

		self::assertSame(implode(',', MenuStructure::MODULES), (new MenuStructure())->normaliseModules($stored));
	}//end testAllModulesReturnTheWholeList()

	/**
	 * The class is what the kernel contract hands out.
	 *
	 * @return void
	 */
	public function testItImplementsTheKernelContract(): void {
		self::assertInstanceOf(MenuStructureInterface::class, new MenuStructure());
	}//end testItImplementsTheKernelContract()
}//end class
