<?php

/**
 * LastContactReader: the client's most recent contact moment.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
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

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\LastContactReader;
use OCA\Pipelinq\Service\TicketService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use PHPUnit\Framework\TestCase;

/**
 * Only the client's own contact moments with a readable moment count.
 */
class LastContactReaderTest extends TestCase {

	/**
	 * Every way the last contact is found: rows of another client or of
	 * another ticket type are ignored even when the store hands them back;
	 * a blank or unreadable moment is skipped; the latest moment wins, an
	 * equal later one does not replace it; it comes back trimmed; no moment
	 * is none.
	 *
	 * @return void
	 */
	public function testEveryWayTheLastContactIsFound(): void {
		$cm = TicketService::TYPE_CONTACTMOMENT;
		$rows = [
			['client' => 'c-2', 'ticketType' => $cm, 'occurredAt' => '2030-01-01T00:00:00Z'],
			['client' => 'c-1', 'ticketType' => 'request', 'occurredAt' => '2030-01-01T00:00:00Z'],
			['client' => 'c-1', 'ticketType' => $cm, 'occurredAt' => ' '],
			['client' => 'c-1', 'ticketType' => $cm, 'occurredAt' => 'not a date'],
			['client' => 'c-1', 'ticketType' => $cm, 'occurredAt' => ' 2026-03-01T10:00:00+00:00 '],
			['client' => 'c-1', 'ticketType' => $cm, 'occurredAt' => '2026-02-01T10:00:00Z'],
			['client' => 'c-1', 'ticketType' => $cm, 'occurredAt' => '2026-03-01T11:00:00+01:00'],
		];
		$calls = [];
		$store = new class ($rows, $calls) extends InMemoryListObjectStore {
			/**
			 * Constructor.
			 *
			 * @param array<int, array<string, mixed>> $handed The rows handed back.
			 * @param array<int, mixed>                $calls  The calls.
			 */
			public function __construct(private array $handed, public array &$calls) {
				parent::__construct();
			}//end __construct()

			/**
			 * Hand back every row, ignoring the filters, recording the call.
			 *
			 * @param string               $schemaSlug The schema.
			 * @param array<string, mixed> $filters    The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(string $schemaSlug, array $filters = []): array {
				$this->calls[] = [$schemaSlug, $filters];
				return $this->handed;
			}//end findAll()
		};

		$this->assertSame('2026-03-01T10:00:00+00:00', (new LastContactReader($store))->forClient(clientId: 'c-1'));
		$this->assertSame([['ticket', ['client' => 'c-1', 'ticketType' => $cm]]], $calls);
		$this->assertNull((new LastContactReader($store))->forClient(clientId: 'c-9'));
	}//end testEveryWayTheLastContactIsFound()
}//end class
