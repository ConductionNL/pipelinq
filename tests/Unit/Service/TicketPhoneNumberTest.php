<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\PhoneNormaliser;
use OCA\Pipelinq\Service\TicketPhoneNumber;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Which number a ticket is about, from whichever field its writer filled.
 *
 * @covers \OCA\Pipelinq\Service\TicketPhoneNumber
 * @uses   \OCA\Pipelinq\Service\PhoneNormaliser
 *
 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.2
 */
class TicketPhoneNumberTest extends TestCase {

	/**
	 * The reader over a Dutch default country.
	 *
	 * @return TicketPhoneNumber
	 */
	private function reader(): TicketPhoneNumber {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('NL');

		return new TicketPhoneNumber(new PhoneNormaliser($config, $this->createMock(LoggerInterface::class)));
	}//end reader()

	/**
	 * An inbound call is about its caller, an outbound one about its callee.
	 *
	 * @return void
	 */
	public function testACallIsAboutTheOtherParty(): void {
		$call = ['from_number' => '+31612345678', 'to_number' => '+31201234567'];

		$this->assertSame('+31612345678', $this->reader()->e164(array_merge($call, ['direction' => 'inbound'])));
		$this->assertSame('+31201234567', $this->reader()->e164(array_merge($call, ['direction' => 'outbound'])));
		$this->assertSame('+31612345678', $this->reader()->e164($call));
	}//end testACallIsAboutTheOtherParty()

	/**
	 * An enquiry's typed number and a message sender are read when there is no call.
	 *
	 * @return void
	 */
	public function testAnEnquiryAndAMessageAreReadToo(): void {
		$this->assertSame('+31612345678', $this->reader()->e164(['reporterPhone' => '06-12345678']));
		$this->assertSame('+31612345678', $this->reader()->e164(['channelMetadata' => ['from' => '0031612345678']]));
		$this->assertSame('06-12345678', $this->reader()->rawNumber(['from_number' => '  ', 'reporterPhone' => ' 06-12345678 ']));
	}//end testAnEnquiryAndAMessageAreReadToo()

	/**
	 * A ticket without a number, or with one that does not normalise, has none.
	 *
	 * @return void
	 */
	public function testNoNumberIsNull(): void {
		$this->assertNull($this->reader()->e164(['title' => 'Vraag over parkeren']));
		$this->assertNull($this->reader()->e164(['reporterPhone' => 'geen']));
		$this->assertNull($this->reader()->rawNumber(['channelMetadata' => 'not an array', 'from_number' => 42]));
	}//end testNoNumberIsNull()
}//end class
