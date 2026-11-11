<?php

/**
 * KeywordTargetService::confirm: every way a confirmation is stored or refused.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Search
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

namespace OCA\Pipelinq\Tests\Unit\Service\Search;

use OCA\Pipelinq\Service\Search\KeywordTargetService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Only a person's confirmation with known vocabulary becomes a target.
 */
class KeywordTargetConfirmTest extends TestCase {

	/**
	 * Every confirmation: the term is required; status and kind default to
	 * watch and manual and must be known, trimmed; a known intent is kept;
	 * the optional fields are kept trimmed and left out when blank; the
	 * stamp and the confirming user are set; nothing is written on a refusal.
	 *
	 * @return void
	 */
	public function testEveryConfirmation(): void {
		$store = new InMemoryListObjectStore();
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1767225600);
		$service = new KeywordTargetService($store, $time);

		$saved = $service->confirm(payload: ['term' => ' kassasysteem ', 'intent' => ' ', 'targetPageRef' => ' /kassa ', 'property' => '', 'notes' => ' n '], uid: 'u-1');
		unset($saved['uuid']);
		$this->assertSame(
			['term' => 'kassasysteem', 'status' => 'watch', 'proposalKind' => 'manual', 'createdBy' => 'u-1', 'createdAt' => '2026-01-01T00:00:00Z', 'targetPageRef' => '/kassa', 'notes' => 'n'],
			$saved
		);

		$saved = $service->confirm(payload: ['term' => 'pos', 'status' => ' use-more ', 'proposalKind' => 'content-gap', 'intent' => KeywordTargetService::INTENTS[0]], uid: 'u-2');
		$this->assertSame(['use-more', 'content-gap', KeywordTargetService::INTENTS[0]], [$saved['status'], $saved['proposalKind'], $saved['intent']]);

		$refusals = [
			[['term' => ' '], 'A keyword target needs a term.'],
			[['term' => 'x', 'status' => 'loud'], 'The status must be one of use-more, use-less, watch.'],
			[['term' => 'x', 'proposalKind' => 'guess'], 'The proposalKind must be one of striking-distance, cannibalisation, content-gap, manual.'],
			[['term' => 'x', 'intent' => 'shopping'], 'The intent must be one of ' . implode(', ', KeywordTargetService::INTENTS) . '.'],
		];
		$writes = $store->writes;
		foreach ($refusals as [$payload, $message]) {
			try {
				$service->confirm(payload: $payload, uid: 'u-1');
				$this->fail($message);
			} catch (UnexpectedValueException $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}

		$this->assertSame($writes, $store->writes);
	}//end testEveryConfirmation()
}//end class
