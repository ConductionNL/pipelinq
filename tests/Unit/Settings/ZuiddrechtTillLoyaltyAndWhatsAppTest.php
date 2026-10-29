<?php

/**
 * Example data for PqKassaAfrekenen and PqBerichtSturen: the till finds Sanne's loyalty account through the parked
 * sale's customer, and Anna de Vries can be sent a WhatsApp message with an approved template.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The till's loyalty account and the WhatsApp template in the Zuiddrecht set.
 */
class ZuiddrechtTillLoyaltyAndWhatsAppTest extends TestCase {

	/**
	 * The fragment with its parts' lists appended, as the loader reads it.
	 *
	 * @return array<string, mixed> The fragment.
	 */
	private function fragment(): array {
		$folder   = __DIR__.'/../../../lib/Settings/example-sets';
		$fragment = json_decode((string)file_get_contents($folder.'/zuiddrecht.json'), true);
		$parts    = glob($folder.'/zuiddrecht.d/*.json');
		if ($parts === false) {
			$parts = [];
		}

		sort($parts);
		foreach ($parts as $file) {
			foreach (json_decode((string)file_get_contents($file), true) as $key => $value) {
				if (is_array($value) === true && array_is_list($value) === true) {
					$fragment[$key] = array_merge(($fragment[$key] ?? []), $value);
				}
			}
		}

		return $fragment;
	}//end fragment()

	/**
	 * The till asks for the loyalty account of the sale's customer, a contact; Sanne's account names that contact.
	 *
	 * @return void
	 */
	public function testTheParkedSalesCustomerHasTheLoyaltyAccount(): void {
		$objects = array_column($this->fragment()['objects'], null, 'key');
		$sale    = $objects['kassa-geparkeerd-sanne']['data'];
		$account = $objects['spaarpas-account-sanne']['data'];

		$this->assertSame(expected: '{{object:contact-sanne-thuis.id}}', actual: $sale['customer']);
		$this->assertSame(expected: $sale['customer'], actual: $account['customerId']);
		$this->assertSame(expected: 340, actual: $account['currentBalance']);
	}//end testTheParkedSalesCustomerHasTheLoyaltyAccount()

	/**
	 * The WhatsApp provider has an approved reminder template with two parameters, the day and the time.
	 *
	 * @return void
	 */
	public function testAnApprovedWhatsAppTemplateIsOffered(): void {
		$objects  = array_column($this->fragment()['objects'], null, 'key');
		$template = $objects['whatsapp-sjabloon-herinnering-afspraak'];

		$this->assertSame(expected: 'messageTemplate', actual: $template['schema']);
		$this->assertSame(expected: '{{object:kanaal-whatsapp-zuiddrecht.id}}', actual: $template['data']['providerId']);
		$this->assertSame(expected: 'approved', actual: $template['data']['status']);
		$this->assertSame(expected: 'nl', actual: $template['data']['language']);
		preg_match_all(pattern: '/\{\{(\d+)\}\}/', subject: $template['data']['body'], matches: $placeholders);
		$this->assertSame(expected: ['1', '2'], actual: $placeholders[1]);
	}//end testAnApprovedWhatsAppTemplateIsOffered()

	/**
	 * Anna wrote on WhatsApp today, so the 24-hour session window is open when Pieter writes back.
	 *
	 * @return void
	 */
	public function testAnnasWhatsAppSessionIsOpenToday(): void {
		$objects = array_column($this->fragment()['objects'], null, 'key');
		$message = $objects['whatsapp-bericht-anna-vandaag']['data'];

		$this->assertSame(expected: '{{object:contact-anna-devries.id}}', actual: $message['contactId']);
		$this->assertSame(expected: 'whatsapp', actual: $message['channel']);
		$this->assertSame(expected: 'inbound', actual: $message['direction']);
		$this->assertMatchesRegularExpression(pattern: '/^\{\{datetime:0 \d\d:\d\d\}\}$/', string: $message['sentAt']);
	}//end testAnnasWhatsAppSessionIsOpenToday()
}//end class
