<?php

/**
 * Unit tests for SubmissionTouchRecorder.
 *
 * Covers:
 * - a known contact's submit becomes a touchpoint naming the contact, its
 *   client and the page, and updates both their last touch
 * - a campaign value names the campaign whose campaign value it is
 * - campaign values are trimmed and capped at 100 characters; a non-text
 *   value is dropped
 * - an unknown address records the touchpoint alone and creates no contact
 * - a repeated Idempotency-Key records nothing the second time
 * - a failing store is logged and never thrown
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\WebForm
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-campaign-on-client/specs/marketing-origin-on-client/spec.md#requirement-the-website-enquiry-form-keeps-campaign-values-req-moc-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\WebForm;

use OCA\Pipelinq\Service\CampaignLinkDecorator;
use OCA\Pipelinq\Service\CampaignService;
use OCA\Pipelinq\Service\TouchpointService;
use OCA\Pipelinq\Service\WebForm\SubmissionTouchRecorder;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/../../Support/InMemoryListObjectStore.php';

/**
 * Tests for SubmissionTouchRecorder.
 *
 * @covers \OCA\Pipelinq\Service\WebForm\SubmissionTouchRecorder
 * @uses   \OCA\Pipelinq\Service\TouchpointService
 * @uses   \OCA\Pipelinq\Service\CampaignService
 * @uses   \OCA\Pipelinq\Service\CampaignLinkDecorator
 * @uses   \OCA\Pipelinq\Service\Marketing\ListObjectStore
 */
class SubmissionTouchRecorderTest extends TestCase {

	/**
	 * The store every read and write goes to.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $store;

	/**
	 * The logger the recorder reports a failure to.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build a recorder over a store seeded with a contact, its client and a campaign.
	 *
	 * @param InMemoryListObjectStore|null $store A store to use instead of the seeded one.
	 *
	 * @return SubmissionTouchRecorder
	 */
	private function recorder(?InMemoryListObjectStore $store = null): SubmissionTouchRecorder {
		$this->store = ($store ?? new InMemoryListObjectStore(self::seed()));
		$this->logger = $this->createMock(LoggerInterface::class);
		$config = $this->createMock(IAppConfig::class);

		return new SubmissionTouchRecorder(
			new TouchpointService($this->store),
			new CampaignService($this->store, $config, new CampaignLinkDecorator($config)),
			$this->store,
			$this->logger
		);
	}//end recorder()

	/**
	 * A contact at a client, the client, and the campaign whose campaign value is voorjaar.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function seed(): array {
		return [
			'contact' => [['uuid' => 'contact-1', 'name' => 'Jan', 'email' => 'jan@zeist.nl', 'client' => 'client-1']],
			'client' => [['uuid' => 'client-1', 'name' => 'Gemeente Zeist']],
			'campaign' => [['uuid' => 'campaign-1', 'name' => 'Voorjaar', 'utmCampaign' => 'voorjaar']],
		];
	}//end seed()

	/**
	 * A known contact's enquiry from a campaign link sets their last touch and the client's.
	 *
	 * @return void
	 */
	public function testAKnownContactsSubmitUpdatesTheContactAndClient(): void {
		$saved = $this->recorder()->record(
			form: ['id' => 'form-enquiry'],
			payload: [
				'contactEmail' => 'jan@zeist.nl',
				'utmCampaign' => 'voorjaar',
				'utmSource' => 'nieuwsbrief',
				'utmMedium' => 'email',
				'pageUrl' => 'https://conduction.nl/contact',
				'status' => 'closed',
			]
		);

		$this->assertNotNull($saved);
		$this->assertSame('submit', $saved['kind']);
		$this->assertSame('contact-1', $saved['contactId']);
		$this->assertSame('client-1', $saved['clientId']);
		$this->assertSame('campaign-1', $saved['campaignId']);
		$this->assertSame('email', $saved['channel']);
		$this->assertSame('form-enquiry', $saved['sourceRef']);
		$this->assertSame('https://conduction.nl/contact', $saved['landingPage']);
		$this->assertSame(['campaign' => 'voorjaar', 'source' => 'nieuwsbrief', 'medium' => 'email'], $saved['utm']);
		$this->assertArrayNotHasKey('status', $saved);

		$contact = $this->store->find('contact', 'contact-1');
		$this->assertSame('voorjaar', $contact['lastTouch']['campaign']);
		$this->assertSame('nieuwsbrief', $contact['lastTouch']['source']);
		$this->assertSame('https://conduction.nl/contact', $contact['lastTouch']['landingPage']);
		$this->assertSame('voorjaar', $contact['firstTouch']['campaign']);
		$this->assertSame('voorjaar', $this->store->find('client', 'client-1')['lastTouch']['campaign']);
	}//end testAKnownContactsSubmitUpdatesTheContactAndClient()

	/**
	 * A later submit from another campaign changes only the last touch.
	 *
	 * @return void
	 */
	public function testALaterSubmitChangesOnlyTheLastTouch(): void {
		$recorder = $this->recorder();
		$recorder->record(form: ['id' => 'f'], payload: ['email' => 'jan@zeist.nl', 'utm_campaign' => 'voorjaar']);
		$recorder->record(form: ['id' => 'f'], payload: ['email' => 'jan@zeist.nl', 'utm_campaign' => 'najaar']);

		$contact = $this->store->find('contact', 'contact-1');
		$this->assertSame('voorjaar', $contact['firstTouch']['campaign']);
		$this->assertSame('najaar', $contact['lastTouch']['campaign']);
		$this->assertSame('', $contact['lastTouch']['campaignId']);
		$this->assertSame(2, $this->store->countOf('touchpoint'));
	}//end testALaterSubmitChangesOnlyTheLastTouch()

	/**
	 * The address is matched lowercased, as the landing page intake matches it.
	 *
	 * @return void
	 */
	public function testTheAddressIsMatchedLowercased(): void {
		$saved = $this->recorder()->record(form: ['id' => 'f'], payload: ['contactEmail' => '  Jan@Zeist.NL ']);

		$this->assertSame('contact-1', $saved['contactId']);
		$this->assertSame('direct', $saved['channel']);
	}//end testTheAddressIsMatchedLowercased()

	/**
	 * Campaign values are trimmed and capped at 100 characters; camelCase wins; a list is dropped.
	 *
	 * @return void
	 */
	public function testCampaignValuesAreTrimmedCappedAndTextOnly(): void {
		$utm = $this->recorder()->utmOf(
			payload: [
				'utmCampaign' => '  ' . str_repeat('a', 500) . '  ',
				'utm_source' => 'linkedin',
				'utmMedium' => 'social',
				'utm_medium' => 'cpc',
				'utmContent' => ['x', 'y'],
				'utmTerm' => '   ',
			]
		);

		$this->assertSame(['campaign', 'source', 'medium'], array_keys($utm));
		$this->assertSame(SubmissionTouchRecorder::MAX_UTM_LENGTH, mb_strlen($utm['campaign']));
		$this->assertSame(str_repeat('a', 100), $utm['campaign']);
		$this->assertSame('linkedin', $utm['source']);
		$this->assertSame('social', $utm['medium']);
	}//end testCampaignValuesAreTrimmedCappedAndTextOnly()

	/**
	 * An unknown address keeps the values on the touchpoint alone and creates no contact.
	 *
	 * @return void
	 */
	public function testAnUnknownAddressRecordsTheTouchpointOnly(): void {
		$saved = $this->recorder()->record(
			form: ['id' => 'f'],
			payload: ['contactEmail' => 'new@example.com', 'utmCampaign' => 'onbekend', 'pageUrl' => str_repeat('p', 3000)]
		);

		$this->assertArrayNotHasKey('contactId', $saved);
		$this->assertArrayNotHasKey('campaignId', $saved);
		$this->assertSame('', $saved['clientId']);
		$this->assertSame(SubmissionTouchRecorder::MAX_PAGE_LENGTH, mb_strlen($saved['landingPage']));
		$this->assertSame(1, $this->store->countOf('contact'));
		$this->assertArrayNotHasKey('lastTouch', $this->store->find('contact', 'contact-1'));
	}//end testAnUnknownAddressRecordsTheTouchpointOnly()

	/**
	 * A repeated submit with the same Idempotency-Key records one touchpoint.
	 *
	 * @return void
	 */
	public function testARepeatedIdempotencyKeyRecordsOnce(): void {
		$recorder = $this->recorder();
		$first = $recorder->record(form: ['id' => 'f'], payload: ['email' => 'jan@zeist.nl'], idempotencyKey: 'k-1');
		$second = $recorder->record(form: ['id' => 'f'], payload: ['email' => 'jan@zeist.nl'], idempotencyKey: 'k-1');

		$this->assertSame('web-form:f:k-1', $first['nonce']);
		$this->assertNull($second);
		$this->assertSame(1, $this->store->countOf('touchpoint'));
	}//end testARepeatedIdempotencyKeyRecordsOnce()

	/**
	 * A store failure is logged, without the visitor's data, and never reaches the caller.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedNotThrown(): void {
		$failing = new class(self::seed()) extends InMemoryListObjectStore {
			/**
			 * Every write fails.
			 *
			 * @param string               $schemaSlug The schema.
			 * @param array<string, mixed> $payload    The row.
			 * @param string|null          $id         The id.
			 *
			 * @return array<string, mixed>|null
			 */
			public function save(string $schemaSlug, array $payload, ?string $id = null): ?array {
				throw new RuntimeException('store down');
			}//end save()
		};
		$recorder = $this->recorder(store: $failing);
		$this->logger->expects($this->once())->method('warning')->with(
			'Pipelinq: website form submit stored, its campaign touch was not recorded.',
			['form' => 'f', 'reason' => 'store down']
		);

		$this->assertNull($recorder->record(form: ['id' => 'f'], payload: ['email' => 'jan@zeist.nl']));
	}//end testAFailureIsLoggedNotThrown()
}//end class
