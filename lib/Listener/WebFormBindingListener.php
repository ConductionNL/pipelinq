<?php

/**
 * Refuses to save a published website form that its destination would refuse.
 *
 * Decision 179: a form is checked against its destination when it is made,
 * not first when a visitor submits it. Decision 181, Q9: refused from the
 * first release. The check runs on OpenRegister's creating and updating
 * events for the webForm schema, so it holds for every way a form is saved:
 * the admin screen, the objects API, an import.
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
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-public-pipelinq-form-names-its-destination-and-is-checked-when-published
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\Service\WebForm\WebFormBindingCheck;
use OCA\Pipelinq\Service\WebForm\WebFormFinder;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stops the save of a webForm with findings.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-public-pipelinq-form-names-its-destination-and-is-checked-when-published
 */
class WebFormBindingListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param WebFormFinder       $finder Knows the webForm schema id.
	 * @param WebFormBindingCheck $check  Judges the form against its destination.
	 * @param LoggerInterface     $logger Logger.
	 *
	 * @spec exclude constructor wiring only; it makes no decision a requirement can describe
	 */
	public function __construct(
		private readonly WebFormFinder $finder,
		private readonly WebFormBindingCheck $check,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Check a webForm that is being created or updated; refuse the save on any finding.
	 *
	 * A check that cannot run (OpenRegister's validator missing) refuses too:
	 * a published form nobody could check is the defect this listener exists
	 * to prevent, so it fails closed.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-public-pipelinq-form-names-its-destination-and-is-checked-when-published
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->judge(entity: $event->getObject(), event: $event);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->judge(entity: $event->getNewObject(), event: $event);
		}

	}//end handle()

	/**
	 * Check one webForm about to be saved; refuse the save on any finding.
	 *
	 * @param ObjectEntity                            $entity The object as it will be saved.
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The event, to read modified data from and to refuse.
	 *
	 * @return void
	 */
	private function judge(ObjectEntity $entity, ObjectCreatingEvent|ObjectUpdatingEvent $event): void {
		$schema = $this->finder->schemaId();
		if ($schema === '' || (string)$entity->getSchema() !== $schema) {
			return;
		}

		$form = array_merge((array)$entity->getObject(), (array)$event->getModifiedData());
		$form['id'] = (string)$entity->getUuid();

		try {
			$findings = $this->check->findings(form: $form);
		} catch (Throwable $e) {
			$this->logger->error('Pipelinq: a website form could not be checked against its destination.', ['exception' => $e]);
			$findings = [
				[
					'property' => '',
					'code' => 'check-failed',
					'message' => 'The form could not be checked against its destination, so it cannot be published now.',
				],
			];
		}

		if ($findings === []) {
			return;
		}

		$event->setErrors(
			[
				'message' => 'This form cannot be published: a submit could never create a valid object. ' . (string)($findings[0]['message'] ?? ''),
				'findings' => $findings,
			]
		);
		$event->stopPropagation();

	}//end judge()
}//end class
