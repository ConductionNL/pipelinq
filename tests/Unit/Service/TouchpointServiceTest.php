<?php

/**
 * Unit tests for TouchpointService.
 *
 * Covers:
 * - a campaign's touchpoints come back oldest first
 * - rows sharing a moment keep the order they were written in
 * - the nonce lookup is what the submission listener guards on
 * - an unknown kind is refused, so the log cannot grow a value the
 *   attribution models do not know
 * - a medium maps to the channel the report groups by
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-is-computed-at-report-time-in-three-models
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\TouchpointService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/InMemoryListObjectStore.php';

/**
 * Tests for TouchpointService.
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-is-computed-at-report-time-in-three-models
 */
class TouchpointServiceTest extends TestCase {

	/**
	 * The store every read comes from.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $store;

	/**
	 * Build a service over an in-memory store.
	 *
	 * @param array<int, array<string, mixed>> $touchpoints Seeded rows.
	 *
	 * @return TouchpointService
	 */
	private function build(array $touchpoints = []): TouchpointService {
		$this->store = new InMemoryListObjectStore(['touchpoint' => $touchpoints]);

		return new TouchpointService($this->store);
	}//end build()

	/**
	 * @return void
	 */
	public function testCampaignTouchpointsComeBackOldestFirst(): void {
		$service = $this->build([
			['uuid' => 'tp-late', 'campaignId' => 'camp-1', 'kind' => 'submit', 'occurredAt' => '2026-10-21T09:47:12+00:00'],
			['uuid' => 'tp-early', 'campaignId' => 'camp-1', 'kind' => 'click', 'occurredAt' => '2026-10-14T10:21:33+00:00'],
			['uuid' => 'tp-other', 'campaignId' => 'camp-2', 'kind' => 'click', 'occurredAt' => '2026-10-01T00:00:00+00:00'],
		]);

		$this->assertSame(
			['tp-early', 'tp-late'],
			array_column($service->forCampaign(campaignId: 'camp-1'), 'uuid')
		);
	}//end testCampaignTouchpointsComeBackOldestFirst()

	/**
	 * A whole-second timestamp cannot order two events inside the same
	 * second. Falling back to write order is what keeps first touch and
	 * last touch answering the same way on every run.
	 *
	 * @return void
	 */
	public function testRowsSharingAMomentKeepTheirWriteOrder(): void {
		$service = $this->build([
			['uuid' => 'tp-a', 'campaignId' => 'camp-1', 'kind' => 'click', 'occurredAt' => '2026-10-14T10:21:33+00:00'],
			['uuid' => 'tp-b', 'campaignId' => 'camp-1', 'kind' => 'visit', 'occurredAt' => '2026-10-14T10:21:33+00:00'],
		]);

		$ordered = array_column($service->forCampaign(campaignId: 'camp-1'), 'uuid');

		$this->assertSame(['tp-a', 'tp-b'], $ordered);
		$this->assertSame($ordered, array_column($service->forCampaign(campaignId: 'camp-1'), 'uuid'));
	}//end testRowsSharingAMomentKeepTheirWriteOrder()

	/**
	 * @return void
	 */
	public function testTheNonceLookupFindsAnAlreadyRecordedInteraction(): void {
		$service = $this->build([
			['uuid' => 'tp-1', 'campaignId' => 'camp-1', 'kind' => 'submit', 'occurredAt' => '2026-10-21T09:47:12+00:00', 'nonce' => 'n-1'],
		]);

		$this->assertTrue($service->existsForNonce(nonce: 'n-1'));
		$this->assertFalse($service->existsForNonce(nonce: 'n-2'));
		$this->assertFalse($service->existsForNonce(nonce: '  '));
	}//end testTheNonceLookupFindsAnAlreadyRecordedInteraction()

	/**
	 * @return void
	 */
	public function testAnUnknownKindIsRefused(): void {
		$service = $this->build();

		$this->assertNull($service->append(touchpoint: ['campaignId' => 'camp-1', 'kind' => 'bounce']));
		$this->assertSame(0, $this->store->countOf(schemaSlug: 'touchpoint'));
	}//end testAnUnknownKindIsRefused()

	/**
	 * @return void
	 */
	public function testAppendStampsAMomentWhenTheCallerHasNone(): void {
		$service = $this->build();

		$saved = $service->append(touchpoint: ['campaignId' => 'camp-1', 'kind' => 'visit']);

		$this->assertNotSame('', (string)$saved['occurredAt']);
		$this->assertNotSame('', (string)$saved['createdAt']);
	}//end testAppendStampsAMomentWhenTheCallerHasNone()

	/**
	 * @return void
	 */
	public function testAMediumMapsToTheChannelTheReportGroupsBy(): void {
		$service = $this->build();

		$this->assertSame('paid', $service->channelForMedium(medium: 'cpc'));
		$this->assertSame('paid', $service->channelForMedium(medium: 'Display'));
		$this->assertSame('social', $service->channelForMedium(medium: 'social'));
		$this->assertSame('direct', $service->channelForMedium(medium: ''));
		$this->assertSame('beurs', $service->channelForMedium(medium: 'beurs'));
	}//end testAMediumMapsToTheChannelTheReportGroupsBy()
}//end class
