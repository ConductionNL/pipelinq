<?php
/**
 * Example data for PqOrganisatie: Buurtvereniging Oost has the subsidy mail
 * Fatma Yildiz linked to it, as the Activiteit card ("e-mail, gekoppeld") and
 * the E-mails card draw it.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The organisation's linked subsidy mail.
 */
class ZuiddrechtOostLinkedMailTest extends TestCase {

	/**
	 * The fields OpenRegister's emailLinks part accepts (ExampleSetEmailLinks::FIELDS).
	 */
	private const FIELDS = ['object', 'messageId', 'mailAccountId', 'mailMessageId', 'subject', 'sender', 'mailDate', 'linkedBy'];

	/**
	 * One mail on Buurtvereniging Oost, the day before, at 16:05, linked by Fatma.
	 *
	 * @return void
	 */
	public function testTheSubsidyMailIsLinkedToBuurtverenigingOost(): void {
		$file = __DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.d/79-oost-linked-mail.json';
		$part = json_decode((string)file_get_contents($file), true);
		$this->assertIsArray(actual: $part, message: 'the fragment is there and is JSON');
		$links = array_column(($part['emailLinks'] ?? []), 'data', 'key');
		$mail  = ($links['oost-subsidie-mail'] ?? null);
		$this->assertNotNull(actual: $mail);
		$this->assertSame(expected: [], actual: array_values(array_diff(array_keys($mail), self::FIELDS)));
		$this->assertSame(expected: '{{object:client-buurtoost.id}}', actual: $mail['object']);
		$this->assertSame(expected: 'Subsidieformulier en de lijst met documenten gestuurd.', actual: $mail['subject']);
		$this->assertSame(expected: '{{datetime:-1 16:05}}', actual: $mail['mailDate']);
		$this->assertSame(expected: '{{cast:fatmayildiz.uid}}', actual: $mail['linkedBy']);
		$this->assertNotSame(expected: '', actual: (string)($mail['messageId'] ?? ''));
	}//end testTheSubsidyMailIsLinkedToBuurtverenigingOost()
}//end class
