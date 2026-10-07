<?php

/**
 * Pipelinq PipelineStageData.
 *
 * Data provider for default pipeline stage configurations.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/pipeline/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Data provider for default pipeline stage configurations.
 *
 * @spec exclude value object holding one stage row for the pipeline summary
 */
class PipelineStageData {
	/**
	 * Get the default sales pipeline data.
	 *
	 * A mapping without a stage total leaves `totalsProperty` out: the schema
	 * types it as a string, so a null fails validation and the whole default
	 * pipeline is refused (pipelinq review R5).
	 *
	 * @param ?string $viewId   The view ID to associate with the pipeline.
	 * @param string  $currency The reporting currency, shown as the totals label.
	 *
	 * @return array The sales pipeline object data.
	 * @spec   openspec/changes/reverse-2026-05-26-be-dashboard-pipeline/tasks.md#task-2
	 * @spec   openspec/changes/review-finish/specs/pipeline/spec.md
	 */
	public function getSalesPipelineData(?string $viewId=null, string $currency='EUR'): array {
		return $this->withViewId(
			data: [
				'title'            => 'Sales Pipeline',
				'description'      => 'Default sales pipeline for tracking leads from first contact through to won or lost.',
				'propertyMappings' => [
					['schemaSlug' => 'lead', 'columnProperty' => 'stage', 'totalsProperty' => 'value'],
					['schemaSlug' => 'request', 'columnProperty' => 'stage'],
				],
				'totalsLabel'      => $currency,
				'isDefault'        => true,
				'stages'           => $this->getSalesStages(),
			],
			viewId: $viewId
		);
	}//end getSalesPipelineData()

	/**
	 * Get the default service requests pipeline data.
	 *
	 * @param ?string $viewId The view ID to associate with the pipeline.
	 *
	 * @return array The service requests pipeline object data.
	 * @spec   openspec/changes/reverse-2026-05-26-be-dashboard-pipeline/tasks.md#task-3
	 * @spec   openspec/changes/review-finish/specs/pipeline/spec.md
	 */
	public function getServiceRequestsPipelineData(?string $viewId=null): array {
		return $this->withViewId(
			data: [
				'title'            => 'Service Requests',
				'description'      => 'Default pipeline for tracking service requests from intake through completion.',
				'propertyMappings' => [
					['schemaSlug' => 'request', 'columnProperty' => 'status'],
				],
				'isDefault'        => false,
				'stages'           => $this->getServiceRequestStages(),
			],
			viewId: $viewId
		);
	}//end getServiceRequestsPipelineData()

	/**
	 * Add the view ID when there is one. A null is left out rather than sent,
	 * so nothing in the payload is null.
	 *
	 * @param array   $data   The pipeline data.
	 * @param ?string $viewId The view ID, or null.
	 *
	 * @return array The pipeline data.
	 * @spec   openspec/changes/review-finish/specs/pipeline/spec.md
	 */
	private function withViewId(array $data, ?string $viewId): array {
		if ($viewId !== null) {
			$data['viewId'] = $viewId;
		}

		return $data;
	}//end withViewId()

	/**
	 * Get the default sales pipeline stages.
	 *
	 * @return array The sales pipeline stages.
	 */
	private function getSalesStages(): array {
		return [
			[
				'name' => 'New',
				'order' => 0,
				'probability' => 10,
				'color' => '#3b82f6',
				'isClosed' => false,
				'isWon' => false,
			],
			[
				'name' => 'Contacted',
				'order' => 1,
				'probability' => 20,
				'color' => '#8b5cf6',
				'isClosed' => false,
				'isWon' => false,
			],
			[
				'name' => 'Qualified',
				'order' => 2,
				'probability' => 40,
				'color' => '#f59e0b',
				'isClosed' => false,
				'isWon' => false,
			],
			[
				'name' => 'Proposal',
				'order' => 3,
				'probability' => 60,
				'color' => '#f97316',
				'isClosed' => false,
				'isWon' => false,
			],
			[
				'name' => 'Negotiation',
				'order' => 4,
				'probability' => 80,
				'color' => '#ef4444',
				'isClosed' => false,
				'isWon' => false,
			],
			[
				'name' => 'Won',
				'order' => 5,
				'probability' => 100,
				'color' => '#22c55e',
				'isClosed' => true,
				'isWon' => true,
			],
			[
				'name' => 'Lost',
				'order' => 6,
				'probability' => 0,
				'color' => '#6b7280',
				'isClosed' => true,
				'isWon' => false,
			],
		];
	}//end getSalesStages()

	/**
	 * Get the default service request pipeline stages.
	 *
	 * @return array The service request pipeline stages.
	 */
	private function getServiceRequestStages(): array {
		return [
			['name' => 'New', 'order' => 0, 'color' => '#3b82f6', 'isClosed' => false, 'isWon' => false],
			['name' => 'In Progress', 'order' => 1, 'color' => '#f59e0b', 'isClosed' => false, 'isWon' => false],
			['name' => 'Completed', 'order' => 2, 'color' => '#22c55e', 'isClosed' => true, 'isWon' => true],
			['name' => 'Rejected', 'order' => 3, 'color' => '#ef4444', 'isClosed' => true, 'isWon' => false],
			['name' => 'Converted to Case', 'order' => 4, 'color' => '#8b5cf6', 'isClosed' => true, 'isWon' => false],
		];
	}//end getServiceRequestStages()
}//end class
