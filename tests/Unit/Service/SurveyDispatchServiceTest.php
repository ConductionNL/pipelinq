<?php

/**
 * Unit tests for SurveyDispatchService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\SurveyDispatchService;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the dispatch rules, the guard chain and the response rate.
 */
class SurveyDispatchServiceTest extends TestCase {
	/**
	 * The configured rules, as JSON.
	 *
	 * @var string
	 */
	private string $rulesJson = '[]';

	/**
	 * The invitations the double holds.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $invitations = [];

	/**
	 * Every object written.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * One enabled rule, as the settings surface would persist it.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The rule.
	 */
	private function rule(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'rule-1',
				'enabled' => true,
				'trigger' => ['entityType' => 'contactmoment', 'statusEquals' => 'closed'],
				'surveyRef' => 'survey-1',
				'channel' => 'email',
				'delayMinutes' => 60,
				'cooldownDays' => 30,
				'expiryDays' => 30,
			],
			$overrides
		);
	}//end rule()

	/**
	 * Build the service over doubles.
	 *
	 * @return SurveyDispatchService The service under test.
	 */
	private function service(): SurveyDispatchService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				$values = [
					'register' => 'reg-1',
					'surveyInvitation_schema' => 'sch-invitation',
					SurveyDispatchService::RULES_KEY => $this->rulesJson,
				];

				return ($values[$key] ?? $default);
			}
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (): array {
				return $this->invitations;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntityInterface {
				$this->written[] = $object;

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($object);
				$entity->method('getUuid')->willReturn('inv-1');

				return $entity;
			}
		);

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('token-abc');

		return new SurveyDispatchService(
			$appConfig,
			$objectService,
			$random,
			$this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * A disabled rule is inert.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-configurable-survey-dispatch-rules
	 */
	public function testADisabledRuleIsInert(): void {
		$this->rulesJson = json_encode([$this->rule(['enabled' => false])]);

		$this->assertSame(
			[],
			$this->service()->matchingRules(entityType: 'contactmoment', status: 'closed')
		);
	}//end testADisabledRuleIsInert()

	/**
	 * An enabled rule matches its trigger and nothing else.
	 *
	 * @return void
	 */
	public function testAnEnabledRuleMatchesItsTrigger(): void {
		$this->rulesJson = json_encode([$this->rule()]);
		$service = $this->service();

		$this->assertCount(1, $service->matchingRules(entityType: 'contactmoment', status: 'closed'));
		$this->assertSame([], $service->matchingRules(entityType: 'contactmoment', status: 'open'));
		$this->assertSame([], $service->matchingRules(entityType: 'request', status: 'closed'));
	}//end testAnEnabledRuleMatchesItsTrigger()

	/**
	 * Malformed rules send nothing, loudly rather than half-heartedly.
	 *
	 * @return void
	 */
	public function testMalformedRulesSendNothing(): void {
		$this->rulesJson = '{not json';

		$this->assertSame([], $this->service()->rules());
	}//end testMalformedRulesSendNothing()

	/**
	 * A contact who opted out is suppressed, with the reason recorded.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
	 */
	public function testAnOptedOutContactIsSuppressed(): void {
		$invitation = $this->service()->buildInvitation(
			rule: $this->rule(),
			contact: ['contactsUid' => 'c1', 'email' => 'jan@example.org', 'surveyOptOut' => true],
			entity: ['id' => 'cm-1'],
		);

		$this->assertSame('suppressed', $invitation['status']);
		$this->assertSame('opt-out', $invitation['suppressionReason']);
	}//end testAnOptedOutContactIsSuppressed()

	/**
	 * The cooldown counts invitations ACROSS surveys.
	 *
	 * Somebody asked about a permit five days ago is not fair game today
	 * because the questionnaire is a different one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
	 */
	public function testTheCooldownCountsAcrossSurveys(): void {
		$now = new DateTimeImmutable('2026-09-18T10:00:00+00:00');

		$invitation = $this->service()->buildInvitation(
			rule: $this->rule(),
			contact: ['contactsUid' => 'c1', 'email' => 'jan@example.org'],
			entity: ['id' => 'cm-1'],
			recentInvitations: [
				['surveyRef' => 'a-different-survey', 'sentAt' => '2026-09-13T10:00:00+00:00'],
			],
			now: $now,
		);

		$this->assertSame('suppressed', $invitation['status']);
		$this->assertSame('cooldown', $invitation['suppressionReason']);
	}//end testTheCooldownCountsAcrossSurveys()

	/**
	 * An invitation outside the cooldown window is scheduled.
	 *
	 * The control: a guard chain that suppressed everything would pass the two
	 * tests above.
	 *
	 * @return void
	 */
	public function testAnInvitationOutsideTheCooldownIsScheduled(): void {
		$now = new DateTimeImmutable('2026-09-18T10:00:00+00:00');

		$invitation = $this->service()->buildInvitation(
			rule: $this->rule(),
			contact: ['contactsUid' => 'c1', 'email' => 'jan@example.org'],
			entity: ['id' => 'cm-1'],
			recentInvitations: [['sentAt' => '2026-01-01T10:00:00+00:00']],
			now: $now,
		);

		$this->assertSame('scheduled', $invitation['status']);
		$this->assertArrayNotHasKey('suppressionReason', $invitation);
		$this->assertSame('token-abc', $invitation['token']);
		$this->assertSame('jan@example.org', $invitation['deliveryAddress']);
		$this->assertSame(
			'2026-09-18T11:00:00+00:00',
			$invitation['scheduledFor'],
			'The rule\'s delay is what the job waits for.'
		);
	}//end testAnInvitationOutsideTheCooldownIsScheduled()

	/**
	 * A contact with no address on the rule's channel is suppressed.
	 *
	 * @return void
	 */
	public function testAContactWithNoAddressIsSuppressed(): void {
		$invitation = $this->service()->buildInvitation(
			rule: $this->rule(),
			contact: ['contactsUid' => 'c1'],
			entity: ['id' => 'cm-1'],
		);

		$this->assertSame('suppressed', $invitation['status']);
		$this->assertSame('no-channel-address', $invitation['suppressionReason']);
	}//end testAContactWithNoAddressIsSuppressed()

	/**
	 * The guards run in order: opt-out outranks cooldown.
	 *
	 * Order matters because the reason is what an administrator reads when
	 * they ask why nobody was invited, and "cooldown" on a contact who opted
	 * out permanently sends them looking at the wrong setting.
	 *
	 * @return void
	 */
	public function testOptOutOutranksCooldown(): void {
		$reason = $this->service()->suppressionReason(
			rule: $this->rule(),
			contact: ['contactsUid' => 'c1', 'email' => 'jan@example.org', 'surveyOptOut' => true],
			recentInvitations: [['sentAt' => (new DateTimeImmutable())->format('c')]],
		);

		$this->assertSame('opt-out', $reason);
	}//end testOptOutOutranksCooldown()

	/**
	 * A suppressed dispatch is PERSISTED, not dropped.
	 *
	 * @return void
	 */
	public function testASuppressedDispatchIsPersisted(): void {
		$this->rulesJson = json_encode([$this->rule(['delayMinutes' => 0])]);

		$written = $this->service()->onInteractionCompleted(
			entityType: 'contactmoment',
			entity: ['id' => 'cm-1', 'status' => 'closed'],
			contact: ['contactsUid' => 'c1', 'email' => 'jan@example.org', 'surveyOptOut' => true],
		);

		$this->assertCount(1, $written);
		$this->assertCount(1, $this->written, 'A suppressed invitation is a fact worth keeping.');
		$this->assertSame('suppressed', $this->written[0]['status']);
	}//end testASuppressedDispatchIsPersisted()

	/**
	 * The response rate excludes suppressed and failed from the denominator.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-response-rate-analytics
	 */
	public function testTheResponseRateExcludesSuppressedAndFailed(): void {
		$invitations = array_merge(
			array_fill(0, 40, ['status' => 'sent']),
			array_fill(0, 10, ['status' => 'responded']),
			array_fill(0, 5, ['status' => 'suppressed']),
			[['status' => 'failed']],
		);

		$figures = $this->service()->responseRate(invitations: $invitations);

		$this->assertSame(50, $figures['delivered']);
		$this->assertSame(10, $figures['responded']);
		$this->assertSame(20.0, $figures['rate']);
		$this->assertSame(5, $figures['suppressed']);
		$this->assertSame(1, $figures['failed']);
	}//end testTheResponseRateExcludesSuppressedAndFailed()

	/**
	 * A survey nobody was sent has no response rate, and says zero delivered.
	 *
	 * @return void
	 */
	public function testASurveyNobodyWasSentHasNoRate(): void {
		$figures = $this->service()->responseRate(
			invitations: [['status' => 'suppressed'], ['status' => 'suppressed']]
		);

		$this->assertSame(0, $figures['delivered']);
		$this->assertSame(0.0, $figures['rate']);
		$this->assertSame(2, $figures['suppressed']);
	}//end testASurveyNobodyWasSentHasNoRate()
}//end class
