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
use OCA\Pipelinq\BulkAction\FillFromKvkRegistrationListener;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Fill in from KvK fills empty fields only, skips with a reason and records what it changed.
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/specs/client-registry-numbers/spec.md
 */
#[CoversClass(FillFromKvkAction::class)]
#[CoversClass(FillFromKvkRegistrationListener::class)]
class FillFromKvkActionTest extends TestCase {

	/**
	 * What the KvK knows about 12345678, as client fields.
	 *
	 * @var array<string, mixed>
	 */
	private const FACTS = [
		'place' => 'Zuiddrecht',
		'legalForm' => 'bv',
		'industry' => ['Brood- en banketbakkerijen', 'Winkels in brood en banket'],
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
	 * @param array{checkStatus: string, facts: array<string, mixed>|null} $answer    What the KvK lookup answers.
	 * @param bool                                                          $writeFails Whether the write throws.
	 *
	 * @return FillFromKvkAction The action.
	 */
	private function action(array $answer, bool $writeFails = false): FillFromKvkAction {
		$identifiers = $this->createMock(originalClassName: ClientIdentifierServiceInterface::class);
		$identifiers->method('kvkFacts')->willReturnCallback(
			function (string $value) use ($answer): array {
				$this->lookedUp[] = $value;
				return $answer;
			}
		);
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('patchObject')->willReturnCallback(
			function (ObjectPatch $patch) use ($writeFails) {
				if ($writeFails === true) {
					throw new RuntimeException('register refused the write');
				}

				$this->patches[] = $patch;
				return $this->createMock(originalClassName: ObjectEntityInterface::class);
			}
		);

		return new FillFromKvkAction(
			identifiers: $identifiers,
			objectService: $objects,
			logger: $this->createMock(originalClassName: \Psr\Log\LoggerInterface::class)
		);
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
		$action = $this->action(answer: ['checkStatus' => 'valid', 'facts' => self::FACTS]);
		$object = $this->client(data: [
			'name' => 'De Korenschoof',
			'type' => 'organization',
			'place' => '',
			'industry' => [],
			'legalForm' => 'eenmanszaak',
			'identifiers' => [['scheme' => 'kvk', 'value' => '1234 5678']],
		]);
		$actor = $this->createMock(originalClassName: IUser::class);

		$preview = $action->apply(new BulkStep(object: $object, parameters: [], commit: false, actor: $actor));
		$this->assertTrue(condition: $preview->isApplied());
		$this->assertSame(expected: [], actual: $this->patches);

		$commit = $action->apply(new BulkStep(object: $object, parameters: [], commit: true, actor: $actor));
		$this->assertTrue(condition: $commit->isApplied());
		$this->assertCount(expectedCount: 1, haystack: $this->patches);
		$this->assertSame(expected: 'c1', actual: $this->patches[0]->objectId);
		$this->assertSame(
			expected: ['place' => 'Zuiddrecht', 'industry' => ['Brood- en banketbakkerijen', 'Winkels in brood en banket']],
			actual: $this->patches[0]->data
		);
		$this->assertSame(expected: '5', actual: $this->patches[0]->target->register);
		$this->assertSame(expected: '7', actual: $this->patches[0]->target->schema);
		$this->assertSame(expected: $actor, actual: $this->patches[0]->currentUser);
		$this->assertSame(expected: ['12345678'], actual: $this->lookedUp, message: 'The rehearsal and the commit ask the KvK once.');
	}//end testApplyRehearsesThenWritesTheEmptyFieldsOnly()

	/**
	 * The reversal plan records the prior value of exactly the filled fields.
	 *
	 * @return void
	 */
	public function testTheReversalPlanRecordsThePriorValues(): void {
		$action = $this->action(answer: ['checkStatus' => 'valid', 'facts' => self::FACTS]);
		$object = $this->client(data: ['type' => 'organization', 'industry' => [], 'kvkNumber' => '12345678']);

		$this->assertSame(
			expected: [
				'prior' => ['place' => null, 'legalForm' => null, 'industry' => []],
				'applied' => ['place' => 'Zuiddrecht', 'legalForm' => 'bv', 'industry' => ['Brood- en banketbakkerijen', 'Winkels in brood en banket']],
			],
			actual: $action->reversalPlanFor($object, [])
		);
		$this->assertSame(expected: 604800, actual: $action->getReversalWindow());
	}//end testTheReversalPlanRecordsThePriorValues()

	/**
	 * A client without a KvK number is skipped with that reason and never looked up.
	 *
	 * @return void
	 */
	public function testAClientWithoutAKvkNumberIsSkipped(): void {
		$action = $this->action(answer: ['checkStatus' => 'valid', 'facts' => self::FACTS]);
		$result = $action->apply(
			new BulkStep(
				object: $this->client(data: ['type' => 'organization', 'identifiers' => [['scheme' => 'vat', 'value' => 'NL123456789B01']]]),
				parameters: [],
				commit: true,
				actor: null
			)
		);

		$this->assertFalse(condition: $result->isApplied());
		$this->assertSame(expected: 'no KvK number', actual: $result->getReason());
		$this->assertSame(expected: [], actual: $this->lookedUp);
		$this->assertSame(expected: [], actual: $this->patches);
	}//end testAClientWithoutAKvkNumberIsSkipped()

	/**
	 * An unreachable KvK skips the member rather than failing the job; an unknown number says so.
	 *
	 * @return void
	 */
	public function testAnUnreachableOrUnknownKvkSkips(): void {
		$client = ['type' => 'organization', 'kvkNumber' => '12345678'];
		$unavailable = $this->action(answer: ['checkStatus' => 'unavailable', 'facts' => null])
			->apply(new BulkStep(object: $this->client(data: $client), parameters: [], commit: true, actor: null));
		$unknown = $this->action(answer: ['checkStatus' => 'invalid', 'facts' => null])
			->apply(new BulkStep(object: $this->client(data: $client, uuid: 'c2'), parameters: [], commit: true, actor: null));

		$this->assertSame(expected: 'KvK unavailable', actual: $unavailable->getReason());
		$this->assertSame(expected: 'KvK number not found in the KvK', actual: $unknown->getReason());
		$this->assertSame(expected: [], actual: $this->patches);
	}//end testAnUnreachableOrUnknownKvkSkips()

	/**
	 * A client whose fields are all filled is skipped; a person is never looked up.
	 *
	 * @return void
	 */
	public function testFilledClientsAndPersonsAreSkipped(): void {
		$action = $this->action(answer: ['checkStatus' => 'valid', 'facts' => self::FACTS]);
		$filled = $action->apply(
			new BulkStep(
				object: $this->client(
					data: ['type' => 'organization', 'kvkNumber' => '12345678', 'place' => 'Dordrecht', 'legalForm' => 'nv', 'industry' => ['Retail']]
				),
				parameters: [],
				commit: true,
				actor: null
			)
		);
		$person = $action->apply(
			new BulkStep(
				object: $this->client(data: ['type' => 'person', 'kvkNumber' => '12345678'], uuid: 'p1'),
				parameters: [],
				commit: true,
				actor: null
			)
		);

		$this->assertSame(expected: 'already has these values', actual: $filled->getReason());
		$this->assertSame(expected: 'not an organisation', actual: $person->getReason());
		$this->assertSame(expected: ['12345678'], actual: $this->lookedUp);
		$this->assertSame(expected: [], actual: $this->patches);
	}//end testFilledClientsAndPersonsAreSkipped()

	/**
	 * A refused write is a failed member with the register's message.
	 *
	 * @return void
	 */
	public function testARefusedWriteFailsTheMember(): void {
		$result = $this->action(answer: ['checkStatus' => 'valid', 'facts' => self::FACTS], writeFails: true)
			->apply(new BulkStep(
				object: $this->client(data: ['type' => 'organization', 'kvkNumber' => '12345678']),
				parameters: [],
				commit: true,
				actor: null
			));

		$this->assertFalse(condition: $result->isApplied());
		$this->assertSame(expected: 'register refused the write', actual: $result->getReason());
	}//end testARefusedWriteFailsTheMember()

	/**
	 * The KvK identifier wins over the older KvK number field.
	 *
	 * @return void
	 */
	public function testTheKvkIdentifierWinsOverTheKvkNumberField(): void {
		$this->assertSame(
			expected: '87654321',
			actual: FillFromKvkAction::kvkNumberOf(client: ['kvkNumber' => '12345678', 'identifiers' => [['scheme' => 'kvk', 'value' => '8765 4321']]])
		);
		$this->assertSame(expected: '12345678', actual: FillFromKvkAction::kvkNumberOf(client: ['kvkNumber' => '12345678']));
		$this->assertSame(expected: '', actual: FillFromKvkAction::kvkNumberOf(client: []));
	}//end testTheKvkIdentifierWinsOverTheKvkNumberField()

	/**
	 * The action describes itself to OpenRegister and registers on its event.
	 *
	 * @return void
	 */
	public function testTheActionIsRegisteredWithOpenRegister(): void {
		$action = $this->action(answer: ['checkStatus' => 'valid', 'facts' => self::FACTS]);
		$this->assertSame(expected: 'pipelinq:fill-from-kvk', actual: $action->getId());
		$this->assertSame(expected: 'Fill in from KvK', actual: $action->getLabel());
		$this->assertFalse(condition: $action->requiresJustification());
		$this->assertSame(expected: ['homogeneity'], actual: $action->getGuards());

		$event = $this->createMock(originalClassName: BulkActionRegistrationEvent::class);
		$event->expects($this->once())->method('registerAction')->with($action);
		(new FillFromKvkRegistrationListener(action: $action))->handle($event);
	}//end testTheActionIsRegisteredWithOpenRegister()
}//end class
