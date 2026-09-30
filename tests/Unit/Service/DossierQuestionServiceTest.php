<?php

/**
 * A resident asks about their own dossier, and replies to the answer.
 *
 * The dossier fixture is shaped exactly like hydra woo-citizen-journey C1
 * (`collection`: title, description, owner, items[{id, publication,
 * attachment, note, addedAt, addedBy}], share, sourceOf), because the
 * opencatalogi lane builds the real object in parallel.
 *
 * @category Tests
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.pipelinq.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTime;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\DossierQuestionService;
use OCA\Pipelinq\Service\Portal\MainRegisterReader;
use OCA\Pipelinq\Tests\Unit\Settings\DossierQuestionSchemaTest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for DossierQuestionService.
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
 */
class DossierQuestionServiceTest extends TestCase {

	private const RESIDENT = 'subj-7f3a';
	private const OTHER = 'subj-0b91';
	private const DOSSIER = '5b0e8c55-1f7e-4a53-9a0e-2f1f0c1d9a11';

	/** @var MainRegisterReader&MockObject */
	private MainRegisterReader $tickets;

	/** @var array<string, array<string, mixed>> Objects in the opencatalogi register, by schema/id. */
	private array $catalogue = [];

	/** @var array<int, array<string, mixed>> Every ticket save: schemaKey, data, id. */
	private array $saves = [];

	private DossierQuestionService $service;

	/**
	 * A dossier shaped like C1, owned by the resident, with three items.
	 *
	 * @return array<string, mixed>
	 */
	public static function dossier(string $owner = self::RESIDENT): array {
		return [
			'title' => 'Windpark Noord',
			'description' => 'Alles over het windpark bij de dijk',
			'owner' => $owner,
			'items' => [
				['id' => 'i-1', 'publication' => 'p-1', 'attachment' => null, 'note' => 'Het besluit', 'addedAt' => '2026-09-28T09:00:00+00:00', 'addedBy' => 'resident'],
				['id' => 'i-2', 'publication' => 'p-2', 'attachment' => 812, 'note' => '', 'addedAt' => '2026-09-28T09:05:00+00:00', 'addedBy' => 'resident'],
				['id' => 'i-3', 'publication' => 'p-gone', 'attachment' => null, 'note' => 'Mijn eigen notitie', 'addedAt' => '2026-09-29T14:00:00+00:00', 'addedBy' => 'dossiq'],
			],
			'share' => null,
			'sourceOf' => [],
		];
	}//end dossier()

	protected function setUp(): void {
		parent::setUp();

		$this->catalogue = [
			'collection/' . self::DOSSIER => self::dossier(),
			'publication/p-1' => ['title' => 'Besluit omgevingsvergunning windpark'],
			'publication/p-2' => ['title' => 'Advies Omgevingsdienst'],
		];

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null, bool $_rbac = true, bool $_multitenancy = true): ?ObjectEntityInterface {
				$this->assertSame('publication', $register, 'dossiers and publications live in opencatalogi\'s publication register');
				$this->assertFalse($_rbac, 'a portal subject has no Nextcloud account; the owner check is the authorisation');
				$data = ($this->catalogue[$schema . '/' . $id] ?? null);
				if ($data === null) {
					return null;
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn($data);
				return $entity;
			}
		);

		$this->tickets = $this->createMock(MainRegisterReader::class);
		$this->tickets->method('save')->willReturnCallback(
			function (string $schemaKey, array $data, ?string $id = null): array {
				$this->saves[] = ['schemaKey' => $schemaKey, 'data' => $data, 'id' => $id];
				return $data + ['id' => ($id ?? 't-new')];
			}
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://gemeente.example' . $path);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-30T10:00:00+00:00'));

		$this->service = new DossierQuestionService(
			tickets: $this->tickets,
			objectService: $objects,
			urlGenerator: $urls,
			time: $time,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Scenario: A resident asks about their own dossier.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function testAResidentAsksAboutTheirOwnDossier(): void {
		$result = $this->service->ask(
			subjectRef: self::RESIDENT,
			collectionId: self::DOSSIER,
			question: 'Wanneer valt het besluit over de vergunning?'
		);

		$this->assertSame(['id' => 't-new', 'status' => 'new'], $result);
		$this->assertCount(1, $this->saves);
		$this->assertSame('ticket', $this->saves[0]['schemaKey']);
		$this->assertNull($this->saves[0]['id']);

		$ticket = $this->saves[0]['data'];
		$this->assertSame('request', $ticket['ticketType']);
		$this->assertSame('portal', $ticket['channel']);
		$this->assertSame('new', $ticket['status']);
		$this->assertSame(self::RESIDENT, $ticket['portalSubject']);
		$this->assertSame('Windpark Noord', $ticket['title'], 'without a subject line the dossier title stands in');
		$this->assertSame('Wanneer valt het besluit over de vergunning?', $ticket['description']);
		$this->assertSame(
			[
				'type' => 'opencatalogi.collection',
				'id' => self::DOSSIER,
				'title' => 'Windpark Noord',
				'items' => [
					['title' => 'Besluit omgevingsvergunning windpark', 'url' => 'https://gemeente.example/index.php/apps/opencatalogi/api/search/p-1'],
					['title' => 'Advies Omgevingsdienst', 'url' => 'https://gemeente.example/index.php/apps/opencatalogi/api/search/p-2'],
					['title' => 'Mijn eigen notitie', 'url' => ''],
				],
			],
			$ticket['subjectReference']
		);
		$this->assertArrayNotHasKey('owner', $ticket['subjectReference'], 'the snapshot never carries the owner');
	}//end testAResidentAsksAboutTheirOwnDossier()

	/**
	 * The ticket the service writes has exactly the keys the schema test validates.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function testTheWrittenTicketHasTheValidatedShape(): void {
		$this->service->ask(subjectRef: self::RESIDENT, collectionId: self::DOSSIER, question: 'Vraag');

		$this->assertSame(
			array_keys(DossierQuestionSchemaTest::questionTicket()),
			array_keys($this->saves[0]['data'])
		);
	}//end testTheWrittenTicketHasTheValidatedShape()

	/**
	 * A subject line the resident typed wins over the dossier title.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function testTheResidentsSubjectLineWins(): void {
		$this->service->ask(subjectRef: self::RESIDENT, collectionId: self::DOSSIER, question: 'Vraag', title: '  Termijn besluit  ');

		$this->assertSame('Termijn besluit', $this->saves[0]['data']['title']);
	}//end testTheResidentsSubjectLineWins()

	/**
	 * An unreadable publication falls back to the item's own title snapshot (C1), then its note.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function testAnUnreadablePublicationKeepsTheItemsTitle(): void {
		$dossier = self::dossier();
		$dossier['items'][2]['title'] = 'Oud besluit windpark';
		$this->catalogue['collection/' . self::DOSSIER] = $dossier;

		$this->service->ask(subjectRef: self::RESIDENT, collectionId: self::DOSSIER, question: 'Vraag');

		$this->assertSame(['title' => 'Oud besluit windpark', 'url' => ''], $this->saves[0]['data']['subjectReference']['items'][2]);
	}//end testAnUnreadablePublicationKeepsTheItemsTitle()

	/**
	 * Scenario: A resident names someone else's dossier.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function testSomeoneElsesDossierIsNotFoundAndNothingIsWritten(): void {
		$this->catalogue['collection/' . self::DOSSIER] = self::dossier(owner: self::OTHER);

		$this->assertNull($this->service->ask(subjectRef: self::RESIDENT, collectionId: self::DOSSIER, question: 'Vraag'));
		$this->assertSame([], $this->saves);
	}//end testSomeoneElsesDossierIsNotFoundAndNothingIsWritten()

	/**
	 * A missing dossier answers the same as a foreign one, and an empty subject owns nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function testAMissingDossierOrAnEmptySubjectIsNotFound(): void {
		$this->assertNull($this->service->ask(subjectRef: self::RESIDENT, collectionId: 'no-such-dossier', question: 'Vraag'));

		$this->catalogue['collection/' . self::DOSSIER] = self::dossier(owner: '');
		$this->assertNull($this->service->ask(subjectRef: '', collectionId: self::DOSSIER, question: 'Vraag'));
		$this->assertSame([], $this->saves);
	}//end testAMissingDossierOrAnEmptySubjectIsNotFound()

	/**
	 * Scenario: The dossier changes after the question.
	 *
	 * The snapshot is taken once. Changing the dossier afterwards changes
	 * nothing pipelinq holds, and replying does not read the dossier again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-question-keeps-the-dossier-as-it-was-when-asked-req-qcd-002
	 */
	public function testTheSnapshotStaysAsItWasWhenAsked(): void {
		$this->service->ask(subjectRef: self::RESIDENT, collectionId: self::DOSSIER, question: 'Vraag');
		$asked = $this->saves[0]['data'];

		$changed = self::dossier();
		array_pop($changed['items']);
		$this->catalogue['collection/' . self::DOSSIER] = $changed;

		$this->tickets->method('find')->willReturn($asked + ['status' => 'awaiting_customer']);
		$this->service->reply(subjectRef: self::RESIDENT, ticketId: 't-new', message: 'Dank u');

		$this->assertCount(3, $this->saves[1]['data']['subjectReference']['items']);
		$this->assertSame($asked['subjectReference'], $this->saves[1]['data']['subjectReference']);
	}//end testTheSnapshotStaysAsItWasWhenAsked()

	/**
	 * Scenario: A resident replies.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-replies-to-an-answer-req-qcd-004
	 */
	public function testAResidentReplies(): void {
		$question = DossierQuestionSchemaTest::questionTicket();
		$question['status'] = 'awaiting_customer';
		$question['customerMessage'] = 'Het besluit valt in november.';
		$question['portalReplies'] = [['message' => 'Eerder bericht', 'createdAt' => '2026-09-29T08:00:00+00:00']];
		$this->tickets->method('find')->willReturnCallback(
			static fn (string $schemaKey, string $id): ?array => ($schemaKey === 'ticket' && $id === 't-9') ? $question : null
		);

		$result = $this->service->reply(subjectRef: self::RESIDENT, ticketId: 't-9', message: 'Dank u, ik wacht het besluit af');

		$this->assertSame('in_progress', $result['status']);
		$this->assertSame('t-9', $this->saves[0]['id']);
		$saved = $this->saves[0]['data'];
		$this->assertSame('in_progress', $saved['status']);
		$this->assertSame(
			[
				['message' => 'Eerder bericht', 'createdAt' => '2026-09-29T08:00:00+00:00'],
				['message' => 'Dank u, ik wacht het besluit af', 'createdAt' => '2026-09-30T10:00:00+00:00'],
			],
			$saved['portalReplies']
		);
		$this->assertSame('Het besluit valt in november.', $saved['customerMessage'], 'the reply leaves the answer alone');
	}//end testAResidentReplies()

	/**
	 * Scenario: A resident replies to someone else's question.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-replies-to-an-answer-req-qcd-004
	 */
	public function testAReplyToSomeoneElsesQuestionIsNotFound(): void {
		$question = DossierQuestionSchemaTest::questionTicket();
		$question['portalSubject'] = self::OTHER;
		$this->tickets->method('find')->willReturn($question);

		$this->assertNull($this->service->reply(subjectRef: self::RESIDENT, ticketId: 't-9', message: 'Hallo'));
		$this->assertSame([], $this->saves);
	}//end testAReplyToSomeoneElsesQuestionIsNotFound()

	/**
	 * A ticket that is not a dossier question (no snapshot) cannot be replied to here.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-replies-to-an-answer-req-qcd-004
	 */
	public function testAnOrdinaryTicketIsNotFound(): void {
		$ticket = DossierQuestionSchemaTest::questionTicket();
		unset($ticket['subjectReference']);
		$this->tickets->method('find')->willReturn($ticket);

		$this->assertNull($this->service->reply(subjectRef: self::RESIDENT, ticketId: 't-9', message: 'Hallo'));
		$this->assertNull($this->service->reply(subjectRef: self::RESIDENT, ticketId: 'missing', message: 'Hallo'));
		$this->assertSame([], $this->saves);
	}//end testAnOrdinaryTicketIsNotFound()

	/**
	 * A failed save surfaces, so the controller can answer 503.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function testAFailedSaveThrows(): void {
		$tickets = $this->createMock(MainRegisterReader::class);
		$tickets->method('save')->willThrowException(new RuntimeException('Failed to persist object.'));
		$objects = $this->createMock(ObjectServiceInterface::class);
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn(self::dossier());
		$objects->method('find')->willReturn($entity);

		$service = new DossierQuestionService(
			tickets: $tickets,
			objectService: $objects,
			urlGenerator: $this->createMock(IURLGenerator::class),
			time: $this->createMock(ITimeFactory::class),
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->expectException(RuntimeException::class);
		$service->ask(subjectRef: self::RESIDENT, collectionId: self::DOSSIER, question: 'Vraag');
	}//end testAFailedSaveThrows()
}//end class
