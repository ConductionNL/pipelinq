<?php

/**
 * Unit tests for FormsSubmissionListener.
 *
 * Driven with a fixture of Forms' FormSubmittedEvent (same public surface:
 * the submission only through getWebhookSerializable()), the real finder,
 * reader and intake, over fakes with OpenRegister's and Forms' signatures.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-nextcloud-forms-form-feeds-pipelinq-only-through-a-checked-binding
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

require_once __DIR__ . '/Fixtures/FormSubmittedEvent.php';

use OCA\Forms\Events\FormSubmittedEvent;
use OCA\Pipelinq\Listener\FormsSubmissionListener;
use OCA\Pipelinq\Service\SystemServiceAccount;
use OCA\Pipelinq\Service\WebForm\FormsQuestionReader;
use OCA\Pipelinq\Service\WebForm\WebFormFinder;
use OCA\Pipelinq\Service\WebForm\WebFormIntakeService;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\FakeContainer;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\FakeFormSubmitService;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\FakeObjectStore;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\ObjectServiceContract;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\FakeRefusal;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A bound Forms answer creates its destination; anything else creates nothing.
 */
class FormsSubmissionListenerTest extends TestCase {

	use ObjectServiceContract;

	/**
	 * The submit fake of the last built listener.
	 *
	 * @var FakeFormSubmitService
	 */
	private FakeFormSubmitService $submitter;

	/**
	 * The logger of the last built listener.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The listener over one binding of Forms form 5 into a ticket.
	 *
	 * @param Throwable|null $throw What the submit throws, when set.
	 *
	 * @return FormsSubmissionListener The listener.
	 */
	private function listener(?Throwable $throw = null): FormsSubmissionListener {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ['register' => '20', 'webForm_schema' => '40'][$key] ?? $default
		);
		$binding = [
			'@self' => ['id' => 'f-forms'],
			'key' => 'nc-forms-callback',
			'status' => 'published',
			'formsFormId' => 5,
			'destination' => ['register' => 'pipelinq', 'schema' => 'ticket'],
			'mapping' => [
				'fields' => [
					['field' => '11', 'property' => 'reporterEmail', 'type' => 'email'],
					['field' => '12', 'property' => 'title', 'type' => 'text'],
				],
				'fixed' => ['ticketType' => 'request', 'channel' => 'forms'],
			],
		];
		$questions = new class {
			/**
			 * The form's questions.
			 *
			 * @param int $formId The form.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findByForm(int $formId): array {
				return [
					['id' => 11, 'type' => 'short', 'text' => 'Email', 'isRequired' => true, 'extraSettings' => ['validationType' => 'email']],
					['id' => 12, 'type' => 'short', 'text' => 'Subject', 'isRequired' => true, 'extraSettings' => []],
				];
			}
		};
		$answers = new class {
			/**
			 * A submission's answers.
			 *
			 * @param int $submissionId The submission.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findBySubmission(int $submissionId): array {
				return [['questionId' => 11, 'text' => 'jane@example.com'], ['questionId' => 12, 'text' => 'Please call me']];
			}
		};
		$this->submitter = new FakeFormSubmitService(throw: $throw);
		$container = new FakeContainer(
			services: [
				'OCA\OpenRegister\Service\Form\FormSubmitService' => $this->submitter,
				FormsQuestionReader::QUESTION_MAPPER => $questions,
				FormsQuestionReader::ANSWER_MAPPER => $answers,
			]
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$this->logger = $this->createMock(LoggerInterface::class);
		$finder = new WebFormFinder($config, $this->contractOver(new FakeObjectStore(rows: ['40' => [$binding]])));
		$intake = new WebFormIntakeService(
			$finder,
			$container,
			new SystemServiceAccount($users, $this->createMock(IUserSession::class), $this->createMock(ISecureRandom::class)),
			$this->logger
		);

		return new FormsSubmissionListener($finder, new FormsQuestionReader($container), $intake, $this->logger);
	}//end listener()

	/**
	 * The event class this listener registers for is the fixture's, the one Forms publishes.
	 *
	 * @return void
	 */
	public function testListensForFormsSubmitEvent(): void {
		$this->assertSame(FormSubmittedEvent::class, FormsSubmissionListener::EVENT);
	}//end testListensForFormsSubmitEvent()

	/**
	 * A bound answer is submitted into the destination, keyed by question id, idempotent on the submission.
	 *
	 * @return void
	 */
	public function testBoundAnswerCreatesItsDestination(): void {
		$this->listener()->handle(new FormSubmittedEvent(['id' => 5, 'hash' => 'abc'], ['id' => 9, 'formId' => 5]));

		$this->assertCount(1, $this->submitter->calls);
		$call = $this->submitter->calls[0];
		$this->assertSame('ticket', $call['writes'][0]['schema']);
		$this->assertSame(['11' => 'jane@example.com', '12' => 'Please call me'], $call['payload']);
		$this->assertSame('forms-submission:9', $call['idempotencyKey']);
	}//end testBoundAnswerCreatesItsDestination()

	/**
	 * An answer on a form no binding names creates nothing.
	 *
	 * @return void
	 */
	public function testUnboundFormCreatesNothing(): void {
		$this->listener()->handle(new FormSubmittedEvent(['id' => 6], ['id' => 9]));

		$this->assertSame([], $this->submitter->calls);
	}//end testUnboundFormCreatesNothing()

	/**
	 * Any other event is ignored.
	 *
	 * @return void
	 */
	public function testOtherEventsAreIgnored(): void {
		$this->listener()->handle(new Event());

		$this->assertSame([], $this->submitter->calls);
	}//end testOtherEventsAreIgnored()

	/**
	 * A refused answer is logged with its ids and finding codes, never its answers, and nothing is thrown.
	 *
	 * @return void
	 */
	public function testRefusalIsLoggedWithoutAnswers(): void {
		$listener = $this->listener(throw: new FakeRefusal('Refused.', 422, [['property' => 'title', 'code' => 'maxLength']]));
		$this->logger->expects($this->once())->method('error')->with(
			$this->stringContains('created nothing'),
			$this->callback(
				static function (array $context): bool {
					return $context['submission'] === 9
						&& $context['form'] === 5
						&& $context['codes'] === ['maxLength']
						&& str_contains((string)json_encode($context), 'jane@example.com') === false;
				}
			)
		);

		$listener->handle(new FormSubmittedEvent(['id' => 5], ['id' => 9]));
	}//end testRefusalIsLoggedWithoutAnswers()
}//end class
