<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Search
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Search;

use OCA\Pipelinq\Service\Search\KeywordTargetService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Reading keyword targets: the list and the confirmed terms a page marks as taken.
 *
 * @covers \OCA\Pipelinq\Service\Search\KeywordTargetService
 */
class KeywordTargetServiceReadTest extends TestCase {

	/**
	 * The service over a store seeded with targets.
	 *
	 * @param array<int, array<string, mixed>> $targets The stored targets.
	 *
	 * @return KeywordTargetService
	 */
	private function service(array $targets): KeywordTargetService {
		$store = new InMemoryListObjectStore([KeywordTargetService::SCHEMA_SLUG => $targets]);

		return new KeywordTargetService($store, $this->createMock(ITimeFactory::class));
	}//end service()

	/**
	 * The schema slug is the declared default when no override is configured.
	 *
	 * @return void
	 */
	public function testSchemaSlugIsTheDeclaredSlug(): void {
		$this->assertSame('keywordTarget', $this->service(targets: [])->schemaSlug());
	}//end testSchemaSlugIsTheDeclaredSlug()

	/**
	 * all() returns every stored target of the keyword target schema.
	 *
	 * @return void
	 */
	public function testAllReturnsEveryStoredTarget(): void {
		$all = $this->service(targets: [['uuid' => 't-1', 'term' => 'kassa'], ['uuid' => 't-2', 'term' => 'pos']])->all();

		$this->assertSame(['t-1', 't-2'], array_column($all, 'uuid'));
		$this->assertSame(['kassa', 'pos'], array_column($all, 'term'));
	}//end testAllReturnsEveryStoredTarget()

	/**
	 * Confirmed terms are trimmed, lowercased (multibyte too), deduplicated in
	 * first-seen order, and blank or missing terms are left out.
	 *
	 * @return void
	 */
	public function testConfirmedTermsAreNormalisedAndDeduplicated(): void {
		$terms = $this->service(
			targets: [
				['uuid' => 't-1', 'term' => ' Kassasysteem '],
				['uuid' => 't-2', 'term' => 'ÉÉN kassa'],
				['uuid' => 't-3', 'term' => 'kassasysteem'],
				['uuid' => 't-4', 'term' => '   '],
				['uuid' => 't-5'],
				['uuid' => 't-6', 'term' => 'POS'],
			]
		)->confirmedTerms();

		$this->assertSame(['kassasysteem', 'één kassa', 'pos'], $terms);
	}//end testConfirmedTermsAreNormalisedAndDeduplicated()

	/**
	 * No targets means no confirmed terms.
	 *
	 * @return void
	 */
	public function testNoTargetsMeansNoConfirmedTerms(): void {
		$this->assertSame([], $this->service(targets: [])->confirmedTerms());
	}//end testNoTargetsMeansNoConfirmedTerms()
}//end class
