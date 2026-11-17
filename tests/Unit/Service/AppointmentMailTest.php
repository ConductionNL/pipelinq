<?php

/**
 * Unit tests for AppointmentMail.
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

use OCA\Pipelinq\Service\AppointmentMail;
use PHPUnit\Framework\TestCase;

/**
 * AppointmentMail carries its values unchanged.
 */
class AppointmentMailTest extends TestCase {

	/**
	 * Every constructor value is readable as given.
	 *
	 * @return void
	 */
	public function testKeepsTheValuesItIsGiven(): void {
		$recipient = 'jan@example.nl';
		$subject = 'Uw afspraak';
		$body = 'Tot morgen.';
		$icsContent = 'BEGIN:VCALENDAR';
		$value = new AppointmentMail(recipient: $recipient, subject: $subject, body: $body, icsContent: $icsContent);

		self::assertSame($recipient, $value->recipient);
		self::assertSame($subject, $value->subject);
		self::assertSame($body, $value->body);
		self::assertSame($icsContent, $value->icsContent);
	}//end testKeepsTheValuesItIsGiven()
}//end class
