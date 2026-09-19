<?php

/**
 * Unit tests for CorrespondenceLanguageService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\CorrespondenceLanguageService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the language a party asked to be written in.
 */
class CorrespondenceLanguageServiceTest extends TestCase {
	/**
	 * The languages the instance ships.
	 *
	 * @var array<int, string>
	 */
	private array $shipped = ['nl', 'en'];

	/**
	 * The instance default language.
	 *
	 * @var string
	 */
	private string $default = 'nl';

	/**
	 * The parties the double holds.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $parties = [];

	/**
	 * Every object written.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * Build the service over doubles.
	 *
	 * @return CorrespondenceLanguageService The service under test.
	 */
	private function service(): CorrespondenceLanguageService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				$values = ['register' => 'reg-1', 'client_schema' => 'sch-client'];

				return ($values[$key] ?? $default);
			}
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			function (string $key, mixed $default = '') {
				return ($key === 'default_language' ? $this->default : $default);
			}
		);

		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('findAvailableLanguages')->willReturnCallback(
			function (): array {
				return $this->shipped;
			}
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id): ?ObjectEntityInterface {
				$data = ($this->parties[(string)$id] ?? null);
				if ($data === null) {
					return null;
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($data);

				return $entity;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntityInterface {
				$this->written[] = $object;

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($object);

				return $entity;
			}
		);

		return new CorrespondenceLanguageService(
			$appConfig,
			$config,
			$l10nFactory,
			$objectService,
			$this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * The party's own preference answers, and says so.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/correspondence-language-per-party/specs/parties/spec.md#requirement-the-language-to-write-in-resolves-with-its-reason-req-pcl-003
	 */
	public function testThePartysOwnPreferenceAnswers(): void {
		$answer = $this->service()->resolveFor(party: ['correspondenceLanguage' => 'en']);

		$this->assertSame('en', $answer['language']);
		$this->assertSame(CorrespondenceLanguageService::RULE_PARTY, $answer['rule']);
	}//end testThePartysOwnPreferenceAnswers()

	/**
	 * The instance default answers, and names itself rather than the party.
	 *
	 * @return void
	 */
	public function testTheInstanceDefaultAnswersAndSaysSo(): void {
		$answer = $this->service()->resolveFor(party: ['name' => 'Jansen']);

		$this->assertSame('nl', $answer['language']);
		$this->assertSame(CorrespondenceLanguageService::RULE_INSTANCE_DEFAULT, $answer['rule']);
		$this->assertSame(
			'',
			$answer['partyPreference'],
			'An unset preference must not be dressed up as a chosen one.'
		);
	}//end testTheInstanceDefaultAnswersAndSaysSo()

	/**
	 * English is the floor, and says it is the floor.
	 *
	 * @return void
	 */
	public function testEnglishIsTheFloor(): void {
		$this->default = '';

		$answer = $this->service()->resolveFor(party: []);

		$this->assertSame('en', $answer['language']);
		$this->assertSame(CorrespondenceLanguageService::RULE_FALLBACK, $answer['rule']);
	}//end testEnglishIsTheFloor()

	/**
	 * Nothing is inferred from a name, an address or a nationality.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/correspondence-language-per-party/specs/parties/spec.md#requirement-a-party-carries-the-language-it-asked-to-be-written-in-req-pcl-001
	 */
	public function testNothingIsInferred(): void {
		$answer = $this->service()->resolveFor(
			party: [
				'name' => 'Jansen',
				'address' => 'Grote Markt 1, Groningen',
				'nationality' => 'NL',
			]
		);

		$this->assertSame(
			'',
			$answer['partyPreference'],
			'A Dutch name and a Dutch address are not a stated preference.'
		);
		$this->assertSame(CorrespondenceLanguageService::RULE_INSTANCE_DEFAULT, $answer['rule']);
	}//end testNothingIsInferred()

	/**
	 * The picker offers what the instance ships, and nothing else.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/correspondence-language-per-party/specs/parties/spec.md#requirement-the-selectable-languages-are-the-ones-the-instance-can-render-req-pcl-002
	 */
	public function testThePickerOffersOnlyWhatExists(): void {
		$this->assertSame(['en', 'nl'], $this->service()->available());
	}//end testThePickerOffersOnlyWhatExists()

	/**
	 * An unrenderable tag is refused, naming it and listing what exists.
	 *
	 * @return void
	 */
	public function testAnUnrenderableTagIsRefused(): void {
		$this->parties = ['p1' => ['id' => 'p1', 'name' => 'Jansen']];

		$result = $this->service()->setPreference(partyId: 'p1', tag: 'fy');

		$this->assertSame(422, $result['status']);
		$this->assertStringContainsString('fy', $result['error']);
		$this->assertStringContainsString('nl', $result['error']);
		$this->assertStringContainsString('en', $result['error']);
		$this->assertSame([], $this->written, 'A refused write writes nothing.');
	}//end testAnUnrenderableTagIsRefused()

	/**
	 * A renderable tag is accepted.
	 *
	 * The control: a validator that refused everything would pass the test
	 * above.
	 *
	 * @return void
	 */
	public function testARenderableTagIsAccepted(): void {
		$this->parties = ['p1' => ['id' => 'p1', 'name' => 'Jansen']];

		$result = $this->service()->setPreference(partyId: 'p1', tag: 'en');

		$this->assertSame(200, $result['status']);
		$this->assertSame('en', $result['language']);
		$this->assertSame(CorrespondenceLanguageService::RULE_PARTY, $result['rule']);
		$this->assertCount(1, $this->written);
		$this->assertSame('en', $this->written[0]['correspondenceLanguage']);
	}//end testARenderableTagIsAccepted()

	/**
	 * Clearing a preference stays possible.
	 *
	 * @return void
	 */
	public function testAPreferenceCanBeCleared(): void {
		$this->parties = ['p1' => ['id' => 'p1', 'correspondenceLanguage' => 'en']];

		$result = $this->service()->setPreference(partyId: 'p1', tag: '');

		$this->assertSame(200, $result['status']);
		$this->assertSame(CorrespondenceLanguageService::RULE_INSTANCE_DEFAULT, $result['rule']);
	}//end testAPreferenceCanBeCleared()

	/**
	 * Two stated preferences stop the merge and ask.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/correspondence-language-per-party/specs/parties/spec.md#requirement-a-merge-does-not-pick-a-language-silently-req-pcl-006
	 */
	public function testTwoPreferencesStopTheMerge(): void {
		$answer = $this->service()->mergeAnswer(
			winner: ['correspondenceLanguage' => 'nl'],
			loser: ['correspondenceLanguage' => 'en'],
		);

		$this->assertTrue($answer['needsChoice']);
		$this->assertSame(['nl', 'en'], $answer['options']);
		$this->assertSame('', $answer['language'], 'Nothing is picked before somebody chooses.');
	}//end testTwoPreferencesStopTheMerge()

	/**
	 * One stated preference survives, and the merge does not ask.
	 *
	 * @return void
	 */
	public function testOnePreferenceSurvives(): void {
		$service = $this->service();

		$fromLoser = $service->mergeAnswer(
			winner: ['name' => 'Jansen'],
			loser: ['correspondenceLanguage' => 'en'],
		);
		$this->assertFalse($fromLoser['needsChoice']);
		$this->assertSame('en', $fromLoser['language']);

		$fromWinner = $service->mergeAnswer(
			winner: ['correspondenceLanguage' => 'en'],
			loser: ['name' => 'Jansen'],
		);
		$this->assertFalse($fromWinner['needsChoice']);
		$this->assertSame('en', $fromWinner['language']);
	}//end testOnePreferenceSurvives()

	/**
	 * Two identical preferences are not a conflict.
	 *
	 * @return void
	 */
	public function testTwoIdenticalPreferencesAreNotAConflict(): void {
		$answer = $this->service()->mergeAnswer(
			winner: ['correspondenceLanguage' => 'en'],
			loser: ['correspondenceLanguage' => 'en'],
		);

		$this->assertFalse($answer['needsChoice']);
		$this->assertSame('en', $answer['language']);
	}//end testTwoIdenticalPreferencesAreNotAConflict()

	/**
	 * A caller who may not read the party is refused.
	 *
	 * @return void
	 */
	public function testACallerWhoMayNotReadThePartyIsRefused(): void {
		$this->parties = [];

		$this->assertSame(403, $this->service()->resolve(partyId: 'p1')['status']);
	}//end testACallerWhoMayNotReadThePartyIsRefused()
}//end class
