<?php

/**
 * Unit tests for LetterRecordOwner.
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

use OCA\Pipelinq\Controller\LetterRecordOwner;
use PHPUnit\Framework\TestCase;

/**
 * A record owner carries its client, register and ticket unchanged.
 */
class LetterRecordOwnerTest extends TestCase {

	/**
	 * Every value given is the value read back.
	 *
	 * @return void
	 */
	public function testCarriesItsValues(): void {
		$owner = new LetterRecordOwner(clientId: 'client-1', register: 'reg', ticketId: null);

		self::assertSame('client-1', $owner->clientId);
		self::assertSame('reg', $owner->register);
		self::assertNull($owner->ticketId);
	}//end testCarriesItsValues()
}//end class
