<?php

/**
 * Unit tests for SoftOptInCheck.
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

use OCA\Pipelinq\Service\SoftOptInCheck;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SoftOptInCheck: why a list or an import cannot take a soft opt-in.
 *
 * @covers \OCA\Pipelinq\Service\SoftOptInCheck
 */
class SoftOptInCheckTest extends TestCase {

	/**
	 * The mailer mock.
	 *
	 * @var IMailer&\PHPUnit\Framework\MockObject\MockObject
	 */
	private IMailer $mailer;

	/**
	 * The check under test.
	 *
	 * @var SoftOptInCheck
	 */
	private SoftOptInCheck $check;

	/**
	 * Set up the check with a mailer mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mailer = $this->createMock(originalClassName: IMailer::class);
		$this->check  = new SoftOptInCheck(mailer: $this->mailer);
	}//end setUp()

	/**
	 * A missing list is not found, a double opt-in list is refused, a soft one passes.
	 *
	 * @return void
	 */
	public function testListRefusal(): void {
		self::assertSame(expected: 'Not found', actual: $this->check->listRefusal(list: null));
		self::assertSame(
			expected: 'This list uses double opt-in, so a subscriber has to confirm the link themselves',
			actual: $this->check->listRefusal(list: ['optInMode' => 'double'])
		);
		self::assertSame(
			expected: 'This list uses double opt-in, so a subscriber has to confirm the link themselves',
			actual: $this->check->listRefusal(list: [])
		);
		self::assertNull(actual: $this->check->listRefusal(list: ['optInMode' => 'soft']));
	}//end testListRefusal()

	/**
	 * An address the mailer rejects is refused before the evidence is looked at.
	 *
	 * @return void
	 */
	public function testImportRefusalRejectsAnInvalidAddress(): void {
		$this->mailer->expects($this->once())->method('validateMailAddress')->with('nope')->willReturn(value: false);

		self::assertSame(
			expected: 'That is not a valid email address',
			actual: $this->check->importRefusal(email: 'nope', evidence: ['objectionOffered' => true])
		);
	}//end testImportRefusalRejectsAnInvalidAddress()

	/**
	 * A valid address still needs a record that the customer could object.
	 *
	 * @return void
	 */
	public function testImportRefusalNeedsTheObjectionEvidence(): void {
		$this->mailer->method('validateMailAddress')->with('a@b.nl')->willReturn(value: true);

		$expected = 'A soft opt-in needs a record that the customer was offered the chance to object';
		self::assertSame(expected: $expected, actual: $this->check->importRefusal(email: 'a@b.nl', evidence: []));
		self::assertSame(expected: $expected, actual: $this->check->importRefusal(email: 'a@b.nl', evidence: ['objectionOffered' => 0]));
		self::assertNull(actual: $this->check->importRefusal(email: 'a@b.nl', evidence: ['objectionOffered' => true]));
	}//end testImportRefusalNeedsTheObjectionEvidence()
}//end class
