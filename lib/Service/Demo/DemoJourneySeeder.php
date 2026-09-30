<?php

/**
 * Pipelinq DemoJourneySeeder.
 *
 * Seeds and removes the demo marketing journeys and the runs that fill their
 * run log, for DemoSeedService.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Demo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Demo;

use OCA\Pipelinq\Service\DemoSeedService;
use OCA\Pipelinq\Service\Marketing\JourneyService;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Demo journeys: saved through JourneyService, never switched on.
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */
class DemoJourneySeeder {
	/**
	 * Constructor.
	 *
	 * @param JourneyService $journeyService Journey write path, which compiles each journey into a flow.
	 * @param ListObjectStore $store Session-free object access for the journeys and their runs.
	 * @param ContainerInterface $container Container for the flow engine.
	 * @param DemoSeedValues $values Placeholder and reference resolution.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly JourneyService $journeyService,
		private readonly ListObjectStore $store,
		private readonly ContainerInterface $container,
		private readonly DemoSeedValues $values,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Seed the demo journeys and the runs that fill their run log.
	 *
	 * Journeys go through JourneyService, the write path the journey form uses,
	 * so each one is compiled into a flow like a real journey: saved around it,
	 * a journey is stored as "not compiled", which reads as broken on the list.
	 * The compiler only enables the flow of an ACTIVE journey, so a demo journey
	 * is held to draft or paused and its flow never runs.
	 *
	 * A journey that already exists is skipped with its runs, which keeps a
	 * re-run from doubling the run log.
	 *
	 * @param array<int, array<string, mixed>> $definitions The seed file's `journeys` section.
	 * @param array<string, string> $uuids Section-local keys mapped to seeded uuids.
	 *
	 * @return array{created: int, runs: int, skipped: int} Counts.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function seed(array $definitions, array $uuids): array {
		$counts = ['created' => 0, 'runs' => 0, 'skipped' => 0];
		if ($definitions === []) {
			return $counts;
		}

		$existing = [];
		foreach ($this->journeyService->listJourneys() as $journey) {
			$existing[(string)($journey['name'] ?? '')] = true;
		}

		foreach ($definitions as $definition) {
			$data = ($definition['data'] ?? []);
			if (isset($existing[(string)($data['name'] ?? '')]) === true) {
				$counts['skipped']++;
				continue;
			}

			if (in_array(($data['status'] ?? ''), ['draft', 'paused'], true) === false) {
				$data['status'] = 'draft';
			}

			$saved = $this->journeyService->save(payload: $data, createdByUid: '');
			$journeyId = $this->store->idOf(payload: $saved);
			if ($journeyId === '') {
				$this->logger->warning('Pipelinq demo seed: journey not saved', ['name' => ($data['name'] ?? '')]);
				continue;
			}

			$counts['created']++;
			$counts['runs'] += $this->seedRuns(runs: ($definition['runs'] ?? []), journeyId: $journeyId, uuids: $uuids);
		}//end foreach

		return $counts;
	}//end seed()

	/**
	 * Remove the demo journeys, their runs and the flows they compiled into.
	 *
	 * The flow goes first: a journey deleted without it leaves a flow nothing
	 * points at. Its delete is best effort, because the flow engine only finds
	 * a flow for the organisation that saved it; one it cannot find is logged
	 * and left, which is harmless since a demo journey's flow is never enabled.
	 *
	 * @param array<int, array<string, mixed>> $definitions The seed file's `journeys` section.
	 *
	 * @return array{journeys: int, runs: int} Counts.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function remove(array $definitions): array {
		$counts = ['journeys' => 0, 'runs' => 0];

		$names = $this->demoNames(definitions: $definitions);
		if ($names === []) {
			return $counts;
		}

		foreach ($this->journeyService->listJourneys() as $journey) {
			if (isset($names[(string)($journey['name'] ?? '')]) === false) {
				continue;
			}

			$removed = $this->removeJourney(journey: $journey);
			$counts['journeys'] += $removed['journeys'];
			$counts['runs'] += $removed['runs'];
		}

		return $counts;
	}//end remove()

	/**
	 * Save the runs of one seeded journey.
	 *
	 * @param array<int, array<string, mixed>> $runs The journey definition's `runs`.
	 * @param string $journeyId The seeded journey's id.
	 * @param array<string, string> $uuids Section-local keys mapped to seeded uuids.
	 *
	 * @return int How many runs were saved.
	 */
	private function seedRuns(array $runs, string $journeyId, array $uuids): int {
		$runSchema = $this->store->schemaSlug('journeyRun_schema', JourneyService::RUN_SCHEMA);
		$saved = 0;

		foreach ($runs as $run) {
			$payload = $this->values->resolvePlaceholders(data: ($run['data'] ?? []));
			$payload = $this->values->linkReference(
				data: $payload,
				definition: $run,
				uuids: $uuids,
				keyName: 'contactKey',
				section: 'contacts',
				field: 'contactId',
			);
			$payload = $this->values->linkReference(
				data: $payload,
				definition: $run,
				uuids: $uuids,
				keyName: 'clientKey',
				section: 'clients',
				field: 'clientId',
			);
			$payload['journeyId'] = $journeyId;

			if ($this->store->save(schemaSlug: $runSchema, payload: $payload) !== null) {
				$saved++;
			}
		}

		return $saved;
	}//end seedRuns()

	/**
	 * The names of the seed file's journeys that carry the demo marker.
	 *
	 * Only a journey with the marker is ever deleted.
	 *
	 * @param array<int, array<string, mixed>> $definitions The seed file's `journeys` section.
	 *
	 * @return array<string, true> Name => true.
	 */
	private function demoNames(array $definitions): array {
		$names = [];
		foreach ($definitions as $definition) {
			$name = (string)($definition['data']['name'] ?? '');
			if (str_starts_with($name, DemoSeedService::DEMO_PREFIX) === true) {
				$names[$name] = true;
			}
		}

		return $names;
	}//end demoNames()

	/**
	 * Delete one demo journey: its flow, its runs, then the journey itself.
	 *
	 * @param array<string, mixed> $journey The journey row.
	 *
	 * @return array{journeys: int, runs: int} Counts.
	 */
	private function removeJourney(array $journey): array {
		$counts = ['journeys' => 0, 'runs' => 0];
		$journeyId = $this->store->idOf(payload: $journey);
		if ($journeyId === '') {
			return $counts;
		}

		$this->deleteFlow(flowUuid: trim((string)($journey['flowUuid'] ?? '')));

		$runSchema = $this->store->schemaSlug('journeyRun_schema', JourneyService::RUN_SCHEMA);
		foreach ($this->journeyService->runsFor(journeyId: $journeyId) as $run) {
			if ($this->store->delete(schemaSlug: $runSchema, id: $this->store->idOf(payload: $run)) === true) {
				$counts['runs']++;
			}
		}

		$journeySchema = $this->store->schemaSlug('journey_schema', JourneyService::JOURNEY_SCHEMA);
		if ($this->store->delete(schemaSlug: $journeySchema, id: $journeyId) === true) {
			$counts['journeys']++;
		}

		return $counts;
	}//end removeJourney()

	/**
	 * Delete the flow a demo journey compiled into, when the engine can find it.
	 *
	 * @param string $flowUuid The flow uuid, or empty when it never compiled.
	 *
	 * @return void
	 */
	private function deleteFlow(string $flowUuid): void {
		if ($flowUuid === '') {
			return;
		}

		try {
			$this->container->get(JourneyService::FLOW_SERVICE)->delete($flowUuid);
		} catch (\Throwable $e) {
			$this->logger->info(
				'Pipelinq demo seed: left a demo journey flow in place',
				['flowUuid' => $flowUuid, 'exception' => $e->getMessage()]
			);
		}
	}//end deleteFlow()
}//end class
