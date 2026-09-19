<?php

/**
 * Unit tests for PartyKindRegistryService and PartyLinkService.
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

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\PartyKindRegistryService;
use OCA\Pipelinq\Service\PartyLinkService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the party kind vocabulary, the acceptance and the refusals.
 */
class PartyKindRegistryServiceTest extends TestCase {
	/**
	 * The declared kinds the double answers with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $kinds = [];

	/**
	 * The declared acceptances the double answers with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $acceptances = [];

	/**
	 * The party links the double holds.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $links = [];

	/**
	 * Every object written.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * Seed the vocabulary every test uses.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->kinds = [
			['code' => 'aanvrager', 'label' => 'Aanvrager', 'maxPerRecord' => 1, 'active' => true],
			['code' => 'gemachtigde', 'label' => 'Gemachtigde', 'maxPerRecord' => 1, 'active' => true],
			['code' => 'belanghebbende', 'label' => 'Belanghebbende', 'maxPerRecord' => 0, 'active' => true],
			['code' => 'vergunninghouder', 'label' => 'Vergunninghouder', 'maxPerRecord' => 0, 'active' => true],
			['code' => 'oudeRol', 'label' => 'Oude rol', 'maxPerRecord' => 0, 'active' => false],
		];
	}//end setUp()

	/**
	 * The object service double over the seeded state.
	 *
	 * @return ObjectServiceInterface The double.
	 */
	private function objectService(): ObjectServiceInterface {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				$schema = ($config['filters']['schema'] ?? '');

				return match ($schema) {
					'sch-kind' => $this->kinds,
					'sch-acceptance' => $this->acceptances,
					'sch-link' => $this->links,
					default => [],
				};
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntityInterface {
				$this->written[] = $object;
				$this->links[] = $object;

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($object);

				return $entity;
			}
		);

		return $objectService;
	}//end objectService()

	/**
	 * Build the registry over doubles.
	 *
	 * @param ObjectServiceInterface|null $objectService An object service to reuse.
	 *
	 * @return PartyKindRegistryService The service under test.
	 */
	private function registry(?ObjectServiceInterface $objectService = null): PartyKindRegistryService {
		return new PartyKindRegistryService(
			$this->appConfig(),
			($objectService ?? $this->objectService()),
			$this->createMock(LoggerInterface::class),
		);
	}//end registry()

	/**
	 * The app config double.
	 *
	 * @return IAppConfig The double.
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				$values = [
					'register' => 'reg-1',
					'partyKind_schema' => 'sch-kind',
					'partyKindAcceptance_schema' => 'sch-acceptance',
					'partyLink_schema' => 'sch-link',
				];

				return ($values[$key] ?? $default);
			}
		);

		return $appConfig;
	}//end appConfig()

	/**
	 * A record type offers exactly the kinds it declared, in that order.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-kinds-accepted-per-case-type/specs/party-kind-registry/spec.md#requirement-the-picker-shall-offer-only-the-declared-kinds-in-the-declared-order-req-pkr-003
	 */
	public function testAPickerOffersTheDeclaredKindsInOrder(): void {
		$this->acceptances = [
			['recordType' => 'dossiq:zaak:subsidie', 'kinds' => ['aanvrager', 'gemachtigde']],
			['recordType' => 'dossiq:zaak:woo', 'kinds' => ['belanghebbende']],
		];

		$offered = $this->registry()->kindsFor(recordType: 'dossiq:zaak:subsidie');

		$this->assertSame(['aanvrager', 'gemachtigde'], array_column($offered, 'code'));
	}//end testAPickerOffersTheDeclaredKindsInOrder()

	/**
	 * The declared order is not re-sorted for display.
	 *
	 * @return void
	 */
	public function testTheDeclaredOrderIsNotResorted(): void {
		$this->acceptances = [
			['recordType' => 'dossiq:zaak:subsidie', 'kinds' => ['gemachtigde', 'aanvrager']],
		];

		$offered = $this->registry()->kindsFor(recordType: 'dossiq:zaak:subsidie');

		$this->assertSame(['gemachtigde', 'aanvrager'], array_column($offered, 'code'));
	}//end testTheDeclaredOrderIsNotResorted()

	/**
	 * An undeclared record type still works, offering every active kind.
	 *
	 * @return void
	 */
	public function testAnUndeclaredRecordTypeOffersEveryActiveKind(): void {
		$this->acceptances = [];

		$offered = $this->registry()->kindsFor(recordType: 'dossiq:zaak:onbekend');

		$codes = array_column($offered, 'code');
		$this->assertContains('aanvrager', $codes);
		$this->assertNotContains('oudeRol', $codes, 'An inactive kind is not offered in the picker.');
	}//end testAnUndeclaredRecordTypeOffersEveryActiveKind()

	/**
	 * A retired kind keeps resolving on links already written.
	 *
	 * @return void
	 */
	public function testARetiredKindStillResolves(): void {
		$vocabulary = $this->registry()->vocabulary();

		$this->assertArrayHasKey('oudeRol', $vocabulary);
		$this->assertSame('Oude rol', $vocabulary['oudeRol']['label']);
	}//end testARetiredKindStillResolves()

	/**
	 * An API caller cannot go around the picker.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-kinds-accepted-per-case-type/specs/party-kind-registry/spec.md#requirement-a-party-link-with-an-unaccepted-kind-shall-be-refused-on-the-write-req-pkr-004
	 */
	public function testAnUnacceptedKindIsRefusedOnTheApi(): void {
		$this->acceptances = [
			['recordType' => 'dossiq:zaak:subsidie', 'kinds' => ['aanvrager', 'gemachtigde']],
		];

		$objectService = $this->objectService();
		$service = new PartyLinkService(
			$this->appConfig(),
			$objectService,
			$this->registry($objectService),
			$this->createMock(LoggerInterface::class),
		);

		$result = $service->link(
			recordType: 'dossiq:zaak:subsidie',
			recordId: 'zaak-1',
			party: 'party-1',
			kind: 'vergunninghouder',
		);

		$this->assertSame(409, $result['status']);
		$this->assertStringContainsString('vergunninghouder', $result['error']);
		$this->assertStringContainsString('dossiq:zaak:subsidie', $result['error']);
		$this->assertSame([], $this->written, 'A refused link writes nothing.');
	}//end testAnUnacceptedKindIsRefusedOnTheApi()

	/**
	 * Nothing is refused for a record type that declared nothing.
	 *
	 * The control: a rule that refused everything would pass the test above.
	 *
	 * @return void
	 */
	public function testNothingIsRefusedForAnUndeclaredRecordType(): void {
		$this->acceptances = [];

		$objectService = $this->objectService();
		$service = new PartyLinkService(
			$this->appConfig(),
			$objectService,
			$this->registry($objectService),
			$this->createMock(LoggerInterface::class),
		);

		$result = $service->link(
			recordType: 'dossiq:zaak:onbekend',
			recordId: 'zaak-1',
			party: 'party-1',
			kind: 'vergunninghouder',
		);

		$this->assertSame(201, $result['status']);
		$this->assertCount(1, $this->written);
	}//end testNothingIsRefusedForAnUndeclaredRecordType()

	/**
	 * A second holder of a single kind is refused, naming the holder.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-kinds-accepted-per-case-type/specs/party-kind-registry/spec.md#requirement-a-kind-declared-single-shall-refuse-a-second-holder-on-one-record-req-pkr-005
	 */
	public function testASecondAanvragerIsRefusedNamingTheHolder(): void {
		$this->links = [
			['party' => 'party-1', 'kind' => 'aanvrager', 'recordType' => 'dossiq:zaak:subsidie', 'recordId' => 'zaak-1'],
		];

		$answer = $this->registry()->mayLink(
			recordType: 'dossiq:zaak:subsidie',
			recordId: 'zaak-1',
			kind: 'aanvrager',
			party: 'party-2',
		);

		$this->assertFalse($answer['allowed']);
		$this->assertStringContainsString('party-1', $answer['reason']);
	}//end testASecondAanvragerIsRefusedNamingTheHolder()

	/**
	 * An ENDED link does not hold the slot.
	 *
	 * Replacing is an explicit act: end the existing link, then write the new
	 * one. Without this, ending a link would leave the slot occupied forever.
	 *
	 * @return void
	 */
	public function testAnEndedLinkDoesNotHoldTheSlot(): void {
		$this->links = [
			[
				'party' => 'party-1',
				'kind' => 'aanvrager',
				'recordType' => 'dossiq:zaak:subsidie',
				'recordId' => 'zaak-1',
				'validUntil' => '2026-09-01',
			],
		];

		$answer = $this->registry()->mayLink(
			recordType: 'dossiq:zaak:subsidie',
			recordId: 'zaak-1',
			kind: 'aanvrager',
			party: 'party-2',
		);

		$this->assertTrue($answer['allowed']);
	}//end testAnEndedLinkDoesNotHoldTheSlot()

	/**
	 * An unbounded kind takes as many as are written.
	 *
	 * @return void
	 */
	public function testAnUnboundedKindTakesFour(): void {
		$objectService = $this->objectService();
		$service = new PartyLinkService(
			$this->appConfig(),
			$objectService,
			$this->registry($objectService),
			$this->createMock(LoggerInterface::class),
		);

		foreach (['a', 'b', 'c', 'd'] as $party) {
			$result = $service->link(
				recordType: 'dossiq:zaak:subsidie',
				recordId: 'zaak-1',
				party: $party,
				kind: 'belanghebbende',
			);
			$this->assertSame(201, $result['status']);
		}

		$this->assertCount(4, $this->written);
	}//end testAnUnboundedKindTakesFour()

	/**
	 * An import is judged by the same rule, and its good rows land.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-kinds-accepted-per-case-type/specs/party-kind-registry/spec.md#requirement-a-party-link-with-an-unaccepted-kind-shall-be-refused-on-the-write-req-pkr-004
	 */
	public function testAnImportIsJudgedByTheSameRule(): void {
		$this->acceptances = [
			['recordType' => 'dossiq:zaak:subsidie', 'kinds' => ['aanvrager', 'gemachtigde']],
		];

		$objectService = $this->objectService();
		$service = new PartyLinkService(
			$this->appConfig(),
			$objectService,
			$this->registry($objectService),
			$this->createMock(LoggerInterface::class),
		);

		$result = $service->import(
			recordType: 'dossiq:zaak:subsidie',
			recordId: 'zaak-1',
			rows: [
				['party' => 'party-1', 'kind' => 'aanvrager'],
				['party' => 'party-2', 'kind' => 'vergunninghouder'],
				['party' => 'party-3', 'kind' => 'gemachtigde'],
			],
		);

		$this->assertCount(2, $result['linked'], 'The accepted rows land.');
		$this->assertCount(1, $result['refused']);
		$this->assertSame('vergunninghouder', $result['refused'][0]['kind']);
		$this->assertStringContainsString('vergunninghouder', $result['refused'][0]['reason']);
	}//end testAnImportIsJudgedByTheSameRule()

	/**
	 * Two holders of a single kind in ONE import do not both land.
	 *
	 * Neither is written when the batch is judged, so a batch that only asked
	 * the store would accept both.
	 *
	 * @return void
	 */
	public function testABatchJudgesItselfAsWellAsTheStore(): void {
		$verdict = $this->registry()->judgeBatch(
			recordType: 'dossiq:zaak:subsidie',
			recordId: 'zaak-1',
			rows: [
				['party' => 'party-1', 'kind' => 'aanvrager'],
				['party' => 'party-2', 'kind' => 'aanvrager'],
			],
		);

		$this->assertCount(1, $verdict['accepted']);
		$this->assertCount(1, $verdict['refused']);
		$this->assertStringContainsString('party-1', $verdict['refused'][0]['reason']);
	}//end testABatchJudgesItselfAsWellAsTheStore()

	/**
	 * The acceptance target is matched as an opaque string.
	 *
	 * A near miss is a different record type, not a fuzzy match, and pipelinq
	 * never parses the three segments.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-kinds-accepted-per-case-type/specs/party-kind-registry/spec.md#requirement-a-consuming-app-shall-declare-which-kinds-a-record-type-accepts-req-pkr-002
	 */
	public function testTheTargetIsMatchedAsAnOpaqueString(): void {
		$this->acceptances = [
			['recordType' => 'dossiq:zaak:subsidie', 'kinds' => ['aanvrager']],
		];

		$registry = $this->registry();

		$this->assertSame(['aanvrager'], $registry->acceptanceFor(recordType: 'dossiq:zaak:subsidie'));
		$this->assertNull($registry->acceptanceFor(recordType: 'dossiq:zaak'));
		$this->assertNull($registry->acceptanceFor(recordType: 'dossiq:zaak:subsidie:extra'));
	}//end testTheTargetIsMatchedAsAnOpaqueString()
}//end class
