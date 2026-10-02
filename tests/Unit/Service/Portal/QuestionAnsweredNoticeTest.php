<?php

/**
 * Question Answered Notice Test
 *
 * The message a resident gets when a KCC employee answers their question:
 * Dutch, a subject that says the question was answered, a link that opens the
 * question on the site, pipelinq's own rule key so portaliq sends the e-mail,
 * and a record link back to the question. The listener writes it only for a
 * portal question whose answer changed, and never fails the save.
 *
 * @category Tests
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/portal-questions-in-dutch/specs/dossier-questions/spec.md#requirement-the-answer-notice-says-the-question-was-answered
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\Listener\QuestionAnsweredListener;
use OCA\Pipelinq\Portal\PortalContributionProvider;
use OCA\Pipelinq\Service\Portal\QuestionAnsweredNotice;
use OCA\Pipelinq\Service\SchemaMapService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Pipelinq\Service\Portal\QuestionAnsweredNotice
 * @covers \OCA\Pipelinq\Listener\QuestionAnsweredListener
 */
class QuestionAnsweredNoticeTest extends TestCase {

	/**
	 * A portal question with the resident's subject reference.
	 */
	private const QUESTION = [
		'title' => 'Fietspad Lindelaan',
		'ticketType' => 'request',
		'channel' => 'portal',
		'portalSubject' => 'subject-1',
		'status' => 'in_progress',
	];

	/**
	 * What OpenRegister was asked to save, as [object, register, schema].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $saved = [];

	/**
	 * The object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The notice under test.
	 *
	 * @var QuestionAnsweredNotice
	 */
	private QuestionAnsweredNotice $notice;

	/**
	 * A notice over a recording object service, a Dutch translator and a site on a port.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('runAsSystem')->willReturnCallback(fn (callable $operation): mixed => $operation());
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null): ObjectEntity {
				$this->saved[] = [$object, $register, $schema];
				return new ObjectEntity();
			}
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $path): string => 'http://localhost:8080' . $path);

		$dutch = [
			'Your question has been answered' => 'Uw vraag is beantwoord',
			'There is an answer to your question "%1$s".' => 'Er is een antwoord op uw vraag "%1$s".',
			'Read the answer here: %1$s' => 'Lees het antwoord hier: %1$s',
		];
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $params = []): string => vsprintf($dutch[$text] ?? $text, $params));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->with('pipelinq', 'nl')->willReturn($l10n);

		$this->notice = new QuestionAnsweredNotice(
			objectService: $this->objects,
			urlGenerator: $urls,
			l10nFactory: $factory,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * The message says the question was answered, in Dutch, and opens the question on the site.
	 *
	 * @return void
	 */
	public function testTheMessageSaysTheQuestionWasAnsweredAndOpensIt(): void {
		self::assertTrue($this->notice->tell(ticket: self::QUESTION, ticketId: 'ticket-1'));

		self::assertCount(1, $this->saved);
		[$message, $register, $schema] = $this->saved[0];
		self::assertSame('portaliq', $register);
		self::assertSame('portalMessage', $schema);
		self::assertSame('subject-1', $message['subjectRef']);
		self::assertSame('Uw vraag is beantwoord', $message['subject']);
		self::assertSame(
			"Er is een antwoord op uw vraag \"Fietspad Lindelaan\".\n\n"
			. 'Lees het antwoord hier: http://localhost:8080/index.php/apps/portaliq/site#open=pipelinq/myQuestions/ticket-1',
			$message['body']
		);
		self::assertSame(PortalContributionProvider::RULE_QUESTION_ANSWERED, $message['ruleKey']);
		self::assertSame(['app' => 'pipelinq', 'collection' => 'myQuestions', 'id' => 'ticket-1'], $message['recordLink']);
		self::assertFalse($message['read']);
	}//end testTheMessageSaysTheQuestionWasAnsweredAndOpensIt()

	/**
	 * The message fits portaliq's real portalMessage schema: known properties, the right types.
	 *
	 * @return void
	 */
	public function testTheMessageFitsPortaliqsPortalMessageSchema(): void {
		$this->notice->tell(ticket: self::QUESTION, ticketId: 'ticket-1');
		$message = $this->saved[0][0];

		// portaliq lib/Settings/portaliq_register.json, portalMessage 0.6.0; required: subjectRef, subject.
		$properties = [
			'subjectRef' => 'string', 'subject' => 'string', 'body' => 'string', 'read' => 'boolean',
			'receivedAt' => 'string', 'ruleKey' => 'string', 'recordLink' => 'array',
		];
		foreach ($message as $key => $value) {
			self::assertArrayHasKey($key, $properties, 'portalMessage has no property ' . $key);
			self::assertSame($properties[$key], gettype($value) === 'array' ? 'array' : gettype($value), $key);
		}

		self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $message['receivedAt']));
	}//end testTheMessageFitsPortaliqsPortalMessageSchema()

	/**
	 * Only a portal question whose answer changed to something is answered.
	 *
	 * @return void
	 */
	public function testOnlyAChangedAnswerOnAPortalQuestionCounts(): void {
		$answered = self::QUESTION + ['customerMessage' => 'Hier is het antwoord.'];

		self::assertTrue($this->notice->isAnswered(before: self::QUESTION, after: $answered));
		self::assertFalse($this->notice->isAnswered(before: $answered, after: $answered));
		self::assertFalse($this->notice->isAnswered(before: self::QUESTION, after: self::QUESTION + ['customerMessage' => '  ']));
		self::assertFalse($this->notice->isAnswered(before: self::QUESTION, after: ['channel' => 'web'] + $answered));
		self::assertFalse($this->notice->isAnswered(before: self::QUESTION, after: ['ticketType' => 'complaint'] + $answered));
		self::assertFalse($this->notice->isAnswered(before: self::QUESTION, after: ['portalSubject' => ''] + $answered));
	}//end testOnlyAChangedAnswerOnAPortalQuestionCounts()

	/**
	 * A message that cannot be written costs the notice, never the answer.
	 *
	 * @return void
	 */
	public function testAFailedWriteDoesNotThrow(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('runAsSystem')->willThrowException(new RuntimeException('portaliq register missing'));
		$notice = new QuestionAnsweredNotice(
			objectService: $objects,
			urlGenerator: $this->createMock(IURLGenerator::class),
			l10nFactory: $this->createMock(IFactory::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		self::assertFalse($notice->tell(ticket: self::QUESTION, ticketId: 'ticket-1'));
		self::assertFalse($notice->tell(ticket: ['portalSubject' => ''] + self::QUESTION, ticketId: 'ticket-1'));
	}//end testAFailedWriteDoesNotThrow()

	/**
	 * The listener writes the notice when an employee saves an answer on a real ObjectUpdatedEvent.
	 *
	 * @return void
	 */
	public function testTheListenerTellsTheResidentWhenTheAnswerIsSaved(): void {
		$listener = new QuestionAnsweredListener(schemaMap: $this->schemaMap(), notice: $this->notice, logger: $this->createMock(LoggerInterface::class));

		$listener->handle(new ObjectUpdatedEvent(
			$this->entity(self::QUESTION + ['customerMessage' => 'Hier is het antwoord.']),
			$this->entity(self::QUESTION)
		));

		self::assertCount(1, $this->saved);
		self::assertSame(['app' => 'pipelinq', 'collection' => 'myQuestions', 'id' => 'ticket-1'], $this->saved[0][0]['recordLink']);
	}//end testTheListenerTellsTheResidentWhenTheAnswerIsSaved()

	/**
	 * The listener ignores other schemas, a create, an update it cannot compare and an unchanged answer.
	 *
	 * @return void
	 */
	public function testTheListenerIgnoresEverythingElse(): void {
		$listener = new QuestionAnsweredListener(schemaMap: $this->schemaMap(), notice: $this->notice, logger: $this->createMock(LoggerInterface::class));
		$answered = self::QUESTION + ['customerMessage' => 'Hier is het antwoord.'];

		$listener->handle(new ObjectCreatedEvent($this->entity($answered)));
		$listener->handle(new ObjectUpdatedEvent($this->entity($answered), null));
		$listener->handle(new ObjectUpdatedEvent($this->entity($answered), $this->entity($answered)));
		$listener->handle(new ObjectUpdatedEvent($this->entity($answered, 'schema-lead'), $this->entity(self::QUESTION, 'schema-lead')));

		self::assertSame([], $this->saved);
	}//end testTheListenerIgnoresEverythingElse()

	/**
	 * A schema map that knows the ticket schema.
	 *
	 * @return SchemaMapService&MockObject
	 */
	private function schemaMap(): SchemaMapService&MockObject {
		$map = $this->createMock(SchemaMapService::class);
		$map->method('resolveEntityType')->willReturnCallback(fn (?string $id): ?string => ['schema-ticket' => 'ticket', 'schema-lead' => 'lead'][$id] ?? null);
		return $map;
	}//end schemaMap()

	/**
	 * A REAL object entity, as OpenRegister hands the listener.
	 *
	 * @param array<string, mixed> $data   The ticket.
	 * @param string               $schema The schema id.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, string $schema = 'schema-ticket'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('ticket-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()
}//end class
