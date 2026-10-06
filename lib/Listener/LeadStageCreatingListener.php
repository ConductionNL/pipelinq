<?php

/**
 * Pipelinq LeadStageCreatingListener.
 *
 * Puts every new lead in a pipeline stage before it is stored. The lead form,
 * the enquiry flow, the API and imports all create leads, and only some of
 * them set a stage. Doing it on OpenRegister's pre-save event covers all of
 * them in one place, and the lead is right from its first save rather than
 * after a background job (pipelinq review F1).
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/pipeline-numbers-tell-the-truth/specs/lead-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\LeadStagePlacer;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fills in the pipeline stage of a lead that is being created.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/lead-management/spec.md
 */
class LeadStageCreatingListener implements IEventListener {

	/**
	 * Upper bound on pipelines read per lead.
	 *
	 * @var int
	 */
	private const PIPELINE_LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $appConfig App config, source of the register and schema ids.
	 * @param ContainerInterface $container Container, used to reach OpenRegister's ObjectService.
	 * @param LeadStagePlacer    $placer    Decides the stage.
	 * @param LoggerInterface    $logger    PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly LeadStagePlacer $placer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an object-creating event.
	 *
	 * Only leads are touched. A failure to read the pipelines never blocks the
	 * save: the lead is stored as given and the repair step places it later.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipeline-numbers-tell-the-truth/specs/lead-management/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false) {
			return;
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$leadSchema = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
		$entity = $event->getObject();
		if ($leadSchema === '' || (string)$entity->getSchema() !== $leadSchema) {
			return;
		}

		$lead = array_merge($entity->getObject(), $event->getModifiedData());
		$pipelines = $this->readPipelines(register: $register);
		$patch = $this->placer->forNewLead(lead: $lead, pipelines: $pipelines);
		if ($patch === []) {
			return;
		}

		// Merge, never replace: another listener may already have modified the data.
		$event->setModifiedData(array_merge($event->getModifiedData(), $patch));
	}//end handle()

	/**
	 * Read the stored pipelines.
	 *
	 * RBAC is off on purpose: a pipeline is configuration, and a user who may
	 * create a lead must get it placed even when they cannot open the pipeline
	 * record itself. Only the stage name and order are copied onto the lead.
	 *
	 * @param string $register The register id.
	 *
	 * @return array<int, array<string, mixed>> The pipelines, [] when unreadable.
	 */
	private function readPipelines(string $register): array {
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'pipeline_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = $objectService->findAll(
				config: [
					'filters' => ['register' => $register, 'schema' => $schema],
					'limit' => self::PIPELINE_LIMIT,
				],
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: could not read pipelines to place a new lead in a stage',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$pipelines = [];
		foreach (($rows ?? []) as $row) {
			if (is_array($row) === true) {
				$pipelines[] = $row;
				continue;
			}

			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$data = $row->jsonSerialize();
				if (is_array($data) === true) {
					$pipelines[] = $data;
				}
			}
		}

		return $pipelines;
	}//end readPipelines()
}//end class
