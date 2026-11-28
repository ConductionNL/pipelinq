<?php

/**
 * Unit tests: an inbound call or email notifies with an "Open client" button.
 *
 * Reads the merged register (monolith plus every fragment in register.d, in
 * the loader's own order), which is what OpenRegister imports.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pipelinq-open-client-on-contactmoment/tasks.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\ConfigFileLoaderService
 * @uses \OCA\Pipelinq\Service\ConfigFragmentValue
 */
class OpenClientOnContactMomentTest extends TestCase {

	/**
	 * The merged ticket schema's notification rules.
	 *
	 * @var array<string, mixed>
	 */
	private array $rules;

	/**
	 * The merged ticket schema.
	 *
	 * @var array<string, mixed>
	 */
	private array $ticket;

	/**
	 * Load the merged register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));
		$config = (new ConfigFileLoaderService($appManager))->loadConfigurationFile();
		$this->ticket = $config['components']['schemas']['ticket'];
		$this->rules = $this->ticket['x-openregister-notifications'];
	}//end setUp()

	/**
	 * The channel value each rule fires on, and its nl/en subject.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function inboundRules(): array {
		return [
			'call' => ['incomingCall', 'telefoon', 'Inkomende oproep van {{client}}', 'Incoming call from {{client}}'],
			'email' => ['incomingEmail', 'email', 'Inkomende e-mail van {{client}}', 'Incoming email from {{client}}'],
		];
	}//end inboundRules()

	/**
	 * Each rule fires on a created ticket of its channel, as pipelinq, in Nextcloud and as a web push.
	 *
	 * @param string $ruleId    The rule.
	 * @param string $channel   The channel value it filters on.
	 * @param string $subjectNl The Dutch subject.
	 * @param string $subjectEn The English subject.
	 *
	 * @return void
	 *
	 * @dataProvider inboundRules
	 *
	 * @spec openspec/changes/pipelinq-open-client-on-contactmoment/specs/contactmomenten/spec.md
	 */
	public function testTheRuleFiresOnACreatedTicketOfItsChannel(string $ruleId, string $channel, string $subjectNl, string $subjectEn): void {
		$rule = $this->rules[$ruleId];

		$this->assertSame(['type' => 'created', 'filter' => ['field' => 'channel', 'operator' => 'equals', 'value' => $channel]], $rule['trigger']);
		$this->assertTrue($rule['enabled']);
		$this->assertSame('pipelinq', $rule['originApp']);
		$this->assertSame(['nc-notification', 'web-push'], $rule['channels']);
		$this->assertSame(['nl' => $subjectNl, 'en' => $subjectEn], $rule['subject']);
		$this->assertArrayHasKey('channel', $this->ticket['properties'], 'the filter names a ticket field');
	}//end testTheRuleFiresOnACreatedTicketOfItsChannel()

	/**
	 * Each rule notifies the ticket's handler and the sales group.
	 *
	 * @param string $ruleId The rule.
	 *
	 * @return void
	 *
	 * @dataProvider inboundRules
	 *
	 * @spec openspec/changes/pipelinq-open-client-on-contactmoment/specs/contactmomenten/spec.md
	 */
	public function testTheRuleNotifiesTheHandlerAndTheSalesGroup(string $ruleId): void {
		$this->assertSame(
			[['kind' => 'field', 'field' => 'assignee'], ['kind' => 'groups', 'groups' => ['sales']]],
			$this->rules[$ruleId]['recipients']
		);
		$this->assertSame('user', $this->ticket['properties']['assignee']['format'], 'the recipient field holds a user');
	}//end testTheRuleNotifiesTheHandlerAndTheSalesGroup()

	/**
	 * Each rule has one primary "Open client" action resolved through the client relation.
	 *
	 * @param string $ruleId The rule.
	 *
	 * @return void
	 *
	 * @dataProvider inboundRules
	 *
	 * @spec openspec/changes/pipelinq-open-client-on-contactmoment/specs/contactmomenten/spec.md
	 */
	public function testTheRuleOpensTheRelatedClient(string $ruleId): void {
		$this->assertSame(
			[
				[
					'label' => ['nl' => 'Klant openen', 'en' => 'Open client'],
					'primary' => true,
					'target' => ['kind' => 'object-detail', 'object' => ['kind' => 'relation', 'field' => 'client']],
				],
			],
			$this->rules[$ruleId]['actions']
		);
		$this->assertSame('client', $this->ticket['properties']['client']['$ref'], 'the relation field points at a client');
	}//end testTheRuleOpensTheRelatedClient()

	/**
	 * The ticket's other rules are left as they were.
	 *
	 * @return void
	 */
	public function testTheTicketsOtherRulesAreKept(): void {
		$this->assertEqualsCanonicalizing(
			expected: ['newTicket', 'ticketUpdated', 'incomingCall', 'incomingEmail', 'chatWaiting'],
			actual: array_keys($this->rules),
		);
		$this->assertSame(['type' => 'created'], $this->rules['newTicket']['trigger']);
	}//end testTheTicketsOtherRulesAreKept()
}//end class
