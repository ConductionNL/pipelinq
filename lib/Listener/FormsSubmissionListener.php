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

use OCA\Pipelinq\Service\WebForm\FormsQuestionReader;
use OCA\Pipelinq\Service\WebForm\WebFormFinder;
use OCA\Pipelinq\Service\WebForm\WebFormIntakeService;
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
	 * @param WebFormFinder        $finder    Finds the published binding for a Forms form.
	 * @param FormsQuestionReader  $questions Reads the form's questions and the submission's answers.
	 * @param WebFormIntakeService $intake    Submits into the destination.
	 * @param LoggerInterface      $logger    Logger. Answers MUST NOT be written here.
	 *
	 * @spec exclude constructor wiring only; it makes no decision a requirement can describe
	 */
	public function __construct(
		private readonly WebFormFinder $finder,
		private readonly FormsQuestionReader $questions,
		private readonly WebFormIntakeService $intake,
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
		// Compared by class name, not `is_a()`: Forms is optional, and naming its
		// class in a type check would make the analysers treat it as required.
		$classes = array_merge([get_class($event)], array_values(class_parents($event)));
		$read = [$event, 'getWebhookSerializable'];
		if (in_array(self::EVENT, $classes, true) === false || is_callable($read) === false) {
			return;
		}

		$serialised = (array)call_user_func($read);
		$formId = (int)(((array)($serialised['form'] ?? []))['id'] ?? 0);
		$submissionId = (int)(((array)($serialised['submission'] ?? []))['id'] ?? 0);
		if ($formId <= 0 || $submissionId <= 0) {
			return;
		}

		try {
			$binding = $this->finder->publishedByFormsFormId(formsFormId: $formId);
			if ($binding === null) {
				return;
			}

			$questions = $this->questions->questions(formId: $formId);
			$answer = $this->intake->submit(
				form: $binding,
				payload: $this->questions->answers(submissionId: $submissionId, questions: $questions),
				idempotencyKey: 'forms-submission:' . $submissionId
			);
			$this->logger->info(
				'Pipelinq: Nextcloud Forms answer {submission} created {reference}.',
				['submission' => $submissionId, 'reference' => $answer['reference'], 'form' => $formId]
			);
		} catch (WebFormSubmitException $e) {
			$this->logger->error(
				'Pipelinq: Nextcloud Forms answer {submission} on form {form} created nothing: {reason}',
				[
					'submission' => $submissionId,
					'form' => $formId,
					'reason' => $e->getMessage(),
					'codes' => array_map(static fn (array $finding): string => (string)($finding['code'] ?? ''), $e->getFindings()),
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq: Nextcloud Forms answer {submission} on form {form} created nothing.',
				['submission' => $submissionId, 'form' => $formId, 'exception' => $e]
			);
		}//end try

	}//end handle()
}//end class
