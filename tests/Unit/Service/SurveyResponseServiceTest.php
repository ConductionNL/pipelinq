<?php

/**
 * Unit tests for SurveyResponseService and the dispatch job's plan.
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
use OCA\Pipelinq\BackgroundJob\SurveyInvitationDispatchJob;
use OCA\Pipelinq\Service\DetractorFollowUpService;
use OCA\Pipelinq\Service\SurveyDispatchService;
use OCA\Pipelinq\Service\SurveyInvitationSender;
use OCA\Pipelinq\Service\SurveyOptOutService;
use OCA\Pipelinq\Service\SurveyResponseService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the token path and the dispatch job's decision.
 */
class SurveyResponseServiceTest extends TestCase {
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
	 * Build the response service over doubles.
	 *
	 * @return SurveyResponseService The service under test.
	 */
	private function service(): SurveyResponseService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				$values = [
					'register' => 'reg-1',
					'surveyInvitation_schema' => 'sch-invitation',
					'surveyResponse_schema' => 'sch-response',
					'contact_schema' => 'sch-contact',
					'task_schema' => 'sch-task',
				];

				return ($values[$key] ?? $default);
			}
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				return (($config['filters']['schema'] ?? '') === 'sch-invitation' ? $this->invitations : []);
			}
		);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id): ?ObjectEntityInterface {
				if ((string)$id !== 'survey-1') {
					return null;
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn(
					[
						'id' => 'survey-1',
						'title' => 'KTO',
						'questions' => [
							['key' => 'recommend', 'kind' => 'nps'],
							['key' => 'service', 'kind' => 'rating'],
							['key' => 'anything', 'kind' => 'text'],
						],
					]
				);

				return $entity;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntityInterface {
				$this->written[] = $object;

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($object);
				$entity->method('getUuid')->willReturn('resp-1');

				return $entity;
			}
		);

		$dispatchService = $this->getMockBuilder(SurveyDispatchService::class)
			->disableOriginalConstructor()
			->onlyMethods(['read', 'write'])
			->getMock();
		$dispatchService->method('read')->willReturnCallback(
			function (): array {
				return $this->invitations;
			}
		);
		$dispatchService->method('write')->willReturnCallback(
			function (array $invitation): bool {
				$this->written[] = $invitation;

				foreach ($this->invitations as $index => $held) {
					if (($held['token'] ?? '') === ($invitation['token'] ?? '')) {
						$this->invitations[$index] = $invitation;
					}
				}

				return true;
			}
		);

		$followUp = $this->getMockBuilder(DetractorFollowUpService::class)
			->disableOriginalConstructor()
			->onlyMethods(['process'])
			->getMock();
		$followUp->method('process')->willReturnArgument(0);

		return new SurveyResponseService(
			$appConfig,
			$objectService,
			$dispatchService,
			$followUp,
			new SurveyOptOutService($appConfig, $objectService, $this->createMock(LoggerInterface::class)),
			$this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * A sent, unexpired token opens the survey.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
	 */
	public function testASentTokenOpensTheSurvey(): void {
		$this->invitations = [
			[
				'id' => 'inv-1',
				'token' => 'abc',
				'status' => 'sent',
				'surveyRef' => 'survey-1',
				'expiresAt' => '2099-01-01T00:00:00+00:00',
			],
		];

		$result = $this->service()->show(token: 'abc');

		$this->assertSame(200, $result['status']);
		$this->assertSame('KTO', $result['survey']['title']);
	}//end testASentTokenOpensTheSurvey()

	/**
	 * An expired token opens a closed page rather than the form.
	 *
	 * @return void
	 */
	public function testAnExpiredTokenOpensAClosedPage(): void {
		$this->invitations = [
			['id' => 'inv-1', 'token' => 'abc', 'status' => 'sent', 'expiresAt' => '2020-01-01T00:00:00+00:00'],
		];

		$result = $this->service()->show(token: 'abc');

		$this->assertSame(410, $result['status']);
		$this->assertSame('expired', $result['state']);
	}//end testAnExpiredTokenOpensAClosedPage()

	/**
	 * A token nobody was issued is unknown.
	 *
	 * @return void
	 */
	public function testAnUnknownTokenIsRefused(): void {
		$this->invitations = [];

		$this->assertSame(404, $this->service()->show(token: 'guessed')['status']);
	}//end testAnUnknownTokenIsRefused()

	/**
	 * A suppressed invitation's token was never delivered, so it opens nothing.
	 *
	 * @return void
	 */
	public function testASuppressedInvitationsTokenOpensNothing(): void {
		$this->invitations = [
			['id' => 'inv-1', 'token' => 'abc', 'status' => 'suppressed', 'suppressionReason' => 'opt-out'],
		];

		$this->assertSame(404, $this->service()->show(token: 'abc')['status']);
	}//end testASuppressedInvitationsTokenOpensNothing()

	/**
	 * Submitting creates a response and spends the token.
	 *
	 * @return void
	 */
	public function testSubmittingCreatesAResponseAndSpendsTheToken(): void {
		$this->invitations = [
			[
				'id' => 'inv-1',
				'token' => 'abc',
				'status' => 'sent',
				'surveyRef' => 'survey-1',
				'clientRef' => 'client-1',
				'contactRef' => 'c1',
				'expiresAt' => '2099-01-01T00:00:00+00:00',
			],
		];

		$service = $this->service();
		$first = $service->submit(
			token: 'abc',
			answers: ['recommend' => 3, 'service' => 2, 'anything' => 'Het duurde lang'],
		);

		$this->assertSame(201, $first['status']);
		$this->assertSame(3, $first['response']['npsScore']);
		$this->assertSame(2.0, $first['response']['averageRating']);
		$this->assertSame('Het duurde lang', $first['response']['verbatim']);
		$this->assertSame('inv-1', $first['response']['invitationRef']);
		$this->assertSame('client-1', $first['response']['clientRef']);

		// Single use: the second submission is refused, and no second response
		// is written.
		$before = count($this->written);
		$second = $service->submit(token: 'abc', answers: ['recommend' => 10]);

		$this->assertSame(410, $second['status']);
		$this->assertSame('closed', $second['state']);
		$this->assertCount($before, $this->written, 'A spent token writes nothing.');
	}//end testSubmittingCreatesAResponseAndSpendsTheToken()

	/**
	 * The job sends what is due, expires what is stale and leaves the rest.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-automated-invitation-dispatch-on-interaction-completion
	 */
	public function testTheJobPlansWhatIsDue(): void {
		$job = new SurveyInvitationDispatchJob(
			$this->createMock(ITimeFactory::class),
			$this->getMockBuilder(SurveyDispatchService::class)
				->disableOriginalConstructor()
				->onlyMethods(['read', 'write'])
				->getMock(),
			$this->getMockBuilder(SurveyInvitationSender::class)
				->disableOriginalConstructor()
				->onlyMethods(['send'])
				->getMock(),
			$this->createMock(LoggerInterface::class),
		);

		$now = new DateTimeImmutable('2026-09-18T12:00:00+00:00');

		$plan = $job->plan(
			invitations: [
				['id' => 'due', 'status' => 'scheduled', 'scheduledFor' => '2026-09-18T11:00:00+00:00', 'expiresAt' => '2026-10-18T11:00:00+00:00'],
				['id' => 'waiting', 'status' => 'scheduled', 'scheduledFor' => '2026-09-18T13:00:00+00:00', 'expiresAt' => '2026-10-18T11:00:00+00:00'],
				['id' => 'stale', 'status' => 'scheduled', 'scheduledFor' => '2026-08-18T11:00:00+00:00', 'expiresAt' => '2026-09-01T11:00:00+00:00'],
				['id' => 'already-sent', 'status' => 'sent'],
				['id' => 'no-delay', 'status' => 'scheduled'],
			],
			now: $now,
		);

		$acts = [];
		foreach ($plan as $step) {
			$acts[(string)$step['invitation']['id']] = $step['act'];
		}

		$this->assertSame('send', $acts['due']);
		$this->assertSame('expire', $acts['stale']);
		$this->assertSame('send', $acts['no-delay'], 'A rule with no delay is due now.');
		$this->assertArrayNotHasKey('waiting', $acts);
		$this->assertArrayNotHasKey('already-sent', $acts);
	}//end testTheJobPlansWhatIsDue()
}//end class
