<?php

/**
 * Unit tests for CampaignLinkDecorator.
 *
 * Covers:
 * - every missing utm_* parameter is appended, to bare and to queried links
 * - a parameter the author wrote is kept as written
 * - the unsubscribe merge tag, anchors, mailto:, tel: and other schemes are untouched
 * - the campaign slug derives from the blast name, template name, then id
 * - a campaign's own source, medium and campaign value win, per parameter
 * - a blast that belongs to no campaign is decorated exactly as before
 * - the per-tenant switch turns decoration off
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
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-blast-links-carry-campaign-parameters
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\CampaignLinkDecorator;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CampaignLinkDecorator.
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-blast-links-carry-campaign-parameters
 */
class CampaignLinkDecoratorTest extends TestCase {

	/**
	 * A blast row as BlastService loads it.
	 *
	 * @var array<string, mixed>
	 */
	private const BLAST = ['uuid' => 'blast-42', 'name' => 'Spring newsletter 2026', 'templateId' => 'tpl-1'];

	/**
	 * A campaign row as CampaignService loads it.
	 *
	 * @var array<string, mixed>
	 */
	private const CAMPAIGN = [
		'uuid' => 'camp-1',
		'name' => 'Webinar AI voor gemeenten',
		'utmCampaign' => 'webinar-ai-voor-gemeenten',
		'utmSource' => 'nieuwsbrief',
		'utmMedium' => 'social',
	];

	/**
	 * Build a decorator with the switch at a given value.
	 *
	 * @param string $auto The stored `blast.utm_auto` value ('' = default).
	 *
	 * @return CampaignLinkDecorator
	 */
	private function build(string $auto = ''): CampaignLinkDecorator {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($auto): string {
				if ($key === CampaignLinkDecorator::AUTO_CONFIG_KEY && $auto !== '') {
					return $auto;
				}

				return $default;
			}
		);

		return new CampaignLinkDecorator($appConfig);
	}//end build()

	/**
	 * @return void
	 */
	public function testAddsAllFourParametersToABareLink(): void {
		$html = '<p><a href="https://example.org/woo">Lees meer</a></p>';
		$out = $this->build()->decorate(html: $html, blast: self::BLAST);

		$this->assertSame(
			'<p><a href="https://example.org/woo?utm_source=email&amp;utm_medium=email&amp;utm_campaign=spring-newsletter-2026&amp;utm_content=blast-42">Lees meer</a></p>',
			$out
		);
	}//end testAddsAllFourParametersToABareLink()

	/**
	 * @return void
	 */
	public function testAppendsToAnExistingQueryString(): void {
		$html = '<a href="https://example.org/?page=2#top">x</a>';
		$out = $this->build()->decorate(html: $html, blast: self::BLAST);

		$this->assertSame(
			'<a href="https://example.org/?page=2&amp;utm_source=email&amp;utm_medium=email&amp;utm_campaign=spring-newsletter-2026&amp;utm_content=blast-42#top">x</a>',
			$out
		);
	}//end testAppendsToAnExistingQueryString()

	/**
	 * @return void
	 */
	public function testKeepsParametersTheAuthorWrote(): void {
		$html = '<a href="https://example.org/?utm_campaign=spring&amp;utm_source=partner">x</a>';
		$out = $this->build()->decorate(html: $html, blast: self::BLAST);

		$this->assertStringContainsString('utm_campaign=spring', $out);
		$this->assertStringContainsString('utm_source=partner', $out);
		$this->assertStringNotContainsString('utm_campaign=spring-newsletter', $out);
		$this->assertStringNotContainsString('utm_source=email', $out);
		$this->assertStringContainsString('utm_medium=email', $out);
		$this->assertStringContainsString('utm_content=blast-42', $out);
	}//end testKeepsParametersTheAuthorWrote()

	/**
	 * @return void
	 */
	public function testLeavesUnsubscribeMergeTagUntouched(): void {
		$html = '<a href="{{unsubscribe_link}}">Unsubscribe</a><a href="https://example.org/{{token}}">y</a>';
		$out = $this->build()->decorate(html: $html, blast: self::BLAST);

		$this->assertSame($html, $out);
	}//end testLeavesUnsubscribeMergeTagUntouched()

	/**
	 * @return void
	 */
	public function testSkipsMailtoTelAndAnchors(): void {
		$html = '<a href="mailto:info@example.org">m</a><a href="tel:+31000">t</a><a href="#top">a</a><a href="">e</a><a href="ftp://x/y">f</a>';
		$out = $this->build()->decorate(html: $html, blast: self::BLAST);

		$this->assertSame($html, $out);
	}//end testSkipsMailtoTelAndAnchors()

	/**
	 * @return void
	 */
	public function testDecoratesRelativeAndProtocolRelativeLinks(): void {
		$html = '<a href="/woo">r</a><a href=\'//example.org/x\'>p</a>';
		$out = $this->build()->decorate(html: $html, blast: self::BLAST);

		$this->assertStringContainsString('href="/woo?utm_source=email', $out);
		$this->assertStringContainsString("href='//example.org/x?utm_source=email", $out);
	}//end testDecoratesRelativeAndProtocolRelativeLinks()

	/**
	 * @return void
	 */
	public function testDoesNothingWhenTheSettingIsOff(): void {
		$html = '<a href="https://example.org/">x</a>';

		$this->assertSame($html, $this->build('false')->decorate(html: $html, blast: self::BLAST));
		$this->assertSame($html, $this->build('0')->decorate(html: $html, blast: self::BLAST));
		$this->assertFalse($this->build('false')->isEnabled());
		$this->assertTrue($this->build()->isEnabled());
	}//end testDoesNothingWhenTheSettingIsOff()

	/**
	 * @return void
	 */
	public function testCampaignSlugFallsBackToTemplateNameThenBlastId(): void {
		$decorator = $this->build();

		$this->assertSame('spring-newsletter-2026', $decorator->campaignFor(blast: self::BLAST));
		$this->assertSame('herfst-actie', $decorator->campaignFor(blast: ['uuid' => 'b-1'], template: ['name' => 'Herfst actie!']));
		$this->assertSame('b-1', $decorator->campaignFor(blast: ['uuid' => 'b-1']));
		$this->assertSame('b-2', $decorator->campaignFor(blast: ['@self' => ['id' => 'b-2']]));
		$this->assertSame('', $decorator->campaignFor(blast: []));
	}//end testCampaignSlugFallsBackToTemplateNameThenBlastId()

	/**
	 * @return void
	 */
	public function testSlugifyIsAsciiLowerCaseAndBounded(): void {
		$this->assertSame('cafe-ete-2026', CampaignLinkDecorator::slugify('Café été 2026'));
		$this->assertSame('a-b', CampaignLinkDecorator::slugify('  a__b  '));
		$this->assertSame(80, strlen(CampaignLinkDecorator::slugify(str_repeat('x', 200))));
	}//end testSlugifyIsAsciiLowerCaseAndBounded()

	/**
	 * @return void
	 */
	public function testUtmMapCarriesTheBlastIdAsContent(): void {
		$utm = $this->build()->utmFor(blast: self::BLAST);

		$this->assertSame(
			['utm_source' => 'email', 'utm_medium' => 'email', 'utm_campaign' => 'spring-newsletter-2026', 'utm_content' => 'blast-42'],
			$utm
		);
	}//end testUtmMapCarriesTheBlastIdAsContent()

	/**
	 * @return void
	 */
	public function testEmptyBodyIsReturnedAsIs(): void {
		$this->assertSame('', $this->build()->decorate(html: '', blast: self::BLAST));
	}//end testEmptyBodyIsReturnedAsIs()

	/**
	 * @return void
	 */
	public function testCampaignSourceAndMediumWinOverTheEmailDefaults(): void {
		$utm = $this->build()->utmFor(blast: self::BLAST, campaign: self::CAMPAIGN);

		$this->assertSame(
			[
				'utm_source' => 'nieuwsbrief',
				'utm_medium' => 'social',
				'utm_campaign' => 'webinar-ai-voor-gemeenten',
				'utm_content' => 'blast-42',
			],
			$utm
		);
	}//end testCampaignSourceAndMediumWinOverTheEmailDefaults()

	/**
	 * @return void
	 */
	public function testCampaignSlugWinsOverTheBlastName(): void {
		$decorator = $this->build();

		$this->assertSame(
			'webinar-ai-voor-gemeenten',
			$decorator->campaignFor(blast: self::BLAST, campaign: self::CAMPAIGN)
		);

		$html = '<p><a href="https://example.org/webinar">Aanmelden</a></p>';
		$this->assertStringContainsString(
			'utm_campaign=webinar-ai-voor-gemeenten',
			$decorator->decorate(html: $html, blast: self::BLAST, campaign: self::CAMPAIGN)
		);
	}//end testCampaignSlugWinsOverTheBlastName()

	/**
	 * A campaign that names only a source must not empty the other three.
	 * Resolution is per parameter for exactly this case: an empty value is
	 * dropped by the decorator, so a set-wide swap would silently remove
	 * the campaign value the blast already had.
	 *
	 * @return void
	 */
	public function testAPartialCampaignFallsBackPerParameter(): void {
		$utm = $this->build()->utmFor(blast: self::BLAST, campaign: ['utmSource' => 'linkedin']);

		$this->assertSame(
			[
				'utm_source' => 'linkedin',
				'utm_medium' => 'email',
				'utm_campaign' => 'spring-newsletter-2026',
				'utm_content' => 'blast-42',
			],
			$utm
		);
	}//end testAPartialCampaignFallsBackPerParameter()

	/**
	 * @return void
	 */
	public function testABlastWithoutACampaignIsUnchanged(): void {
		$decorator = $this->build();
		$html = '<p><a href="https://example.org/woo">Lees meer</a></p>';

		$this->assertSame(
			$decorator->decorate(html: $html, blast: self::BLAST),
			$decorator->decorate(html: $html, blast: self::BLAST, template: [], campaign: [])
		);
	}//end testABlastWithoutACampaignIsUnchanged()

	/**
	 * @return void
	 */
	public function testACampaignValueIsLowercasedBeforeItIsStamped(): void {
		$utm = $this->build()->utmFor(blast: self::BLAST, campaign: ['utmSource' => 'LinkedIn']);

		$this->assertSame('linkedin', $utm['utm_source']);
	}//end testACampaignValueIsLowercasedBeforeItIsStamped()
}//end class
