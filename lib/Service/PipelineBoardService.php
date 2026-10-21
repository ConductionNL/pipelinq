<?php

/**
 * Pipelinq PipelineBoardService.
 *
 * The cards of one pipeline's board: every record the pipeline applies to
 * (leads, requests, complaints, contact moments), each read with the user's
 * own permissions and marked with the property that holds its stage.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/pipeline/spec.md#requirement-kanban-board-view-mvp
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reads the records on one pipeline's board.
 *
 * @spec openspec/specs/pipeline/spec.md#requirement-kanban-board-view-mvp
 */
class PipelineBoardService {

	/**
	 * The record kinds a pipeline that declares no scope applies to.
	 *
	 * @var array<int, string>
	 */
	public const UNSCOPED_SLUGS = ['lead', 'request'];

	/**
	 * The record kinds stored as a `ticket` with this `ticketType`.
	 *
	 * @var array<int, string>
	 */
	public const TICKET_SUBTYPES = [
		TicketService::TYPE_REQUEST,
		TicketService::TYPE_COMPLAINT,
		TicketService::TYPE_CONTACTMOMENT,
	];

	/**
	 * The card filters a caller may send.
	 *
	 * @var array<int, string>
	 */
	public const CARD_FILTERS = ['assignee', 'status'];

	/**
	 * The most records read per kind.
	 *
	 * @var int
	 */
	private const ITEM_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig             $appConfig     App configuration (register and schema ids).
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The cards of a pipeline's board.
	 *
	 * Each card is the stored record with `_schemaSlug` (lead, request, ...),
	 * `_objectType` (lead or ticket), `_columnProperty` (the property its stage
	 * is stored in, `stage` unless the pipeline maps another) and `stage` set
	 * to that property's value, so one board reads every kind alike.
	 *
	 * Without a pipeline id the default pipeline (else the first) is shown.
	 * `filters` narrows the cards further with equality filters the board's
	 * chips send (`assignee`, `status`); other keys are ignored.
	 *
	 * @param string               $pipelineId The pipeline's id, or '' for the default.
	 * @param array<string, mixed> $filters    Equality filters on the cards.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int, pipeline: string}|null The cards, or null when the pipeline is not readable.
	 *
	 * @spec openspec/specs/pipeline/spec.md#requirement-kanban-board-view-mvp
	 */
	public function items(string $pipelineId, array $filters=[]): ?array {
		$pipeline = $this->findPipeline(pipelineId: $pipelineId);
		if ($pipeline === null) {
			return null;
		}

		$pipelineId = (string) ($pipeline['id'] ?? '');
		$narrow = array_intersect_key($filters, array_flip(self::CARD_FILTERS));

		$rows = [];
		foreach ($this->slugsOf(pipeline: $pipeline) as $slug) {
			$columnProperty = $this->columnPropertyOf(pipeline: $pipeline, slug: $slug);
			foreach ($this->recordsOf(slug: $slug, pipelineId: $pipelineId, narrow: $narrow) as $record) {
				$rows[] = array_merge(
					$record,
					[
						'_schemaSlug'     => $slug,
						'_objectType'     => $this->objectTypeOf(slug: $slug),
						'_columnProperty' => $columnProperty,
						'stage'           => ($record[$columnProperty] ?? null),
					]
				);
			}
		}

		return ['results' => $rows, 'total' => count($rows), 'pipeline' => $pipelineId];
	}//end items()

	/**
	 * The record kinds a pipeline applies to: its property mappings, else its
	 * older `entityType`, else every kind a board shows by default.
	 *
	 * @param array<string, mixed> $pipeline The stored pipeline.
	 *
	 * @return array<int, string> The kinds.
	 *
	 * @spec openspec/specs/pipeline/spec.md#requirement-kanban-board-view-mvp
	 */
	public function slugsOf(array $pipeline): array {
		$mappings = ($pipeline['propertyMappings'] ?? null);
		if (is_array($mappings) === true) {
			$slugs = [];
			foreach ($mappings as $mapping) {
				if (is_array($mapping) === true && is_string($mapping['schemaSlug'] ?? null) === true && $mapping['schemaSlug'] !== '') {
					$slugs[] = $mapping['schemaSlug'];
				}
			}

			if ($slugs !== []) {
				return array_values(array_unique($slugs));
			}
		}

		$entityType = (string) ($pipeline['entityType'] ?? '');
		if ($entityType === 'both') {
			return ['lead', 'request'];
		}

		if ($entityType !== '') {
			return [$entityType];
		}

		return self::UNSCOPED_SLUGS;
	}//end slugsOf()

	/**
	 * The property a kind's stage is stored in on this pipeline.
	 *
	 * @param array<string, mixed> $pipeline The stored pipeline.
	 * @param string               $slug     The record kind.
	 *
	 * @return string The property, `stage` by default.
	 */
	private function columnPropertyOf(array $pipeline, string $slug): string {
		foreach (($pipeline['propertyMappings'] ?? []) as $mapping) {
			if (is_array($mapping) === true && ($mapping['schemaSlug'] ?? null) === $slug) {
				$property = (string) ($mapping['columnProperty'] ?? '');
				if ($property !== '') {
					return $property;
				}
			}
		}

		return 'stage';
	}//end columnPropertyOf()

	/**
	 * The OpenRegister object type a kind is stored as.
	 *
	 * @param string $slug The record kind.
	 *
	 * @return string `ticket` for a ticket subtype, else the kind itself.
	 */
	private function objectTypeOf(string $slug): string {
		if (in_array($slug, self::TICKET_SUBTYPES, true) === true) {
			return 'ticket';
		}

		return $slug;
	}//end objectTypeOf()

	/**
	 * The records of one kind on a pipeline, read with the user's permissions.
	 *
	 * @param string               $slug       The record kind.
	 * @param string               $pipelineId The pipeline's id.
	 * @param array<string, mixed> $narrow     Extra equality filters.
	 *
	 * @return array<int, array<string, mixed>> The records.
	 */
	private function recordsOf(string $slug, string $pipelineId, array $narrow): array {
		$objectType = $this->objectTypeOf(slug: $slug);
		$filters = array_merge($narrow, ['pipeline' => $pipelineId]);
		if ($objectType === 'ticket') {
			$filters['ticketType'] = $slug;
		}

		return $this->findAll(schemaKey: $objectType.'_schema', filters: $filters);
	}//end recordsOf()

	/**
	 * The pipeline, read with the user's permissions.
	 *
	 * @param string $pipelineId The pipeline's id, or '' for the default one.
	 *
	 * @return array<string, mixed>|null The pipeline, or null when absent or not readable.
	 */
	private function findPipeline(string $pipelineId): ?array {
		if ($pipelineId === '') {
			$pipelines = $this->findAll(schemaKey: 'pipeline_schema', filters: []);
			foreach ($pipelines as $pipeline) {
				if (($pipeline['isDefault'] ?? false) === true) {
					return $pipeline;
				}
			}

			return ($pipelines[0] ?? null);
		}

		$found = $this->findAll(schemaKey: 'pipeline_schema', filters: ['id' => $pipelineId]);
		foreach ($found as $pipeline) {
			if ((string) ($pipeline['id'] ?? '') === $pipelineId) {
				return $pipeline;
			}
		}

		return null;
	}//end findPipeline()

	/**
	 * Objects of a configured schema.
	 *
	 * @param string               $schemaKey The app-config key of the schema id.
	 * @param array<string, mixed> $filters   Equality filters.
	 *
	 * @return array<int, array<string, mixed>> The objects, empty when the schema is not configured.
	 */
	private function findAll(string $schemaKey, array $filters): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			return [];
		}

		$results = $this->objectService->findAll(
			config: [
				'filters' => array_merge(['register' => $register, 'schema' => $schema], $filters),
				'limit'   => self::ITEM_LIMIT,
			]
		);

		$objects = [];
		foreach ($results as $result) {
			$objects[] = $this->toArray(object: $result);
		}

		return $objects;
	}//end findAll()

	/**
	 * An object as an array.
	 *
	 * @param mixed $object The object or array.
	 *
	 * @return array<string, mixed> The array.
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return [];
	}//end toArray()
}//end class
