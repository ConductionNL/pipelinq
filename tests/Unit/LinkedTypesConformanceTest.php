<?php

/**
 * Every leaf type a CRM schema declares is mounted on its detail page or named
 * as a deliberate exclusion.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/leaf-integration-broadening/specs/collaboration-leaves/spec.md#requirement-declared-linkedtypes-are-mounted-or-recorded-as-deliberate-exclusions
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Declared-vs-mounted conformance of the schemas' `linkedTypes`.
 *
 * A schema's `linkedTypes` promises a link surface. Before
 * leaf-integration-broadening, `deck` and `forms` were declared on the lead
 * and mounted nowhere, which nothing noticed. This reads the register as the
 * import composes it (every register.d fragment merged on) and the manifest,
 * and names every declared leaf type that is neither an integration widget
 * placed on the type's detail page nor named, in backticks, in that page's
 * `_linkedTypesNote`.
 *
 * @coversNothing
 */
class LinkedTypesConformanceTest extends TestCase {

	/**
	 * The detail page of each schema the rule covers.
	 *
	 * @var array<string, string>
	 */
	private const DETAIL_PAGES = [
		'client'  => 'ClientDetail',
		'contact' => 'ContactDetail',
		'lead'    => 'LeadDetail',
		'ticket'  => 'TicketDetail',
	];

	/**
	 * The register as the import composes it.
	 *
	 * @return array<string, array<string, mixed>> The schemas by slug.
	 */
	private function registerSchemas(): array {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 2));

		$register = (new ConfigFileLoaderService($appManager))->loadConfigurationFile();
		$schemas  = ($register['components']['schemas'] ?? null);
		$this->assertIsArray($schemas, 'The register was not read.');

		return $schemas;
	}//end registerSchemas()

	/**
	 * The manifest's pages by id.
	 *
	 * @return array<string, array<string, mixed>> The pages.
	 */
	private function pages(): array {
		$manifest = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/src/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

		$pages = [];
		foreach ($manifest['pages'] as $page) {
			$pages[$page['id']] = $page;
		}

		return $pages;
	}//end pages()

	/**
	 * Every widget id a page places: in its layout, a tab strip or the side column.
	 *
	 * @param mixed $node The page config, or a part of it.
	 *
	 * @return array<int, string> The placed widget ids.
	 */
	private function placedIds(mixed $node): array {
		if (is_array($node) === false) {
			return [];
		}

		$placed = [];
		foreach ($node as $key => $value) {
			if ($key === 'widgetId' && is_string($value) === true) {
				$placed[] = $value;
			} else if (in_array($key, ['widgetIds', 'sideColumn', 'sideColumnOrder'], true) === true && is_array($value) === true) {
				$placed = array_merge($placed, array_filter($value, 'is_string'));
			}

			$placed = array_merge($placed, $this->placedIds(node: $value));
		}

		return $placed;
	}//end placedIds()

	/**
	 * The leaf types a page mounts as placed integration widgets.
	 *
	 * @param array<string, mixed> $page The manifest page.
	 *
	 * @return array<int, string> The integration ids.
	 */
	private function mounted(array $page): array {
		$config = (array)($page['config'] ?? []);
		$placed = $this->placedIds(node: $config);

		$mounted = [];
		foreach ((array)($config['widgets'] ?? []) as $widget) {
			if (($widget['type'] ?? '') === 'integration' && in_array($widget['id'] ?? '', $placed, true) === true) {
				$mounted[] = (string)$widget['integrationId'];
			}
		}

		return $mounted;
	}//end mounted()

	/**
	 * The leaf types a page names in backticks in its `_linkedTypesNote`.
	 *
	 * @param array<string, mixed> $page The manifest page.
	 *
	 * @return array<int, string> The recorded types.
	 */
	private function recorded(array $page): array {
		preg_match_all('/`([^`]+)`/', (string)($page['config']['_linkedTypesNote'] ?? ''), $matches);

		return $matches[1];
	}//end recorded()

	/**
	 * The declared leaf types a page neither mounts nor records.
	 *
	 * @param array<int, string>   $declared The schema's linkedTypes.
	 * @param array<string, mixed> $page     The detail page.
	 *
	 * @return array<int, string> The unaccounted types.
	 */
	private function unaccounted(array $declared, array $page): array {
		return array_values(array_diff($declared, $this->mounted(page: $page), $this->recorded(page: $page)));
	}//end unaccounted()

	/**
	 * The schemas the rule covers, with their detail pages.
	 *
	 * @return array<string, array{0: string, 1: string}> The cases.
	 */
	public static function coveredSchemas(): array {
		$cases = [];
		foreach (self::DETAIL_PAGES as $schema => $page) {
			$cases[$schema] = [$schema, $page];
		}

		return $cases;
	}//end coveredSchemas()

	/**
	 * Every declared leaf type is mounted on the detail page or recorded there.
	 *
	 * @param string $schema The schema slug.
	 * @param string $pageId The detail page id.
	 *
	 * @return void
	 */
	#[DataProvider('coveredSchemas')]
	public function testEveryDeclaredLeafTypeIsMountedOrRecorded(string $schema, string $pageId): void {
		$schemas = $this->registerSchemas();
		$pages   = $this->pages();
		$this->assertArrayHasKey($schema, $schemas);
		$this->assertArrayHasKey($pageId, $pages);

		$declared = array_map('strval', (array)($schemas[$schema]['linkedTypes'] ?? []));
		foreach ($this->unaccounted(declared: $declared, page: $pages[$pageId]) as $type) {
			$this->fail("Schema '{$schema}' declares the leaf type '{$type}', which page {$pageId} neither mounts nor names in its _linkedTypesNote.");
		}

		$this->assertNotSame([], $declared, "Schema '{$schema}' declares no linkedTypes.");
	}//end testEveryDeclaredLeafTypeIsMountedOrRecorded()

	/**
	 * A page records only leaf types its schema declares, so a note cannot go stale.
	 *
	 * @param string $schema The schema slug.
	 * @param string $pageId The detail page id.
	 *
	 * @return void
	 */
	#[DataProvider('coveredSchemas')]
	public function testAPageRecordsOnlyDeclaredLeafTypes(string $schema, string $pageId): void {
		$declared = array_map('strval', (array)($this->registerSchemas()[$schema]['linkedTypes'] ?? []));

		$this->assertSame([], array_values(array_diff($this->recorded(page: $this->pages()[$pageId]), $declared)), $pageId);
	}//end testAPageRecordsOnlyDeclaredLeafTypes()

	/**
	 * The check names a declared type that is neither mounted nor recorded,
	 * and passes once the page records it.
	 *
	 * @return void
	 */
	public function testAnUnmountedUnrecordedTypeIsNamed(): void {
		$page = [
			'config' => [
				'widgets' => [
					['id' => 'x-email', 'type' => 'integration', 'integrationId' => 'email'],
					['id' => 'x-deck', 'type' => 'integration', 'integrationId' => 'deck'],
				],
				'layout'  => [['widgetId' => 'x-email']],
			],
		];

		// The deck widget is declared but placed nowhere, so it does not count as mounted.
		$this->assertSame(['deck', 'forms'], $this->unaccounted(declared: ['email', 'deck', 'forms'], page: $page));

		$page['config']['_linkedTypesNote'] = 'Not mounted on purpose: `deck` and `forms`.';
		$this->assertSame([], $this->unaccounted(declared: ['email', 'deck', 'forms'], page: $page));
	}//end testAnUnmountedUnrecordedTypeIsNamed()

	/**
	 * The lead page mounts the Talk, Deck and Forms leaves as tabs of its body
	 * strip, and the sidebar stays audit-only.
	 *
	 * @return void
	 */
	public function testTheLeadPageMountsTalkDeckAndFormsInItsBody(): void {
		$lead = $this->pages()['LeadDetail'];
		$this->assertEqualsCanonicalizing(
			['calendar', 'email', 'notes', 'files', 'decidesk-decisions', 'talk', 'deck', 'forms'],
			$this->mounted(page: $lead)
		);

		$tabs = [];
		foreach ($lead['config']['widgets'] as $widget) {
			if ($widget['id'] === 'lead-panels') {
				$tabs = array_column($widget['content']['tabs'], 'widgetId', 'label');
			}
		}

		$this->assertSame('lead-talk', $tabs['Deal room'] ?? null);
		$this->assertSame('lead-deck', $tabs['Board'] ?? null);
		$this->assertSame('lead-forms', $tabs['Intake forms'] ?? null);
		$this->assertSame(['audit'], array_column($lead['config']['sidebar']['tabs'], 'id'));
	}//end testTheLeadPageMountsTalkDeckAndFormsInItsBody()

	/**
	 * Talk is declared on the client and the lead, and not on the contact.
	 *
	 * @return void
	 */
	public function testTalkIsDeclaredOnClientAndLeadOnly(): void {
		$schemas = $this->registerSchemas();

		$this->assertContains('talk', $schemas['client']['linkedTypes']);
		$this->assertContains('talk', $schemas['lead']['linkedTypes']);
		$this->assertSame(['email', 'calendar'], $schemas['contact']['linkedTypes']);
		$this->assertContains('talk', $this->mounted(page: $this->pages()['ClientDetail']));
	}//end testTalkIsDeclaredOnClientAndLeadOnly()
}//end class
