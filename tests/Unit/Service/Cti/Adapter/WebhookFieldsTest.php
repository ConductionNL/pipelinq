<?php

/**
 * Unit tests for WebhookFields.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Cti\Adapter
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

namespace OCA\Pipelinq\Tests\Unit\Service\Cti\Adapter;

use OCA\Pipelinq\Service\Cti\Adapter\WebhookFields;
use PHPUnit\Framework\TestCase;

/**
 * WebhookFields reads typed values out of a telephony webhook payload.
 */
class WebhookFieldsTest extends TestCase {

	/**
	 * A payload shaped like a telephony call event.
	 *
	 * @return WebhookFields
	 */
	private static function fields(): WebhookFields {
		return new WebhookFields(
			source: [
				'callId' => 'c-42',
				'duration' => '125',
				'zero' => 0,
				'empty' => '',
				'nothing' => null,
				'caller' => ['number' => '+31201234567', 'extension' => 12],
				'legs' => [['agent' => 'anna'], ['agent' => 'bram']],
				'scalarList' => 'not a list',
			]
		);
	}//end fields()

	/**
	 * Text reads strings and stringifies numbers; absent and null keys are null.
	 *
	 * @return void
	 */
	public function testText(): void {
		$fields = self::fields();

		self::assertSame('c-42', $fields->text(key: 'callId'));
		self::assertSame('0', $fields->text(key: 'zero'));
		self::assertSame('', $fields->text(key: 'empty'));
		self::assertNull($fields->text(key: 'nothing'));
		self::assertNull($fields->text(key: 'missing'));
	}//end testText()

	/**
	 * Number casts to an integer; absent and null keys are null.
	 *
	 * @return void
	 */
	public function testNumber(): void {
		$fields = self::fields();

		self::assertSame(125, $fields->number(key: 'duration'));
		self::assertSame(0, $fields->number(key: 'zero'));
		self::assertNull($fields->number(key: 'nothing'));
		self::assertNull($fields->number(key: 'missing'));
	}//end testNumber()

	/**
	 * A part reads a nested object; a missing part is empty.
	 *
	 * @return void
	 */
	public function testPart(): void {
		$caller = self::fields()->part(key: 'caller');

		self::assertSame('+31201234567', $caller->text(key: 'number'));
		self::assertSame(12, $caller->number(key: 'extension'));
		self::assertNull(self::fields()->part(key: 'missing')->text(key: 'number'));
	}//end testPart()

	/**
	 * First reads the first entry of a list; an empty or absent list is empty.
	 *
	 * @return void
	 */
	public function testFirst(): void {
		self::assertSame('anna', self::fields()->first(key: 'legs')->text(key: 'agent'));
		self::assertNull(self::fields()->first(key: 'missing')->text(key: 'agent'));
		self::assertNull((new WebhookFields(source: ['legs' => []]))->first(key: 'legs')->text(key: 'agent'));
	}//end testFirst()
}//end class
