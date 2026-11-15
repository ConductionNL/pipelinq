<?php

/**
 * Pins every row a landing page submission writes, across the submission shapes.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\Pipelinq\Event\LandingPageFormSubmittedEvent;
use OCA\Pipelinq\Listener\LandingPageFormSubmittedListener;
use OCA\Pipelinq\Service\CampaignLinkDecorator;
use OCA\Pipelinq\Service\CampaignService;
use OCA\Pipelinq\Service\TouchpointService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../Support/InMemoryListObjectStore.php';

/**
 * The whole store, the write counts and the event state after each scenario.
 */
class LandingPageSubmissionGoldenTest extends TestCase {

	/**
	 * One submission.
	 *
	 * @param array<string, mixed> $over Fields to override.
	 *
	 * @return LandingPageFormSubmittedEvent
	 */
	private static function submission(array $over = []): LandingPageFormSubmittedEvent {
		$f = array_merge(
			[
				'form'      => 'form-1',
				'route'     => '/campagne/webinar',
				'reference' => 'pipelinq:campaign:camp-1',
				'values'    => ['name' => 'J. de Vries', 'email' => 'JANE.DOE@EXAMPLE.COM', 'consent' => true, 'tags' => ['a']],
				'referrer'  => 'https://www.linkedin.com/',
				'nonce'     => 'nonce-1',
			],
			$over
		);
		return new LandingPageFormSubmittedEvent(
			'pipelinq',
			$f['form'],
			'page-1',
			$f['route'],
			'open-tilburg',
			$f['reference'],
			$f['values'],
			['campaign' => 'webinar', 'source' => 'nieuwsbrief', 'medium' => 'email', 'extra' => 'x'],
			['source' => ' linkedin ', 'medium' => 'social'],
			$f['referrer'],
			'2026-10-21T09:47:12+00:00',
			$f['nonce']
		);
	}//end submission()

	/**
	 * The scenarios: seed rows and the submissions handled in order.
	 *
	 * @return array<string, array{array<string, mixed>, array<int, array<string, mixed>>}>
	 */
	public static function scenarios(): array {
		return [
			'new contact'           => [[], [[]]],
			'redelivered'           => [[], [[], []]],
			'blank nonce twice'     => [[], [['nonce' => '  '], ['nonce' => '']]],
			'existing contact'      => [['contact' => [['uuid' => 'ct-9', 'email' => 'jane.doe@example.com']]], [[]]],
			'contact as submitted'  => [['contact' => [['uuid' => 'ct-8', 'email' => 'JANE.DOE@EXAMPLE.COM']]], [[]]],
			'organisation twice'    => [
				[],
				[
					['values' => ['email' => 'a@b.nl', 'organisation' => ' Gemeente X '], 'nonce' => 'n-1'],
					['values' => ['email' => 'c@d.nl', 'organisation' => 'Gemeente X'], 'nonce' => 'n-2'],
				],
			],
			'existing client'       => [['client' => [['uuid' => 'cl-1', 'name' => 'Gemeente X']]], [['values' => ['email' => 'a@b.nl', 'organisation' => 'Gemeente X']]]],
			'no email'              => [[], [['values' => ['name' => 'Anon']]]],
			'by form id'            => [[], [['reference' => '']]],
			'empty reference id'    => [[], [['reference' => 'pipelinq:campaign:  ']]],
			'unknown form'          => [[], [['reference' => '', 'form' => 'form-x']]],
			'no form'               => [[], [['reference' => '', 'form' => ' ']]],
			'missing campaign'      => [[], [['reference' => 'pipelinq:campaign:gone']]],
			'no campaign no route'  => [[], [['reference' => 'pipelinq:campaign:gone', 'route' => ' ', 'referrer' => ' ']]],
			'no name'               => [[], [['values' => ['email' => 'x@y.nl']]]],
		];
	}//end scenarios()

	/**
	 * Runs one scenario and returns the observable state.
	 *
	 * @param array<string, mixed>             $seed  Seed rows per schema.
	 * @param array<int, array<string, mixed>> $steps Submissions, in order.
	 *
	 * @return array<string, mixed>
	 */
	public function play(array $seed, array $steps): array {
		$campaign = ['uuid' => 'camp-1', 'name' => ' Webinar AI ', 'utmCampaign' => 'webinar', 'formRef' => 'form-1'];
		$store = new InMemoryListObjectStore(array_merge(['campaign' => [$campaign]], $seed));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnArgument(2);
		$listener = new LandingPageFormSubmittedListener(
			$store,
			new CampaignService($store, $appConfig, new CampaignLinkDecorator($appConfig)),
			new TouchpointService($store),
			$this->createMock(LoggerInterface::class)
		);

		$events = [];
		foreach ($steps as $step) {
			$event = self::submission(over: $step);
			$listener->handle($event);
			$events[] = ['handled' => $event->isHandled(), 'lead' => $event->getLeadId()];
		}

		$rows = $store->rows;
		foreach (($rows['touchpoint'] ?? []) as $id => $touchpoint) {
			if (isset($touchpoint['createdAt']) === true) {
				// The service stamps its own clock; only its presence and shape are pinned.
				$stamped = \DateTimeImmutable::createFromFormat(DATE_ATOM, (string)$touchpoint['createdAt']);
				$rows['touchpoint'][$id]['createdAt'] = ($stamped !== false) ? '<now>' : '<invalid>';
			}
		}

		return ['rows' => $rows, 'writes' => $store->writes, 'events' => $events];
	}//end play()

	/**
	 * Each scenario leaves exactly the pinned state.
	 *
	 * @param array<string, mixed>             $seed  Seed rows per schema.
	 * @param array<int, array<string, mixed>> $steps Submissions, in order.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testScenario(array $seed, array $steps): void {
		$golden = json_decode((string)file_get_contents(__DIR__.'/Fixtures/landing-page-submissions.json'), true);
		$name = $this->dataName();
		$this->assertSame($golden[$name], json_decode((string)json_encode($this->play(seed: $seed, steps: $steps)), true));
	}//end testScenario()
}//end class
