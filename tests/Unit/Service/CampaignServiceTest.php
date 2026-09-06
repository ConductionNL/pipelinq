<?php

/**
 * Unit tests for CampaignService.
 *
 * Covers:
 * - the campaign value is minted from the name on the first save
 * - a rename leaves the minted value alone
 * - a source or medium outside the vocabulary is refused, naming both
 * - the vocabulary comes from app config when an administrator set one
 * - a blast without a campaignId resolves to an empty campaign
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
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\CampaignLinkDecorator;
use OCA\Pipelinq\Service\CampaignService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/InMemoryListObjectStore.php';

/**
 * Tests for CampaignService.
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
 */
class CampaignServiceTest extends TestCase {

	/**
	 * The store the service under test writes to.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $store;

	/**
	 * Build a service over an in-memory store.
	 *
	 * @param array<string, string> $config App-config values by key.
	 * @param array<string, array<int, array<string, mixed>>> $seed Seeded rows.
	 *
	 * @return CampaignService
	 */
	private function build(array $config = [], array $seed = []): CampaignService {
		$this->store = new InMemoryListObjectStore($seed);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				return ($config[$key] ?? $default);
			}
		);

		return new CampaignService($this->store, $appConfig, new CampaignLinkDecorator($appConfig));
	}//end build()

	/**
	 * @return void
	 */
	public function testMintsTheSlugFromTheNameOnFirstSave(): void {
		$service = $this->build();

		$result = $service->save(payload: ['name' => 'Webinar AI voor gemeenten'], uid: 'admin');

		$this->assertSame('', $result['error']);
		$this->assertSame('webinar-ai-voor-gemeenten', $result['campaign']['utmCampaign']);
		$this->assertSame('admin', $result['campaign']['createdBy']);
	}//end testMintsTheSlugFromTheNameOnFirstSave()

	/**
	 * @return void
	 */
	public function testKeepsTheSlugWhenTheCampaignIsRenamed(): void {
		$service = $this->build();
		$created = $service->save(payload: ['name' => 'Webinar AI voor gemeenten'], uid: 'admin');
		$id = $this->store->idOf(payload: $created['campaign']);

		$renamed = $service->save(payload: ['name' => 'Webinar AI voor provincies'], id: $id, uid: 'admin');

		$this->assertSame('', $renamed['error']);
		$this->assertSame('Webinar AI voor provincies', $renamed['campaign']['name']);
		$this->assertSame('webinar-ai-voor-gemeenten', $renamed['campaign']['utmCampaign']);
	}//end testKeepsTheSlugWhenTheCampaignIsRenamed()

	/**
	 * @return void
	 */
	public function testRefusesASourceOutsideTheVocabulary(): void {
		$service = $this->build();

		$result = $service->save(payload: ['name' => 'Beursactie', 'utmSource' => 'Beurs'], uid: 'admin');

		$this->assertSame('unknown_utm_source', $result['error']);
		$this->assertSame('Beurs', $result['value']);
		$this->assertContains('beurs', $result['allowed']);
		$this->assertNull($result['campaign']);
		$this->assertSame(0, $this->store->countOf(schemaSlug: 'campaign'));
	}//end testRefusesASourceOutsideTheVocabulary()

	/**
	 * @return void
	 */
	public function testRefusesAMediumOutsideTheVocabulary(): void {
		$service = $this->build();

		$result = $service->save(payload: ['name' => 'Beursactie', 'utmMedium' => 'poster'], uid: 'admin');

		$this->assertSame('unknown_utm_medium', $result['error']);
		$this->assertSame('poster', $result['value']);
	}//end testRefusesAMediumOutsideTheVocabulary()

	/**
	 * @return void
	 */
	public function testAnAdministratorsVocabularyReplacesTheBuiltInOne(): void {
		$service = $this->build(config: ['campaign.utm_sources' => 'Beurs, Vakblad ,beurs']);

		$vocabularies = $service->vocabularies();

		$this->assertSame(['beurs', 'vakblad'], $vocabularies['sources']);
		$this->assertContains('email', $vocabularies['mediums']);

		$result = $service->save(payload: ['name' => 'Beursactie', 'utmSource' => 'beurs'], uid: 'admin');
		$this->assertSame('', $result['error']);
	}//end testAnAdministratorsVocabularyReplacesTheBuiltInOne()

	/**
	 * @return void
	 */
	public function testABlastWithoutACampaignResolvesToAnEmptyCampaign(): void {
		$service = $this->build();

		$this->assertSame([], $service->forBlast(blast: ['uuid' => 'blast-1']));
		$this->assertSame([], $service->forBlast(blast: ['uuid' => 'blast-1', 'campaignId' => 'nope']));
	}//end testABlastWithoutACampaignResolvesToAnEmptyCampaign()

	/**
	 * @return void
	 */
	public function testABlastWithACampaignResolvesToIt(): void {
		$service = $this->build(
			seed: ['campaign' => [['uuid' => 'camp-1', 'name' => 'Webinar', 'utmCampaign' => 'webinar']]]
		);

		$campaign = $service->forBlast(blast: ['uuid' => 'blast-1', 'campaignId' => 'camp-1']);

		$this->assertSame('webinar', $campaign['utmCampaign']);
	}//end testABlastWithACampaignResolvesToIt()

	/**
	 * @return void
	 */
	public function testAnEmptyNameIsRefused(): void {
		$service = $this->build();

		$this->assertSame('name_required', $service->save(payload: ['name' => '  '])['error']);
	}//end testAnEmptyNameIsRefused()

	/**
	 * @return void
	 */
	public function testRecordLandingPageStoresTheRouteAndFormId(): void {
		$service = $this->build(
			seed: ['campaign' => [['uuid' => 'camp-1', 'name' => 'Webinar', 'utmCampaign' => 'webinar']]]
		);

		$saved = $service->recordLandingPage(
			id: 'camp-1',
			landingPage: ['portal' => 'open-tilburg', 'route' => '/campagne/webinar', 'pageId' => 'page-1', 'publicUrl' => ''],
			formId: 'form-1'
		);

		$this->assertSame('/campagne/webinar', $saved['landingPage']['route']);
		$this->assertSame('form-1', $saved['formRef']);
	}//end testRecordLandingPageStoresTheRouteAndFormId()
}//end class
