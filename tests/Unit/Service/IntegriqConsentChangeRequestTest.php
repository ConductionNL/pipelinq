<?php

/**
 * Unit tests for the change request IntegriqMarketingConsent builds per consent record.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ContactAddressLookup;
use OCA\Pipelinq\Service\IntegriqConsentClient;
use OCA\Pipelinq\Service\IntegriqMarketingConsent;
use PHPUnit\Framework\TestCase;

/**
 * Pins the exact change request of every kind of consent record.
 */
final class IntegriqConsentChangeRequestTest extends TestCase {
	/**
	 * Every change request answers exactly as recorded.
	 *
	 * @return void
	 */
	public function testEveryRecordMapsExactly(): void {
		$addresses = $this->createMock(ContactAddressLookup::class);
		$addresses->method('addressFor')->willReturnCallback(
			static fn (string $contactId, string $channel): string => $contactId.'|'.$channel
		);
		$consent = new IntegriqMarketingConsent($this->createMock(IntegriqConsentClient::class), $addresses);
		$marketing = IntegriqConsentClient::CATEGORY_MARKETING;

		$this->assertNull($consent->changeRequestFor(['withdrawnAt' => '2026-01-01', 'withdrawnReason' => 'bounce-hard'], 'r-0'));
		$this->assertNull($consent->changeRequestFor(['withdrawnAt' => '2026-01-01', 'withdrawnReason' => 'bounce-soft-x5'], 'r-0'));
		$this->assertSame(
			[
				'address' => 'c-1|sms',
				'state' => 'opted-in',
				'scope' => 'channel',
				'channel' => 'sms',
				'ref' => '',
				'contactRef' => 'c-1',
				'evidence' => ['text' => 'form'],
				'source' => 'webform',
				'purpose' => $marketing,
				'legacyRef' => 'r-1:in',
				'lawfulBasis' => 'consent',
			],
			$consent->changeRequestFor(['contactId' => 'c-1', 'channel' => 'SMS', 'evidence' => 'form', 'consentSource' => 'webform', 'lawfulBasis' => 'consent', 'withdrawnReason' => 'bounce-hard'], 'r-1')
		);
		$this->assertSame(
			[
				'address' => 'jan@acme.nl|email',
				'state' => 'opted-out',
				'scope' => 'list',
				'channel' => 'email',
				'ref' => 'list-9',
				'contactRef' => '',
				'evidence' => ['ip' => '10.0.0.1'],
				'source' => 'unsubscribe-link',
				'purpose' => $marketing,
				'legacyRef' => 'r-2:out',
			],
			$consent->changeRequestFor(['contactId' => 'jan@acme.nl', 'channel' => 'email', 'listId' => ' list-9 ', 'evidence' => ['ip' => '10.0.0.1'], 'consentSource' => 'form', 'lawfulBasis' => 'consent', 'withdrawnAt' => ' 2026-02-01 ', 'withdrawnReason' => 'unsubscribe-link'], 'r-2')
		);
		$this->assertSame(
			[
				'address' => '|',
				'state' => 'opted-in',
				'scope' => 'channel',
				'channel' => '',
				'ref' => '',
				'contactRef' => '',
				'evidence' => [],
				'source' => '',
				'purpose' => $marketing,
				'legacyRef' => 'r-3:in',
				'lawfulBasis' => '',
			],
			$consent->changeRequestFor([], 'r-3')
		);
	}//end testEveryRecordMapsExactly()
}//end class
