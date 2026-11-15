<?php

/**
 * Unit tests for ListPageText.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
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

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ListPageText;
use PHPUnit\Framework\TestCase;

/**
 * A page text carries its title, heading, body and form unchanged.
 */
class ListPageTextTest extends TestCase {

	/**
	 * Every value given is the value read back.
	 *
	 * @return void
	 */
	public function testCarriesItsValues(): void {
		$text = new ListPageText(title: 'Unsubscribe', heading: 'Unsubscribe from News', body: 'Nothing has changed yet.', form: '<form></form>');

		self::assertSame('Unsubscribe', $text->title);
		self::assertSame('Unsubscribe from News', $text->heading);
		self::assertSame('Nothing has changed yet.', $text->body);
		self::assertSame('<form></form>', $text->form);
	}//end testCarriesItsValues()
}//end class
