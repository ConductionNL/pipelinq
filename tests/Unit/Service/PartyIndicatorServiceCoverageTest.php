<?php

/**
 * Coverage tests for PartyIndicatorService's acknowledgement, merge and read paths.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\PartyIndicatorService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Error paths and edge cases of the party indicator service.
 *
 * @covers \OCA\Pipelinq\Service\PartyIndicatorService
 */
class PartyIndicatorServiceCoverageTest extends TestCase {
	/**
	 * The app-config contents.
	 *
	 * @var array<string, string>
	 */
	private array $config = [
		'register' => 'reg-1',
		'partyIndicator_schema' => 'sch-ind',
		'partyIndicatorValue_schema' => 'sch-val',
	];

	/**
	 * The object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The user session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->session = $this->createMock(IUserSession::class);
	}//end setUp()

	/**
	 * Build the service over the current config.
	 *
	 * @return PartyIndicatorService
	 */
	private function service(): PartyIndicatorService {
		$config = $this->config;
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				return ($config[$key] ?? $default);
			}
		);

		return new PartyIndicatorService($appConfig, $this->objectService, $this->session, $this->logger);
	}//end service()

	/**
	 * An entity double whose jsonSerialize returns the given data.
	 *
	 * @param mixed $data The serialized form.
	 *
	 * @return ObjectEntityInterface&MockObject
	 */
	private function entity(mixed $data): ObjectEntityInterface&MockObject {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * A blank party id resolves to nothing without reading.
	 *
	 * @return void
	 */
	public function testBlankPartyResolvesToNothing(): void {
		$this->objectService->expects($this->never())->method('findAll');

		$this->assertSame([], $this->service()->resolve('   '));
	}//end testBlankPartyResolvesToNothing()

	/**
	 * Acknowledging without an id is a 400 and reads nothing.
	 *
	 * @return void
	 */
	public function testAcknowledgeWithoutIdIsRefused(): void {
		$this->objectService->expects($this->never())->method('find');

		$this->assertSame(
			['status' => 400, 'error' => 'An indicator value is required.'],
			$this->service()->acknowledge('  ')
		);
	}//end testAcknowledgeWithoutIdIsRefused()

	/**
	 * Acknowledging on an unconfigured value schema is a 400.
	 *
	 * @return void
	 */
	public function testAcknowledgeWithoutSchemaIsRefused(): void {
		unset($this->config['partyIndicatorValue_schema']);
		$this->objectService->expects($this->never())->method('find');

		$this->assertSame(400, $this->service()->acknowledge('v-1')['status']);
	}//end testAcknowledgeWithoutSchemaIsRefused()

	/**
	 * A value that cannot be read (exception) is a 404, logged at debug.
	 *
	 * @return void
	 */
	public function testAcknowledgeUnreadableValueIs404(): void {
		$this->objectService->expects($this->once())->method('find')->with('v-1')
			->willThrowException(new RuntimeException('gone'));
		$this->objectService->expects($this->never())->method('saveObject');
		$this->logger->expects($this->once())->method('debug')->with(
			'PartyIndicatorService: could not read the indicator value',
			['uuid' => 'v-1', 'exception' => 'gone']
		);

		$this->assertSame(
			['status' => 404, 'error' => 'That indicator value could not be read.'],
			$this->service()->acknowledge('v-1')
		);
	}//end testAcknowledgeUnreadableValueIs404()

	/**
	 * A missing value is a 404.
	 *
	 * @return void
	 */
	public function testAcknowledgeMissingValueIs404(): void {
		$this->objectService->method('find')->willReturn(null);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertSame(404, $this->service()->acknowledge('v-1')['status']);
	}//end testAcknowledgeMissingValueIs404()

	/**
	 * A value that does not serialize to an array is a 404.
	 *
	 * @return void
	 */
	public function testAcknowledgeNonArrayValueIs404(): void {
		$this->objectService->method('find')->willReturn($this->entity('not-an-array'));
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertSame(404, $this->service()->acknowledge('v-1')['status']);
	}//end testAcknowledgeNonArrayValueIs404()

	/**
	 * A successful acknowledgement records who and when, and saves in scope.
	 *
	 * @return void
	 */
	public function testAcknowledgeRecordsUserAndSaves(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('maria');
		$this->session->method('getUser')->willReturn($user);
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'v-1', 'indicator' => 'x']));
		$this->objectService->expects($this->once())->method('saveObject')
			->with(
				$this->callback(static fn (array $o): bool => $o['acknowledgedBy'] === 'maria' && $o['indicator'] === 'x'),
				$this->anything(),
				'reg-1',
				'sch-val',
				'v-1'
			)
			->willReturn($this->entity([]));

		$result = $this->service()->acknowledge(' v-1 ');

		$this->assertSame(200, $result['status']);
		$this->assertSame('maria', $result['indicatorValue']['acknowledgedBy']);
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
			$result['indicatorValue']['acknowledgedAt']
		);
	}//end testAcknowledgeRecordsUserAndSaves()

	/**
	 * Without a logged-in user the acknowledgement is recorded with an empty user.
	 *
	 * @return void
	 */
	public function testAcknowledgeWithoutUserRecordsEmptyUser(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'v-1']));
		$this->objectService->method('saveObject')->willReturn($this->entity([]));

		$this->assertSame('', $this->service()->acknowledge('v-1')['indicatorValue']['acknowledgedBy']);
	}//end testAcknowledgeWithoutUserRecordsEmptyUser()

	/**
	 * A failing save is logged and answered with a 500.
	 *
	 * @return void
	 */
	public function testAcknowledgeSaveFailureIs500(): void {
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'v-1']));
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('db'));
		$this->logger->expects($this->once())->method('error')->with(
			'PartyIndicatorService: the acknowledgement could not be saved',
			['uuid' => 'v-1', 'exception' => 'db']
		);

		$this->assertSame(
			['status' => 500, 'error' => 'The acknowledgement could not be saved.'],
			$this->service()->acknowledge('v-1')
		);
	}//end testAcknowledgeSaveFailureIs500()

	/**
	 * Merge drops values without a code and accepts `code` as the key.
	 *
	 * @return void
	 */
	public function testMergeSkipsCodelessValuesAndReadsCodeKey(): void {
		$union = $this->service()->unionForMerge(
			[['indicator' => '  '], ['code' => 'overleden']],
			[['foo' => 'bar']]
		);

		$this->assertSame([['code' => 'overleden']], $union);
	}//end testMergeSkipsCodelessValuesAndReadsCodeKey()

	/**
	 * Merge keeps an open winner over a closed loser.
	 *
	 * @return void
	 */
	public function testMergeKeepsOpenWinner(): void {
		$open = ['indicator' => 'x', 'validUntil' => ''];
		$closed = ['indicator' => 'x', 'validUntil' => '2026-01-01'];

		$this->assertSame([$open], $this->service()->unionForMerge([$open], [$closed]));
	}//end testMergeKeepsOpenWinner()

	/**
	 * Of two closed periods, the later end wins, whichever side it is on.
	 *
	 * @return void
	 */
	public function testMergeKeepsTheLaterEnd(): void {
		$early = ['indicator' => 'x', 'validUntil' => '2026-01-01'];
		$late = ['indicator' => 'x', 'validUntil' => '2026-06-01'];

		$this->assertSame([$late], $this->service()->unionForMerge([$early], [$late]));
		$this->assertSame([$late], $this->service()->unionForMerge([$late], [$early]));
	}//end testMergeKeepsTheLaterEnd()

	/**
	 * An unparsable date counts as absent: an unusable end means open.
	 *
	 * @return void
	 */
	public function testUnparsableDateIsTreatedAsAbsent(): void {
		$service = $this->service();
		$day = new DateTimeImmutable('2026-03-01');

		$this->assertTrue($service->applies(['validFrom' => 'not a date', 'validUntil' => 'garbage'], $day));
		$this->assertFalse($service->applies(['validUntil' => '2026-02-28'], $day));
	}//end testUnparsableDateIsTreatedAsAbsent()

	/**
	 * Without a register nothing is read, and the gap is logged.
	 *
	 * @return void
	 */
	public function testUnconfiguredRegisterReadsNothing(): void {
		unset($this->config['register']);
		$this->objectService->expects($this->never())->method('findAll');
		$this->logger->expects($this->atLeastOnce())->method('warning')->with(
			$this->stringContains('app-config "register" is not configured')
		);
		$this->logger->expects($this->exactly(2))->method('debug')->with(
			'PartyIndicatorService: the indicator surface is not configured',
			$this->callback(static fn (array $c): bool => in_array($c['schemaKey'], ['partyIndicator_schema', 'partyIndicatorValue_schema'], true))
		);

		$this->assertSame([], $this->service()->resolve('party-1'));
	}//end testUnconfiguredRegisterReadsNothing()

	/**
	 * A failing read is logged and yields no indicators.
	 *
	 * @return void
	 */
	public function testFailingReadYieldsNothing(): void {
		$this->objectService->expects($this->exactly(2))->method('findAll')
			->willThrowException(new RuntimeException('down'));
		$this->logger->expects($this->exactly(2))->method('warning')->with(
			'PartyIndicatorService: the indicator read failed',
			$this->callback(static fn (array $c): bool => $c['exception'] === 'down')
		);

		$this->assertSame([], $this->service()->resolve('party-1'));
	}//end testFailingReadYieldsNothing()

	/**
	 * Entity rows are serialized; rows that do not serialize to arrays are skipped.
	 *
	 * @return void
	 */
	public function testEntityRowsAreSerialized(): void {
		$vocab = [$this->entity(['code' => 'overleden', 'label' => 'Overleden', 'blocksOutbound' => true]), $this->entity('junk')];
		$values = [
			$this->entity(['id' => 'v-1', 'indicator' => 'overleden']),
			'scalar row',
			$this->entity(null),
		];
		$this->objectService->expects($this->exactly(2))->method('findAll')->willReturnCallback(
			static function (array $config) use ($vocab, $values): array {
				return ($config['filters']['schema'] === 'sch-ind' ? $vocab : $values);
			}
		);

		$answer = $this->service()->isBlocked('party-1', 'send', new DateTimeImmutable('2026-03-01'));

		$this->assertTrue($answer['blocked']);
		$this->assertCount(1, $answer['indicators']);
		$this->assertSame('v-1', $answer['indicators'][0]['id']);
		$this->assertSame('Overleden', $answer['indicators'][0]['label']);
	}//end testEntityRowsAreSerialized()
}//end class
