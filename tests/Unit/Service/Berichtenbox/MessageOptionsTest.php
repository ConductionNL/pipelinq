<?php

/**
 * Unit tests for MessageOptions: the per-message overrides of a Berichtenbox send.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Berichtenbox
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

namespace OCA\Pipelinq\Tests\Unit\Service\Berichtenbox;

use OCA\Pipelinq\Service\Berichtenbox\MessageOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Case type, language and how the extra variables combine with the defaults.
 */
#[CoversClass(MessageOptions::class)]
class MessageOptionsTest extends TestCase {

	/**
	 * Without extra variables there is no case type and the language is Dutch.
	 *
	 * @return void
	 */
	public function testDefaults(): void {
		$options = new MessageOptions();

		self::assertNull($options->templateOverride);
		self::assertSame('', $options->caseType());
		self::assertSame('nl', $options->language());
		self::assertSame(['a' => '1'], $options->variablesOver(defaults: ['a' => '1']));
	}//end testDefaults()

	/**
	 * Case type and language come from the extra variables, read as strings.
	 *
	 * @return void
	 */
	public function testCaseTypeAndLanguageComeFromTheVariables(): void {
		$options = new MessageOptions(extraVariables: ['caseType' => 'parkeervergunning', 'language' => 'en']);
		self::assertSame('parkeervergunning', $options->caseType());
		self::assertSame('en', $options->language());

		$numeric = new MessageOptions(extraVariables: ['caseType' => 12]);
		self::assertSame('12', $numeric->caseType());
	}//end testCaseTypeAndLanguageComeFromTheVariables()

	/**
	 * The extra variables override the defaults key by key and add new ones after them.
	 *
	 * @return void
	 */
	public function testExtraVariablesWinOverTheDefaults(): void {
		$options = new MessageOptions(extraVariables: ['status' => 'afgehandeld', 'extra' => 'ja']);

		self::assertSame(
			['caseId' => 'z1', 'status' => 'afgehandeld', 'extra' => 'ja'],
			$options->variablesOver(defaults: ['caseId' => 'z1', 'status' => 'ontvangen'])
		);
	}//end testExtraVariablesWinOverTheDefaults()
}//end class
