<?php

/**
 * Unit tests for PartyIndicatorService.
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
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\PartyIndicatorService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the standing indicators on a party.
 */
class PartyIndicatorServiceTest extends TestCase {
	/**
	 * The declared vocabulary the double answers with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $vocabulary = [];

	/**
	 * The stored values the double answers with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $values = [];

	/**
	 * Build the service over doubles.
	 *
	 * @return PartyIndicatorService The service under test.
	 */
	private function service(): PartyIndicatorService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				$values = [
					'register' => 'reg-1',
					'partyIndicator_schema' => 'sch-ind',
					'partyIndicatorValue_schema' => 'sch-val',
				];

				return ($values[$key] ?? $default);
			}
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				$schema = ($config['filters']['schema'] ?? '');

				return ($schema === 'sch-ind' ? $this->vocabulary : $this->values);
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('maria');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new PartyIndicatorService(
			$appConfig,
			$objectService,
			$session,
			$this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * A value past its end date stops applying, with nobody editing it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-an-indicator-shall-be-a-declared-vocabulary-with-dated-values-req-pfi-002
	 */
	public function testALiftedIndicatorStopsApplyingOnItsOwnDate(): void {
		$this->vocabulary = [['code' => 'geheimhouding-adres', 'label' => 'Geheimhouding adres', 'severity' => 'warning']];
		$this->values = [
			[
				'id' => 'v1',
				'party' => 'party-1',
				'indicator' => 'geheimhouding-adres',
				'validFrom' => '2026-01-01',
				'validUntil' => '2026-09-17',
			],
		];

		$service = $this->service();

		$this->assertCount(
			1,
			$service->resolve(partyId: 'party-1', onDay: new DateTimeImmutable('2026-09-17')),
			'On its last day it still applies.'
		);
		$this->assertSame(
			[],
			$service->resolve(partyId: 'party-1', onDay: new DateTimeImmutable('2026-09-18')),
			'The day after, it is gone, and nobody edited it.'
		);
	}//end testALiftedIndicatorStopsApplyingOnItsOwnDate()

	/**
	 * A value that has not started yet does not apply.
	 *
	 * @return void
	 */
	public function testAFutureValueDoesNotApplyYet(): void {
		$service = $this->service();

		$this->assertFalse(
			$service->applies(['validFrom' => '2027-01-01'], new DateTimeImmutable('2026-09-18'))
		);
		$this->assertTrue(
			$service->applies(['validFrom' => '2026-01-01'], new DateTimeImmutable('2026-09-18'))
		);
	}//end testAFutureValueDoesNotApplyYet()

	/**
	 * Each value names the source that set it.
	 *
	 * @return void
	 */
	public function testTheSourceOfAFlagIsRecorded(): void {
		$this->vocabulary = [
			['code' => 'overleden', 'label' => 'Overleden', 'severity' => 'critical', 'blocksOutbound' => true],
			['code' => 'agressie-registratie', 'label' => 'Agressie-registratie', 'severity' => 'warning'],
		];
		$this->values = [
			['id' => 'v1', 'party' => 'p', 'indicator' => 'overleden', 'validFrom' => '2026-01-01', 'source' => 'brp-lookup'],
			['id' => 'v2', 'party' => 'p', 'indicator' => 'agressie-registratie', 'validFrom' => '2026-01-01', 'source' => 'kcc-supervisor'],
		];

		$resolved = $this->service()->resolve(partyId: 'p');

		$this->assertSame(['brp-lookup', 'kcc-supervisor'], array_column($resolved, 'source'));
	}//end testTheSourceOfAFlagIsRecorded()

	/**
	 * A send to a deceased party is refused, and the refusal names why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-pipelinq-shall-answer-whether-an-indicator-blocks-an-act-and-shall-not-intercept-it-req-pfi-004
	 */
	public function testASendToADeceasedPartyIsBlockedWithAReason(): void {
		$this->vocabulary = [
			['code' => 'overleden', 'label' => 'Overleden', 'severity' => 'critical', 'blocksOutbound' => true],
		];
		$this->values = [['id' => 'v1', 'party' => 'p', 'indicator' => 'overleden', 'validFrom' => '2026-01-01']];

		$answer = $this->service()->isBlocked(partyId: 'p', act: 'send');

		$this->assertTrue($answer['blocked']);
		$this->assertSame('overleden', $answer['indicators'][0]['code']);
		$this->assertSame('Overleden', $answer['indicators'][0]['label']);
		$this->assertSame('critical', $answer['indicators'][0]['severity']);
	}//end testASendToADeceasedPartyIsBlockedWithAReason()

	/**
	 * Publication is answered separately from sending.
	 *
	 * The control that keeps the blocking answer from being "yes to
	 * everything": a party carrying only the address flag may still be
	 * written to.
	 *
	 * @return void
	 */
	public function testPublicationIsAnsweredSeparatelyFromSending(): void {
		$this->vocabulary = [
			[
				'code' => 'geheimhouding-adres',
				'label' => 'Geheimhouding adres',
				'severity' => 'warning',
				'blocksAddressPublication' => true,
			],
		];
		$this->values = [['id' => 'v1', 'party' => 'p', 'indicator' => 'geheimhouding-adres', 'validFrom' => '2026-01-01']];

		$service = $this->service();

		$this->assertFalse($service->isBlocked(partyId: 'p', act: 'send')['blocked']);
		$this->assertTrue($service->isBlocked(partyId: 'p', act: 'publishAddress')['blocked']);
	}//end testPublicationIsAnsweredSeparatelyFromSending()

	/**
	 * An act this service does not answer about is refused, not guessed.
	 *
	 * @return void
	 */
	public function testAnUnknownActIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service()->isBlocked(partyId: 'p', act: 'invoice');
	}//end testAnUnknownActIsRefused()

	/**
	 * A merge unions indicators rather than picking a winner.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-a-merge-shall-survive-field-values-and-shall-union-indicators-req-pfi-006
	 */
	public function testAMergeUnionsIndicators(): void {
		$service = $this->service();

		$union = $service->unionForMerge(
			winner: [['indicator' => 'agressie-registratie', 'validFrom' => '2026-01-01']],
			loser: [['indicator' => 'overleden', 'validFrom' => '2026-02-01']],
		);

		$codes = array_column($union, 'indicator');
		sort($codes);
		$this->assertSame(['agressie-registratie', 'overleden'], $codes);
	}//end testAMergeUnionsIndicators()

	/**
	 * Of two values for one code, the open one survives the merge.
	 *
	 * A closed period must not end a standing flag: that is the silent drop
	 * this rule exists to prevent.
	 *
	 * @return void
	 */
	public function testAnOpenPeriodBeatsAClosedOneOnMerge(): void {
		$union = $this->service()->unionForMerge(
			winner: [['indicator' => 'overleden', 'validFrom' => '2026-01-01', 'validUntil' => '2026-03-01']],
			loser: [['indicator' => 'overleden', 'validFrom' => '2026-01-01']],
		);

		$this->assertCount(1, $union);
		$this->assertArrayNotHasKey('validUntil', $union[0]);
	}//end testAnOpenPeriodBeatsAClosedOneOnMerge()

	/**
	 * A value naming an undeclared indicator is reported, not dropped.
	 *
	 * @return void
	 */
	public function testAValueAgainstAnUndeclaredIndicatorIsStillReported(): void {
		$this->vocabulary = [];
		$this->values = [['id' => 'v1', 'party' => 'p', 'indicator' => 'bewindvoering', 'validFrom' => '2026-01-01']];

		$resolved = $this->service()->resolve(partyId: 'p');

		$this->assertCount(1, $resolved);
		$this->assertSame('bewindvoering', $resolved[0]['code']);
	}//end testAValueAgainstAnUndeclaredIndicatorIsStillReported()
}//end class
