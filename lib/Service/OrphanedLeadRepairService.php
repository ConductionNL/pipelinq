<?php

/**
 * Pipelinq OrphanedLeadRepairService.
 *
 * Moves leads off a pipeline that no longer exists. Such a lead is on no
 * board, yet counts in the open pipeline and the forecast (pipelinq review
 * F1). Each one goes to the default lead pipeline; OrphanedLeadPlacer decides the
 * stage. The writes run as the signed-in user, or as the pipelinq system
 * account during a repair, always with OpenRegister's access checks on.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-a-repair-step-moves-leads-off-a-deleted-pipeline-req-raf-011
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\OrphanedLeadRepairServiceInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-homes leads whose pipeline was deleted.
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-a-repair-step-moves-leads-off-a-deleted-pipeline-req-raf-011
 */
class OrphanedLeadRepairService implements OrphanedLeadRepairServiceInterface {
	/**
	 * Upper bound on the objects read per schema in one run.
	 */
	private const SCAN_LIMIT = 10000;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig             $appConfig     Register and schema ids.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 * @param SystemServiceAccount   $systemAccount The account the writes run as when nobody is signed in.
	 * @param OrphanedLeadPlacer     $placer        Decides the pipeline and stage.
	 * @param LoggerInterface        $logger        The logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly SystemServiceAccount $systemAccount,
		private readonly OrphanedLeadPlacer $placer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Move every lead on a deleted pipeline to the default lead pipeline.
	 *
	 * @return array{moved: int, failed: int} How many leads moved, and how many could not be saved.
	 *
	 * @spec openspec/specs/lead-management/spec.md#requirement-a-repair-step-moves-leads-off-a-deleted-pipeline-req-raf-011
	 */
	public function repair(): array {
		return $this->systemAccount->runAsCurrentOrSystem(fn (): array => $this->repairAsCaller());
	}//end repair()

	/**
	 * Move the orphaned leads, as the active user.
	 *
	 * @return array{moved: int, failed: int} The counts.
	 */
	private function repairAsCaller(): array {
		$counts = ['moved' => 0, 'failed' => 0];

		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$leadSchema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'lead_schema', '');
		$pipelineSchema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'pipeline_schema', '');
		if ($register === '' || $leadSchema === '' || $pipelineSchema === '') {
			return $counts;
		}

		$pipelines = $this->readAll(register: $register, schema: $pipelineSchema);
		// No pipelines read means nothing can be told apart from a deleted
		// one: an outage would otherwise move every lead.
		if ($pipelines === []) {
			return $counts;
		}

		return $this->moveOrphans(target: ['register' => $register, 'schema' => $leadSchema], pipelines: $pipelines);
	}//end repairAsCaller()

	/**
	 * Move every lead that sits on a deleted pipeline, counting the outcomes.
	 *
	 * @param array<string, string>            $target    Keys: register, schema (the lead schema).
	 * @param array<int, array<string, mixed>> $pipelines The stored pipelines.
	 *
	 * @return array{moved: int, failed: int} The counts.
	 */
	private function moveOrphans(array $target, array $pipelines): array {
		$counts = ['moved' => 0, 'failed' => 0];
		foreach ($this->readAll(register: $target['register'], schema: $target['schema']) as $lead) {
			$outcome = $this->moveLead(lead: $lead, pipelines: $pipelines, target: $target);
			if ($outcome !== null) {
				$counts[$outcome]++;
			}
		}

		return $counts;
	}//end moveOrphans()

	/**
	 * Move one lead off a deleted pipeline.
	 *
	 * @param array<string, mixed>             $lead      The lead.
	 * @param array<int, array<string, mixed>> $pipelines The stored pipelines.
	 * @param array<string, string>            $target    Keys: register, schema (the lead schema).
	 *
	 * @return string|null 'moved' or 'failed', null when the lead needs no move.
	 */
	private function moveLead(array $lead, array $pipelines, array $target): string|null {
		$patch = $this->placer->forOrphanedLead(lead: $lead, pipelines: $pipelines);
		$uuid = (string)($lead['id'] ?? '');
		if ($patch === [] || $uuid === '') {
			return null;
		}

		$payload = $lead;
		unset($payload['@self']);
		try {
			$this->objectService->saveObject(
				object: array_merge($payload, $patch),
				extend: [],
				register: $target['register'],
				schema: $target['schema'],
				uuid: $uuid,
			);
			return 'moved';
		} catch (Throwable $e) {
			$this->logger->error('Pipelinq: could not move a lead off a deleted pipeline', ['uuid' => $uuid, 'exception' => $e->getMessage()]);
			return 'failed';
		}
	}//end moveLead()

	/**
	 * Read every object of a schema as plain arrays.
	 *
	 * @param string $register The register id.
	 * @param string $schema   The schema id.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function readAll(string $register, string $schema): array {
		try {
			$rows = $this->objectService->findAll(
				config: [
					'filters' => ['register' => $register, 'schema' => $schema],
					'limit' => self::SCAN_LIMIT,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: could not read objects for the orphaned lead repair',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		return $this->plainRows(rows: $rows);
	}//end readAll()

	/**
	 * Rows as plain arrays; a row with no array shape is dropped.
	 *
	 * @param iterable<mixed> $rows The rows OpenRegister returned.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function plainRows(iterable $rows): array {
		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
				continue;
			}

			if ($row instanceof \JsonSerializable) {
				$data = $row->jsonSerialize();
				if (is_array($data) === true) {
					$out[] = $data;
				}
			}
		}

		return $out;
	}//end plainRows()
}//end class
