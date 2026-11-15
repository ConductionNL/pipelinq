<?php

/**
 * DemoJourneySeeder::seed: every way a demo journey seed ends.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Demo
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Demo;

use OCA\Pipelinq\Service\Demo\DemoJourneySeeder;
use OCA\Pipelinq\Service\Demo\DemoSeedValues;
use OCA\Pipelinq\Service\Marketing\JourneyService;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Journeys are saved never active, existing ones skipped, runs linked.
 */
class DemoJourneySeederTest extends TestCase {

	/**
	 * Every way a seed ends: no definitions reads nothing; an existing name
	 * is skipped with its runs; a status other than draft or paused becomes
	 * draft; a journey that does not save is logged and its runs skipped;
	 * each run is linked to its journey, contact and client, and only saved
	 * runs are counted.
	 *
	 * @return void
	 */
	public function testEveryWayASeedEnds(): void {
		$journeys = $this->createMock(JourneyService::class);
		$journeys->method('listJourneys')->willReturn([['name' => 'Bestaand'], ['id' => 'x']]);
		$saved = [];
		$journeys->method('save')->willReturnCallback(
			static function (array $payload, string $createdByUid) use (&$saved): array {
				$saved[] = [$payload, $createdByUid];
				if ($payload['name'] === 'Mislukt') {
					return [];
				}

				return ['id' => 'j-' . count($saved)] + $payload;
			}
		);
		$store = $this->createMock(ListObjectStore::class);
		$store->method('schemaSlug')->willReturnCallback(static fn (string $key, string $default): string => 'runs-' . $key . '-' . $default);
		$store->method('idOf')->willReturnCallback(static fn (?array $payload): string => (string)($payload['id'] ?? ''));
		$runs = [];
		$store->method('save')->willReturnCallback(
			static function (string $schemaSlug, array $payload) use (&$runs): ?array {
				$runs[] = [$schemaSlug, $payload];
				if (($payload['note'] ?? '') === 'fails') {
					return null;
				}

				return ['id' => 'r-' . count($runs)];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Pipelinq demo seed: journey not saved', ['name' => 'Mislukt']);
		$seeder = new DemoJourneySeeder($journeys, $store, $this->createMock(ContainerInterface::class), new DemoSeedValues(), $logger);

		$this->assertSame(['created' => 0, 'runs' => 0, 'skipped' => 0], $seeder->seed(definitions: [], uuids: []));
		$this->assertSame([], $saved);

		$counts = $seeder->seed(
			definitions: [
				['data' => ['name' => 'Bestaand', 'status' => 'active'], 'runs' => [['data' => ['note' => 'a']]]],
				['data' => ['name' => 'Nieuw', 'status' => 'active'], 'runs' => [
					['data' => ['note' => 'one'], 'contactKey' => 'c1', 'clientKey' => 'k9'],
					['data' => ['note' => 'fails']],
				]],
				['data' => ['name' => 'Mislukt', 'status' => 'paused'], 'runs' => [['data' => ['note' => 'never']]]],
				['data' => ['name' => 'Gepauzeerd', 'status' => 'paused']],
			],
			uuids: ['contacts:c1' => 'contact-uuid', 'clients:k1' => 'client-uuid']
		);

		$this->assertSame(['created' => 2, 'runs' => 1, 'skipped' => 1], $counts);
		$this->assertSame(
			[[['name' => 'Nieuw', 'status' => 'draft'], ''], [['name' => 'Mislukt', 'status' => 'paused'], ''], [['name' => 'Gepauzeerd', 'status' => 'paused'], '']],
			$saved
		);
		$this->assertSame(
			[
				['runs-journeyRun_schema-' . JourneyService::RUN_SCHEMA, ['note' => 'one', 'contactId' => 'contact-uuid', 'journeyId' => 'j-1']],
				['runs-journeyRun_schema-' . JourneyService::RUN_SCHEMA, ['note' => 'fails', 'journeyId' => 'j-1']],
			],
			$runs
		);
	}//end testEveryWayASeedEnds()
}//end class
