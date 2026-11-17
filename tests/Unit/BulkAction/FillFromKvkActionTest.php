<?php

/**
 * Unit tests for FillFromKvkAction.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BulkAction
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

namespace OCA\Pipelinq\Tests\Unit\BulkAction;

use OCA\OpenRegister\BulkAction\BulkStep;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\BulkActionRegistrationEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use OCA\Pipelinq\BulkAction\FillFromKvkAction;
use OCA\Pipelinq\Kernel\Contracts\ClientIdentifierServiceInterface;
use OCA\Pipelinq\Listener\FillFromKvkRegistrationListener;
use OCA\Pipelinq\Service\ClientIdentifierService;
use OCA\Pipelinq\Service\OrganisationEnrichmentService;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Fill in from KvK fills empty fields only, skips with a reason and records what it changed.
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/specs/client-registry-numbers/spec.md
 */
#[CoversClass(FillFromKvkAction::class)]
#[CoversClass(FillFromKvkRegistrationListener::class)]
#[UsesClass(ClientIdentifierService::class)]
#[UsesClass(OrganisationEnrichmentService::class)]
class FillFromKvkActionTest extends TestCase {

	/**
	 * What the KvK knows about 12345678.
	 *
	 * @var array<string, mixed>
	 */
	private const COMPANY = [
		'kvkNummer' => '12345678',
		'naam' => 'Bakkerij de Korenschoof',
		'rechtsvorm' => 'Besloten Vennootschap',
		'adres' => ['binnenlandsAdres' => ['plaats' => 'Zuiddrecht', 'straatnaam' => 'Molenweg']],
		'sbiActiviteiten' => [
			['sbiCode' => '4724', 'sbiOmschrijving' => 'Winkels in brood en banket', 'indHoofdactiviteit' => 'Nee'],
			['sbiCode' => '1071', 'sbiOmschrijving' => 'Brood- en banketbakkerijen', 'indHoofdactiviteit' => 'Ja'],
		],
	];

	/**
	 * The KvK numbers looked up.
	 *
	 * @var array<int, string>
	 */
	private array $lookedUp = [];

	/**
	 * The patches written.
	 *
	 * @var array<int, ObjectPatch>
	 */
	private array $patches = [];

	/**
	 * An action over the given KvK answer, and a write path that may fail.
	 *
	 * @param array{checkStatus: string, company: array<string, mixed>|null} $answer    What the KvK lookup answers.
	 * @param bool                                                          $writeFails Whether the write throws.
	 *
	 * @return FillFromKvkAction The action.
	 */
	private function action(array $answer, bool $writeFails = false): FillFromKvkAction {
		$identifiers = $this->createMock(ClientIdentifierServiceInterface::class);
		$identifiers->method('kvkCompany')->willReturnCallback(
			function (string $value) use ($answer): array {
				$this->lookedUp[] = $value;
				return $answer;
			}
		);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('patchObject')->willReturnCallback(
			function (ObjectPatch $patch) use ($writeFails) {
				if ($writeFails === true) {
					throw new RuntimeException('register refused the write');
				}

				$this->patches[] = $patch;
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		return new FillFromKvkAction($identifiers, $objects, $this->createMock(\Psr\Log\LoggerInterface::class));
	}//end action()

	/**
	 * A client object.
	 *
	 * @param array<string, mixed> $data The data.
	 * @param string               $uuid The uuid.
	 *
	 * @return ObjectEntity The object.
	 */
	private function client(array $data, string $uuid = 'c1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('5');
		$object->setSchema('7');
		$object->setObject($data);
		return $object;
	}//end client()

	/**
	 * A rehearsal says it would apply and writes nothing; the commit writes the empty fields only.
	 *
	 * @return void
	 */
	public function testApplyRehearsesThenWritesTheEmptyFieldsOnly(): void {
		$action = $this->action(answer: ['checkStatus' => 'valid', 'company' => self::COMPANY]);
		$object = $this->client(data: [
			'name' => 'De Korenschoof',
			'type' => 'organization',
			'place' => '',
			'industry' => [],
			'legalForm' => 'eenmanszaak',
			'identifiers' => [['scheme' => 'kvk', 'value' => '1234 5678']],
		]);
		$actor = $this->createMock(IUser::class);

		$preview = $action->apply(new BulkStep(object: $object, parameters: [], commit: false, actor: $actor));
		$this->assertTrue($preview->isApplied());
		$this->assertSame([], $this->patches);

		$commit = $action->apply(new BulkStep(object: $object, parameters: [], commit: true, actor: $actor));
		$this->assertTrue($commit->isApplied());
		$this->assertCount(1, $this->patches);
		$this->assertSame('c1', $this->patches[0]->objectId);
		$this->assertSame(['place' => 'Zuiddrecht', 'industry' => ['Brood- en banketbakkerijen', 'Winkels in brood en banket']], $this->patches[0]->data);
		$this->assertSame('5', $this->patches[0]->target->register);
		$this->assertSame('7', $this->patches[0]->target->schema);
		$this->assertSame($actor, $this->patches[0]->currentUser);
		$this->assertSame(['12345678'], $this->lookedUp, 'The rehearsal and the commit ask the KvK once.');
	}//end testApplyRehearsesThenWritesTheEmptyFieldsOnly()

	/**
	 * The reversal plan records the prior value of exactly the filled fields.
	 *
	 * @return void
	 */
	public function testTheReversalPlanRecordsThePriorValues(): void {
		$action = $this->action(answer: ['checkStatus' => 'valid', 'company' => self::COMPANY]);
		$object = $this->client(data: ['type' => 'organization', 'industry' => [], 'kvkNumber' => '12345678']);

		$this->assertSame(
			[
				'prior' => ['place' => null, 'legalForm' => null, 'industry' => []],
				'applied' => ['place' => 'Zuiddrecht', 'legalForm' => 'bv', 'industry' => ['Brood- en banketbakkerijen', 'Winkels in brood en banket']],
			],
			$action->reversalPlanFor($object, [])
		);
		$this->assertSame(604800, $action->getReversalWindow());
	}//end testTheReversalPlanRecordsThePriorValues()

	/**
	 * A client without a KvK number is skipped with that reason and never looked up.
	 *
	 * @return void
	 */
	public function testAClientWithoutAKvkNumberIsSkipped(): void {
		$action = $this->action(answer: ['checkStatus' => 'valid', 'company' => self::COMPANY]);
		$result = $action->apply(
			new BulkStep(object: $this->client(data: ['type' => 'organization', 'identifiers' => [['scheme' => 'vat', 'value' => 'NL123456789B01']]]), parameters: [], commit: true, actor: null)
		);

		$this->assertFalse($result->isApplied());
		$this->assertSame('no KvK number', $result->getReason());
		$this->assertSame([], $this->lookedUp);
		$this->assertSame([], $this->patches);
	}//end testAClientWithoutAKvkNumberIsSkipped()

	/**
	 * An unreachable KvK skips the member rather than failing the job; an unknown number says so.
	 *
	 * @return void
	 */
	public function testAnUnreachableOrUnknownKvkSkips(): void {
		$client = ['type' => 'organization', 'kvkNumber' => '12345678'];
		$unavailable = $this->action(answer: ['checkStatus' => 'unavailable', 'company' => null])
			->apply(new BulkStep(object: $this->client(data: $client), parameters: [], commit: true, actor: null));
		$unknown = $this->action(answer: ['checkStatus' => 'invalid', 'company' => null])
			->apply(new BulkStep(object: $this->client(data: $client, uuid: 'c2'), parameters: [], commit: true, actor: null));

		$this->assertSame('KvK unavailable', $unavailable->getReason());
		$this->assertSame('KvK number not found in the KvK', $unknown->getReason());
		$this->assertSame([], $this->patches);
	}//end testAnUnreachableOrUnknownKvkSkips()

	/**
	 * A client whose fields are all filled is skipped; a person is never looked up.
	 *
	 * @return void
	 */
	public function testFilledClientsAndPersonsAreSkipped(): void {
		$action = $this->action(answer: ['checkStatus' => 'valid', 'company' => self::COMPANY]);
		$filled = $action->apply(
			new BulkStep(
				object: $this->client(data: ['type' => 'organization', 'kvkNumber' => '12345678', 'place' => 'Dordrecht', 'legalForm' => 'nv', 'industry' => ['Retail']]),
				parameters: [],
				commit: true,
				actor: null
			)
		);
		$person = $action->apply(
			new BulkStep(object: $this->client(data: ['type' => 'person', 'kvkNumber' => '12345678'], uuid: 'p1'), parameters: [], commit: true, actor: null)
		);

		$this->assertSame('already has these values', $filled->getReason());
		$this->assertSame('not an organisation', $person->getReason());
		$this->assertSame(['12345678'], $this->lookedUp);
		$this->assertSame([], $this->patches);
	}//end testFilledClientsAndPersonsAreSkipped()

	/**
	 * A refused write is a failed member with the register's message.
	 *
	 * @return void
	 */
	public function testARefusedWriteFailsTheMember(): void {
		$result = $this->action(answer: ['checkStatus' => 'valid', 'company' => self::COMPANY], writeFails: true)
			->apply(new BulkStep(object: $this->client(data: ['type' => 'organization', 'kvkNumber' => '12345678']), parameters: [], commit: true, actor: null));

		$this->assertFalse($result->isApplied());
		$this->assertSame('register refused the write', $result->getReason());
	}//end testARefusedWriteFailsTheMember()

	/**
	 * The KvK identifier wins over the older KvK number field.
	 *
	 * @return void
	 */
	public function testTheKvkIdentifierWinsOverTheKvkNumberField(): void {
		$this->assertSame('87654321', FillFromKvkAction::kvkNumberOf(client: ['kvkNumber' => '12345678', 'identifiers' => [['scheme' => 'kvk', 'value' => '8765 4321']]]));
		$this->assertSame('12345678', FillFromKvkAction::kvkNumberOf(client: ['kvkNumber' => '12345678']));
		$this->assertSame('', FillFromKvkAction::kvkNumberOf(client: []));
	}//end testTheKvkIdentifierWinsOverTheKvkNumberField()

	/**
	 * The action describes itself to OpenRegister and registers on its event.
	 *
	 * @return void
	 */
	public function testTheActionIsRegisteredWithOpenRegister(): void {
		$action = $this->action(answer: ['checkStatus' => 'valid', 'company' => self::COMPANY]);
		$this->assertSame('pipelinq:fill-from-kvk', $action->getId());
		$this->assertSame('Fill in from KvK', $action->getLabel());
		$this->assertFalse($action->requiresJustification());
		$this->assertSame(['homogeneity'], $action->getGuards());

		$event = $this->createMock(BulkActionRegistrationEvent::class);
		$event->expects($this->once())->method('registerAction')->with($action);
		(new FillFromKvkRegistrationListener($action))->handle($event);
	}//end testTheActionIsRegisteredWithOpenRegister()
}//end class
