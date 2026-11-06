<?php

/**
 * Pipelinq StageLimitGuard.
 *
 * Refuses a create or update that would put one item more into a pipeline
 * stage that has reached its limit, whatever screen the write came from.
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
 * @spec openspec/changes/pipeline-stage-limit/specs/pipeline-stage-limits/spec.md#requirement-no-write-puts-an-item-into-a-full-stage-req-psl-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SchemaMapService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The server-side stage limit (pipeline-stage-limit D2).
 *
 * An item that stays in its stage, or leaves one, is never refused. Only a
 * move or a create into a stage with `maxItems` is counted, across every
 * object type the pipeline boards.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/pipeline-stage-limit/specs/pipeline-stage-limits/spec.md#requirement-no-write-puts-an-item-into-a-full-stage-req-psl-002
 */
class StageLimitGuard implements IEventListener {

	/**
	 * Ticket subtypes a pipeline mapping may name; they live in the `ticket` schema.
	 *
	 * @var array<int, string>
	 */
	public const TICKET_SUBTYPES = ['request', 'complaint', 'interaction'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param SchemaMapService $schemaMap Resolves a schema id to its object type.
	 * @param IAppConfig $appConfig The app configuration.
	 * @param IL10N $l10n Words the refusal in the user's language.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @spec openspec/changes/pipeline-stage-limit/specs/pipeline-stage-limits/spec.md#requirement-no-write-puts-an-item-into-a-full-stage-req-psl-002
	 */
	public function __construct(
		private ObjectServiceInterface $objectService,
		private SchemaMapService $schemaMap,
		private IAppConfig $appConfig,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check a create or update and refuse it when the target stage is full.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipeline-stage-limit/specs/pipeline-stage-limits/spec.md#requirement-no-write-puts-an-item-into-a-full-stage-req-psl-002
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$entity = $event->getObject();
			$data = array_merge((array)$entity->getObject(), (array)$event->getModifiedData());
			$this->judge(event: $event, schemaId: (string)$entity->getSchema(), data: $data, old: []);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
			$data = array_merge((array)$entity->getObject(), (array)$event->getModifiedData());
			$old = (array)($event->getOldObject()?->getObject() ?? []);
			$this->judge(event: $event, schemaId: (string)$entity->getSchema(), data: $data, old: $old);
		}
	}//end handle()

	/**
	 * Refuse the write when it moves the item into a full stage.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The event to stop.
	 * @param string $schemaId The item's schema id.
	 * @param array<string, mixed> $data The item as it will be saved.
	 * @param array<string, mixed> $old The item as it was (empty on create).
	 *
	 * @return void
	 */
	private function judge(ObjectCreatingEvent|ObjectUpdatingEvent $event, string $schemaId, array $data, array $old): void {
		$slug = $this->logicalSlug(schemaId: $schemaId, data: $data);
		$pipelineId = trim((string)($data['pipeline'] ?? ''));
		if ($slug === null || $pipelineId === '') {
			return;
		}

		$pipeline = $this->pipeline(pipelineId: $pipelineId);
		$column = $this->columnFor(pipeline: $pipeline, slug: $slug);
		if ($column === null) {
			return;
		}

		$stageName = (string)($data[$column] ?? '');
		$samePipeline = (string)($old['pipeline'] ?? '') === $pipelineId;
		if ($stageName === '' || ($samePipeline === true && (string)($old[$column] ?? '') === $stageName)) {
			return;
		}

		$max = $this->limitOf(pipeline: $pipeline, stageName: $stageName);
		if ($max === null) {
			return;
		}

		$count = $this->countInStage(pipeline: $pipeline, pipelineId: $pipelineId, stageName: $stageName);
		if ($count < $max) {
			return;
		}

		$event->setErrors(
			[
				'message' => $this->l10n->t('Stage %1$s is full (%2$s of %3$s)', [$stageName, (string)$count, (string)$max]),
			]
		);
		$event->stopPropagation();
	}//end judge()

	/**
	 * The slug a pipeline mapping uses for this item: its object type, or the
	 * ticket type for a ticket.
	 *
	 * @param string $schemaId The item's schema id.
	 * @param array<string, mixed> $data The item.
	 *
	 * @return string|null The slug, or null for a schema pipelinq does not map.
	 */
	private function logicalSlug(string $schemaId, array $data): ?string {
		$type = $this->schemaMap->resolveEntityType(schemaId: $schemaId);
		if ($type === 'ticket' && in_array((string)($data['ticketType'] ?? ''), self::TICKET_SUBTYPES, true) === true) {
			return (string)$data['ticketType'];
		}

		return $type;
	}//end logicalSlug()

	/**
	 * Read the pipeline the item sits in.
	 *
	 * @param string $pipelineId The pipeline uuid.
	 *
	 * @return array<string, mixed> The pipeline, or empty when it cannot be read.
	 */
	private function pipeline(string $pipelineId): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'pipeline_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$entity = $this->objectService->find(id: $pipelineId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->warning('StageLimitGuard: pipeline not readable', ['pipeline' => $pipelineId, 'exception' => $e->getMessage()]);
			return [];
		}

		if ($entity === null) {
			return [];
		}

		return (array)$entity->getObject();
	}//end pipeline()

	/**
	 * The property holding the stage for a slug in this pipeline.
	 *
	 * @param array<string, mixed> $pipeline The pipeline.
	 * @param string $slug The item's slug.
	 *
	 * @return string|null The column property, or null when the pipeline does not board the slug.
	 */
	private function columnFor(array $pipeline, string $slug): ?string {
		foreach ($this->mappings(pipeline: $pipeline) as $mapping) {
			if ($mapping['schemaSlug'] === $slug) {
				return $mapping['columnProperty'];
			}
		}

		return null;
	}//end columnFor()

	/**
	 * The pipeline's mappings, each with a slug and a column property.
	 *
	 * @param array<string, mixed> $pipeline The pipeline.
	 *
	 * @return array<int, array{schemaSlug: string, columnProperty: string}> The mappings.
	 */
	private function mappings(array $pipeline): array {
		$mappings = [];
		foreach ((array)($pipeline['propertyMappings'] ?? []) as $mapping) {
			$slug = (string)(((array)$mapping)['schemaSlug'] ?? '');
			if ($slug === '') {
				continue;
			}

			$column = (string)(((array)$mapping)['columnProperty'] ?? '');
			if ($column === '') {
				$column = 'stage';
			}

			$mappings[] = ['schemaSlug' => $slug, 'columnProperty' => $column];
		}

		return $mappings;
	}//end mappings()

	/**
	 * The limit of an open stage, or null when it has none.
	 *
	 * @param array<string, mixed> $pipeline The pipeline.
	 * @param string $stageName The stage.
	 *
	 * @return int|null The limit.
	 */
	private function limitOf(array $pipeline, string $stageName): ?int {
		foreach ((array)($pipeline['stages'] ?? []) as $stage) {
			$stage = (array)$stage;
			if ((string)($stage['name'] ?? '') !== $stageName || ($stage['isClosed'] ?? false) === true) {
				continue;
			}

			$max = ($stage['maxItems'] ?? null);
			if (is_numeric($max) === true && (int)$max > 0) {
				return (int)$max;
			}
		}

		return null;
	}//end limitOf()

	/**
	 * Count the pipeline's items already in a stage, across its mapped types.
	 *
	 * @param array<string, mixed> $pipeline The pipeline.
	 * @param string $pipelineId The pipeline uuid.
	 * @param string $stageName The stage.
	 *
	 * @return int The count.
	 */
	private function countInStage(array $pipeline, string $pipelineId, string $stageName): int {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$count = 0;
		foreach ($this->mappings(pipeline: $pipeline) as $mapping) {
			$filters = $this->filtersFor(slug: $mapping['schemaSlug']);
			if ($filters === null) {
				continue;
			}

			$filters += ['register' => $register, 'pipeline' => $pipelineId, $mapping['columnProperty'] => $stageName];
			try {
				$count += $this->objectService->count(['filters' => $filters]);
			} catch (Throwable $e) {
				$this->logger->warning('StageLimitGuard: counting a stage failed', ['stage' => $stageName, 'exception' => $e->getMessage()]);
			}
		}

		return $count;
	}//end countInStage()

	/**
	 * The schema filter for a mapped slug; a ticket subtype narrows the ticket schema.
	 *
	 * @param string $slug The mapped slug.
	 *
	 * @return array<string, string>|null The filters, or null when the schema is not configured.
	 */
	private function filtersFor(string $slug): ?array {
		$type = $slug;
		$filters = [];
		if (in_array($slug, self::TICKET_SUBTYPES, true) === true) {
			$type = 'ticket';
			$filters['ticketType'] = $slug;
		}

		$schema = $this->appConfig->getValueString(Application::APP_ID, $type.'_schema', '');
		if ($schema === '') {
			return null;
		}

		return ['schema' => $schema] + $filters;
	}//end filtersFor()
}//end class
