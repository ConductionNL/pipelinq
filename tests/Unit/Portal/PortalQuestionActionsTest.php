<?php

/**
 * Unit tests for PortalQuestionActions.
 *
 * Pins the three question action declarations the portal renders: which
 * kind of action each is, where it is attached, which fields it asks and
 * requires, and when the reply is offered.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Portal;

use OCA\Pipelinq\Portal\PortalQuestionActions;
use PHPUnit\Framework\TestCase;

/**
 * The question action declarations.
 *
 * @covers \OCA\Pipelinq\Portal\PortalQuestionActions
 */
class PortalQuestionActionsTest extends TestCase {

	/**
	 * Asking a general question creates a portal request ticket in pipelinq.
	 *
	 * @return void
	 */
	public function testAskCreatesAPortalRequestTicket(): void {
		$ask = (new PortalQuestionActions())->ask();

		self::assertSame('askQuestion', $ask['id']);
		self::assertSame('create', $ask['type']);
		self::assertSame('pipelinq', $ask['register']);
		self::assertSame('ticket', $ask['schema']);
		self::assertSame(['ticketType' => 'request', 'channel' => 'portal'], $ask['defaults']);
		self::assertSame('portalSubject', $ask['scopeField']);
		self::assertSame(['title', 'description'], $ask['fields']);
		self::assertSame(['title', 'description'], $ask['requiredFields']);
		self::assertSame('occurredAt', $ask['createdAtField']);
		self::assertSame('contact-confirmation', $ask['confirmationMail']);
		self::assertSame('title', $ask['topicField']);
		self::assertArrayNotHasKey('endpoint', $ask);
	}//end testAskCreatesAPortalRequestTicket()

	/**
	 * Asking about a dossier posts to the questions endpoint from the dossier page.
	 *
	 * @return void
	 */
	public function testAskAboutDossierPostsFromTheDossierPage(): void {
		$action = (new PortalQuestionActions())->askAboutDossier();

		self::assertSame('askAboutDossier', $action['id']);
		self::assertSame('/index.php/apps/pipelinq/api/portal/questions', $action['endpoint']);
		self::assertSame('POST', $action['method']);
		self::assertSame(['app' => 'opencatalogi', 'schema' => 'collection'], $action['attachTo']);
		self::assertSame('collectionId', $action['rowField']);
		self::assertSame(['question', 'title'], $action['fields']);
		self::assertSame(['question'], $action['requiredFields']);
		self::assertTrue($action['fieldConfigs']['question']['required']);
		self::assertArrayNotHasKey('required', $action['fieldConfigs']['title']);
		self::assertArrayNotHasKey('type', $action);
	}//end testAskAboutDossierPostsFromTheDossierPage()

	/**
	 * The reply posts on the resident's own question, only while it awaits them.
	 *
	 * @return void
	 */
	public function testReplyIsOfferedOnlyWhileAwaitingTheResident(): void {
		$reply = (new PortalQuestionActions())->reply();

		self::assertSame('replyToQuestion', $reply['id']);
		self::assertSame('/index.php/apps/pipelinq/api/portal/questions/reply', $reply['endpoint']);
		self::assertSame('POST', $reply['method']);
		self::assertSame(['app' => 'pipelinq', 'schema' => 'ticket', 'collection' => 'myQuestions'], $reply['attachTo']);
		self::assertSame('ticket', $reply['rowField']);
		self::assertSame(['field' => 'status', 'in' => ['awaiting_customer']], $reply['rowWhen']);
		self::assertSame(['ticket', 'message'], $reply['fields']);
		self::assertSame(['message'], $reply['requiredFields']);
		self::assertSame(['visible' => false, 'required' => true], $reply['fieldConfigs']['ticket']);
	}//end testReplyIsOfferedOnlyWhileAwaitingTheResident()

	/**
	 * Every action has a distinct id, requires only fields it asks, configures
	 * every field it asks, and labels its form in Dutch.
	 *
	 * @return void
	 */
	public function testEveryActionIsSelfConsistent(): void {
		$questions = new PortalQuestionActions();
		$actions = [$questions->ask(), $questions->askAboutDossier(), $questions->reply()];

		$ids = array_column($actions, 'id');
		self::assertSame($ids, array_unique($ids));
		foreach ($actions as $action) {
			self::assertSame([], array_diff($action['requiredFields'], $action['fields']), $action['id']);
			self::assertEqualsCanonicalizing($action['fields'], array_keys($action['fieldConfigs']), $action['id']);
			self::assertNotSame('', $action['label'], $action['id']);
			self::assertNotSame('', $action['successMessage'], $action['id']);
			self::assertStringEndsWith('versturen', $action['submitLabel'], $action['id']);
		}
	}//end testEveryActionIsSelfConsistent()
}//end class
