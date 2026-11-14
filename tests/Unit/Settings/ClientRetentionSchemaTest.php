<?php

/**
 * The retention term of clients and contact persons, as the register ships it.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.pipelinq.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/platform-client-retention/specs/client-retention/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * A client's retention starts when its relationship ends, and a contact person
 * follows its client (REQ-PCR-001, REQ-PCR-002). The schemas are read through
 * the loader OpenRegister's import uses, so the fragments are merged.
 *
 * @covers \OCA\Pipelinq\Service\ConfigFileLoaderService
 * @uses \OCA\Pipelinq\Service\ConfigFragmentValue
 */
class ClientRetentionSchemaTest extends TestCase {

	/**
	 * The schemas as the import composes them.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $schemas;

	/**
	 * Load the merged register once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));

		$register = (new ConfigFileLoaderService($appManager))->loadConfigurationFile();
		$this->schemas = ($register['components']['schemas'] ?? []);
	}//end setUp()

	/**
	 * The client records when its relationship ended, read only in forms.
	 *
	 * @return void
	 */
	public function testTheClientCarriesARelationshipEndDate(): void {
		$property = ($this->schemas['client']['properties']['relationshipEndedAt'] ?? null);

		$this->assertIsArray($property);
		$this->assertSame('string', $property['type']);
		$this->assertSame('date-time', $property['format']);
		$this->assertTrue($property['readOnly']);
		$this->assertNotContains('relationshipEndedAt', $this->schemas['client']['required']);
	}//end testTheClientCarriesARelationshipEndDate()

	/**
	 * Every move into inactive stamps the date, every move back to active clears
	 * it, and blocking leaves it alone.
	 *
	 * @return void
	 */
	public function testTheAccountStatusLifecycleStampsAndClearsTheDate(): void {
		$lifecycle = $this->schemas['client']['x-openregister-lifecycle'];
		$this->assertSame('accountStatus', $lifecycle['field']);

		$stamps = [];
		foreach ($lifecycle['transitions'] as $name => $transition) {
			$fields = [];
			foreach (($transition['actions'] ?? []) as $action) {
				$this->assertSame('x-openregister-lifecycle-action', $action['trigger'], $name);
				$this->assertSame('set-fields', $action['action'], $name);
				$fields += $action['actionParameters'];
			}

			$stamps[$transition['to']] = $fields;
		}

		$this->assertSame(['relationshipEndedAt' => '@now'], $stamps['inactive']);
		$this->assertSame(['relationshipEndedAt' => null], $stamps['active']);
		$this->assertSame([], $stamps['blocked']);
	}//end testTheAccountStatusLifecycleStampsAndClearsTheDate()

	/**
	 * The lifecycle declares a transition for every change between the three
	 * statuses, so declaring it refuses no change the client allowed before.
	 *
	 * @return void
	 */
	public function testEveryStatusChangeStaysAllowed(): void {
		$statuses = $this->schemas['client']['properties']['accountStatus']['enum'];
		$declared = [];
		foreach ($this->schemas['client']['x-openregister-lifecycle']['transitions'] as $transition) {
			$this->assertContains($transition['to'], $statuses);
			foreach ((array)$transition['from'] as $from) {
				$this->assertContains($from, $statuses);
				$declared[] = $from . '>' . $transition['to'];
			}
		}

		$expected = [];
		foreach ($statuses as $from) {
			foreach ($statuses as $to) {
				if ($from !== $to) {
					$expected[] = $from . '>' . $to;
				}
			}
		}

		sort($declared);
		sort($expected);
		$this->assertSame($expected, $declared);
	}//end testEveryStatusChangeStaysAllowed()

	/**
	 * The client's term counts two years from the relationship end, then destroys,
	 * through the reviewed `archive` path and not the annotation sweep.
	 *
	 * @return void
	 */
	public function testTheClientTermCountsFromTheRelationshipEnd(): void {
		$client = $this->schemas['client'];

		$this->assertSame(
			[
				'enabled' => true,
				'afleidingswijze' => 'ander_datumkenmerk',
				'sourceDateProperty' => 'relationshipEndedAt',
				'defaultBewaartermijn' => 'P2Y',
				'defaultNominatie' => 'vernietigen',
			],
			$client['archive']
		);
		$this->assertArrayHasKey($client['archive']['sourceDateProperty'], $client['properties']);
		$this->assertArrayNotHasKey('x-openregister-archival', $client);
	}//end testTheClientTermCountsFromTheRelationshipEnd()

	/**
	 * A contact person takes its date from the client it points at.
	 *
	 * @return void
	 */
	public function testAContactPersonFollowsItsClient(): void {
		$contact = $this->schemas['contact'];
		$archive = $contact['archive'];

		$this->assertTrue($archive['enabled']);
		$this->assertSame('gerelateerde_zaak', $archive['afleidingswijze']);
		$this->assertSame('client', $archive['sourceRelation']);
		$this->assertSame('client', $contact['properties']['client']['$ref']);
		$this->assertArrayHasKey($archive['sourceRelationProperty'], $this->schemas['client']['properties']);
		$this->assertSame('relationshipEndedAt', $archive['sourceRelationProperty']);
		$this->assertSame('P2Y', $archive['defaultBewaartermijn']);
		$this->assertSame('vernietigen', $archive['defaultNominatie']);
		$this->assertArrayNotHasKey('x-openregister-archival', $contact);
	}//end testAContactPersonFollowsItsClient()
}//end class
