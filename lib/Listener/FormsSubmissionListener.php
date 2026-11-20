<?php

/**
 * A Nextcloud Forms answer creates the object its binding names, in the submit event.
 *
 * Decision 181, Q6: Nextcloud Forms stays a form source only through a
 * binding that is checked against the destination when it is made (a webForm
 * with `formsFormId`, judged by WebFormBindingCheck on save) and that creates
 * the object in the submit event. The answers stay in Nextcloud Forms as that
 * app's own record; pipelinq keeps no copy and no queue.
 *
 * 🔴 IT NAMES NO FORMS CLASS. Forms is optional: the event is taken as the
 * framework's `Event`, its class is a string constant, and the registration is
 * guarded on `class_exists`, so an instance without Forms never builds this
 * listener. Forms' FormSubmittedEvent keeps its submission private and exposes
 * it only through `getWebhookSerializable()`, which is what is read here.
 *
 * Forms has already stored the answer when this runs, so a refusal cannot be
 * shown to the person who filled it in. It is logged with the form and
 * submission ids and the finding codes, never the answers, so an admin can
 * see which binding needs fixing.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-nextcloud-forms-form-feeds-pipelinq-only-through-a-checked-binding
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\Pipelinq\Kernel\Contracts\FormsQuestionReaderInterface;
use OCA\Pipelinq\Kernel\Contracts\WebFormFinderInterface;
use OCA\Pipelinq\Kernel\Contracts\WebFormIntakeServiceInterface;
use OCA\Pipelinq\Service\WebForm\WebFormSubmitException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Submits a bound Nextcloud Forms answer into its destination.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-nextcloud-forms-form-feeds-pipelinq-only-through-a-checked-binding
 */
class FormsSubmissionListener implements IEventListener {

	/**
	 * Nextcloud Forms' submit event. A plain string, never imported.
	 *
	 * @var string
	 */
	public const EVENT = 'OCA\Forms\Events\FormSubmittedEvent';

	/**
	 * Constructor.
	 *
	 * @param WebFormFinderInterface        $finder    Finds the published binding for a Forms form.
	 * @param FormsQuestionReaderInterface  $questions Reads the form's questions and the submission's answers.
	 * @param WebFormIntakeServiceInterface $intake    Submits into the destination.
	 * @param LoggerInterface      $logger    Logger. Answers MUST NOT be written here.
	 *
	 * @spec exclude constructor wiring only; it makes no decision a requirement can describe
	 */
	public function __construct(
		private readonly WebFormFinderInterface $finder,
		private readonly FormsQuestionReaderInterface $questions,
		private readonly WebFormIntakeServiceInterface $intake,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Submit one Forms answer when a published binding names its form.
	 *
	 * @param Event $event The Forms submit event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-nextcloud-forms-form-feeds-pipelinq-only-through-a-checked-binding
	 */
	public function handle(Event $event): void {
		$answer = $this->submittedAnswer(event: $event);
		if ($answer === null) {
			return;
		}

		try {
			$this->createFromAnswer(answer: $answer);
		} catch (WebFormSubmitException $e) {
			$this->logRefused(answer: $answer, refusal: $e);
		} catch (Throwable $e) {
			$this->logFailed(answer: $answer, failure: $e);
		}
	}//end handle()

	/**
	 * The answer a Forms submit event carries, or null for another event or an answer without ids.
	 *
	 * @param Event $event The event.
	 *
	 * @return FormsAnswer|null The answer's ids.
	 */
	private function submittedAnswer(Event $event): ?FormsAnswer {
		if ($this->isFormsSubmit(event: $event) === false) {
			return null;
		}

		$serialised = (array)call_user_func([$event, 'getWebhookSerializable']);
		$formId = (int)(((array)($serialised['form'] ?? []))['id'] ?? 0);
		$submissionId = (int)(((array)($serialised['submission'] ?? []))['id'] ?? 0);
		if ($formId <= 0 || $submissionId <= 0) {
			return null;
		}

		return new FormsAnswer(formId: $formId, submissionId: $submissionId);
	}//end submittedAnswer()

	/**
	 * Whether the event is Forms' submit event and can be read.
	 *
	 * Compared by class name, not `is_a()`: Forms is optional, and naming its
	 * class in a type check would make the analysers treat it as required.
	 *
	 * @param Event $event The event.
	 *
	 * @return bool True for a readable Forms submit event.
	 */
	private function isFormsSubmit(Event $event): bool {
		$classes = array_merge([get_class($event)], array_values(class_parents($event)));
		$read = [$event, 'getWebhookSerializable'];
		return in_array(self::EVENT, $classes, true) === true && is_callable($read) === true;
	}//end isFormsSubmit()

	/**
	 * Submit an answer into the destination its published binding names; nothing when no binding names its form.
	 *
	 * @param FormsAnswer $answer The answer's ids.
	 *
	 * @return void
	 */
	private function createFromAnswer(FormsAnswer $answer): void {
		$binding = $this->finder->publishedByFormsFormId(formsFormId: $answer->formId);
		if ($binding === null) {
			return;
		}

		$questions = $this->questions->questions(formId: $answer->formId);
		$created = $this->intake->submit(
			form: $binding,
			payload: $this->questions->answers(submissionId: $answer->submissionId, questions: $questions),
			idempotencyKey: 'forms-submission:' . $answer->submissionId
		);
		$this->logger->info(
			'Pipelinq: Nextcloud Forms answer {submission} created {reference}.',
			['submission' => $answer->submissionId, 'reference' => $created['reference'], 'form' => $answer->formId]
		);
	}//end createFromAnswer()

	/**
	 * Log a refused answer with its ids and finding codes, never its answers.
	 *
	 * @param FormsAnswer            $answer  The answer's ids.
	 * @param WebFormSubmitException $refusal The refusal.
	 *
	 * @return void
	 */
	private function logRefused(FormsAnswer $answer, WebFormSubmitException $refusal): void {
		$this->logger->error(
			'Pipelinq: Nextcloud Forms answer {submission} on form {form} created nothing: {reason}',
			[
				'submission' => $answer->submissionId,
				'form' => $answer->formId,
				'reason' => $refusal->getMessage(),
				'codes' => array_map(static fn (array $finding): string => (string)($finding['code'] ?? ''), $refusal->getFindings()),
			]
		);
	}//end logRefused()

	/**
	 * Log an answer that failed unexpectedly.
	 *
	 * @param FormsAnswer $answer  The answer's ids.
	 * @param Throwable   $failure The failure.
	 *
	 * @return void
	 */
	private function logFailed(FormsAnswer $answer, Throwable $failure): void {
		$this->logger->error(
			'Pipelinq: Nextcloud Forms answer {submission} on form {form} created nothing.',
			['submission' => $answer->submissionId, 'form' => $answer->formId, 'exception' => $failure]
		);
	}//end logFailed()
}//end class
