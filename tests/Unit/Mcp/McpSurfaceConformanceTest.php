<?php

/**
 * Conformance of pipelinq's MCP tool surface.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Mcp;

use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCA\Pipelinq\Mcp\PipelinqScannableServices;
use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCA\Pipelinq\Service\LeadService;
use OCA\Pipelinq\Service\TicketService;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * The whole tool surface, derived and curated, read from the shipped register
 * (monolith plus every register.d fragment, merged as the import merges it)
 * and from the #[McpTool] attributes.
 *
 * Every assertion here is about the declarations, so the test reads the real
 * files rather than fixtures: a fragment that drops a block, a schema that
 * joins the surface, or a tool that declares the wrong reach fails it.
 *
 * @spec openspec/changes/mcp-full-action-coverage/specs/crm-mcp-tool-surface/spec.md#requirement-every-tool-declares-scope-and-reach-for-the-grant-matrix
 * @spec openspec/changes/mcp-full-action-coverage/specs/crm-mcp-tool-surface/spec.md#requirement-excluded-surfaces-are-never-agent-invocable
 */
#[CoversNothing]
class McpSurfaceConformanceTest extends TestCase {

	/**
	 * The schemas that may carry an `x-openregister-mcp` block.
	 *
	 * The spec's `task` is the `crmTask` schema. `enquiry` joined with
	 * website-enquiry (register.d/26-website-enquiry.json) as a read-only
	 * surface after this list was written; it stays here by name so widening
	 * the surface is always a reviewed edit of this list.
	 *
	 * @var list<string>
	 */
	private const ALLOWED_SCHEMAS = ['client', 'contact', 'lead', 'ticket', 'crmTask', 'product', 'leadProduct', 'enquiry'];

	/**
	 * The derived verbs each schema declares.
	 *
	 * @var array<string, list<string>>
	 */
	private const DERIVED_VERBS = [
		'client' => ['search', 'get', 'create', 'update'],
		'contact' => ['search', 'get', 'create', 'update'],
		'crmTask' => ['search', 'get', 'create', 'update'],
		'lead' => ['search', 'get'],
		'ticket' => ['search', 'get'],
		'product' => ['search', 'get'],
		'leadProduct' => ['search', 'get', 'create', 'update', 'delete'],
		'enquiry' => ['search', 'get'],
	];

	/**
	 * The curated tools, by the class that carries them.
	 *
	 * @var array<class-string, list<string>>
	 */
	private const CURATED = [
		LeadService::class => ['createLead', 'updateLead', 'winLead', 'loseLead', 'pipelineForecast'],
		TicketService::class => [
			'logContactmoment', 'createTicket', 'updateTicket', 'startTicket', 'completeTicket',
			'convertTicket', 'resolveTicket', 'rejectTicket', 'closeTicket',
		],
	];

	/**
	 * The schemas of the shipped register, merged with every fragment.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function mergedSchemas(): array {
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));

		return (new ConfigFileLoaderService(appManager: $appManager))->loadConfigurationFile()['components']['schemas'];
	}//end mergedSchemas()

	/**
	 * Every `x-openregister-mcp` block, by schema slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function mcpBlocks(): array {
		$blocks = [];
		foreach ($this->mergedSchemas() as $slug => $schema) {
			$block = ($schema['configuration']['x-openregister-mcp'] ?? ($schema['x-openregister-mcp'] ?? null));
			if (is_array($block) === true) {
				$blocks[(string)$slug] = $block;
			}
		}

		return $blocks;
	}//end mcpBlocks()

	/**
	 * Every #[McpTool] in lib/, as [class, method, attribute].
	 *
	 * @return list<array{0: class-string, 1: string, 2: McpTool}>
	 */
	private function curatedTools(): array {
		$tools = [];
		foreach (array_keys(self::CURATED) as $class) {
			foreach ((new ReflectionClass($class))->getMethods() as $method) {
				foreach ($method->getAttributes(McpTool::class) as $attribute) {
					$tools[] = [$class, $method->getName(), $attribute->newInstance()];
				}
			}
		}

		return $tools;
	}//end curatedTools()

	/**
	 * Every schema declares exactly its verbs, and the blocks survive the fragment merge.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mcp-full-action-coverage/specs/crm-mcp-tool-surface/spec.md#requirement-crm-crud-tools-are-declared-on-the-schema-not-hand-coded
	 */
	public function testEverySchemaDeclaresExactlyItsVerbs(): void {
		$blocks = $this->mcpBlocks();
		foreach (self::DERIVED_VERBS as $slug => $verbs) {
			$this->assertArrayHasKey(key: $slug, array: $blocks, message: "$slug lost its x-openregister-mcp block in the merge");
			$this->assertTrue(condition: ($blocks[$slug]['enabled'] ?? false), message: "$slug is not enabled");
			$declared = array_keys($blocks[$slug]['tools'] ?? []);
			sort($declared);
			$wanted = $verbs;
			sort($wanted);
			$this->assertSame(expected: $wanted, actual: $declared, message: "$slug verbs");
		}

		$this->assertSame(expected: ['type', 'status', 'clientId'], actual: $blocks['crmTask']['tools']['search']['filters']);
		$this->assertSame(expected: ['client'], actual: $blocks['contact']['tools']['search']['filters']);
		$this->assertTrue(condition: $blocks['leadProduct']['tools']['delete']['destructiveHint']);
	}//end testEverySchemaDeclaresExactlyItsVerbs()

	/**
	 * Reads are read-only and writes are not; a declared reach follows the read/write split.
	 *
	 * @return void
	 */
	public function testDerivedToolsFollowTheReadWriteSplit(): void {
		foreach ($this->mcpBlocks() as $slug => $block) {
			foreach (($block['tools'] ?? []) as $verb => $tool) {
				$scope = (string)($tool['scope'] ?? '');
				$label = "$slug.$verb";
				$this->assertContains(needle: $scope, haystack: ['read', 'create', 'update', 'delete'], message: "$label scope");
				if (in_array($verb, ['search', 'get'], true) === true) {
					$this->assertSame(expected: 'read', actual: $scope, message: $label);
					$this->assertTrue(condition: ($tool['readOnlyHint'] ?? false), message: "$label must be readOnlyHint");
				} else {
					$this->assertSame(expected: $verb, actual: $scope, message: $label);
					$this->assertNotTrue(condition: ($tool['readOnlyHint'] ?? false), message: "$label is a write");
				}

				// Until openregister accepts `reach` in a tool entry (companion
				// mcp-reach-annotation) none is declared; once one is, it must
				// follow the same split as the curated tools.
				if (isset($tool['reach']) === true) {
					$expectedReach = 'instance';
					if ($scope === 'read') {
						$expectedReach = 'user';
					}

					$this->assertSame(expected: $expectedReach, actual: $tool['reach'], message: "$label reach");
				}
			}
		}
	}//end testDerivedToolsFollowTheReadWriteSplit()

	/**
	 * Every curated tool declares scope and reach: reads `user` and read-only, writes `instance`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mcp-full-action-coverage/specs/crm-mcp-tool-surface/spec.md#requirement-every-tool-declares-scope-and-reach-for-the-grant-matrix
	 */
	public function testCuratedToolsDeclareScopeAndReach(): void {
		foreach ($this->curatedTools() as [$class, $method, $tool]) {
			$label = "$class::$method";
			$this->assertNotNull(actual: $tool->scope, message: "$label declares no scope");
			$this->assertNotContains(needle: $tool->reach, haystack: ['self', 'external', null], message: "$label reach");
			if ($tool->scope === 'read') {
				$this->assertSame(expected: 'user', actual: $tool->reach, message: $label);
				$this->assertTrue(condition: $tool->readOnlyHint, message: $label);
			} else {
				$this->assertSame(expected: 'instance', actual: $tool->reach, message: $label);
				$this->assertFalse(condition: $tool->readOnlyHint, message: $label);
			}
		}
	}//end testCuratedToolsDeclareScopeAndReach()

	/**
	 * Exactly the fourteen curated tools exist, on the two scannable services.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mcp-full-action-coverage/specs/crm-mcp-tool-surface/spec.md#requirement-hand-written-service-tools-take-precedence-over-derived-tools
	 */
	public function testTheCuratedSetIsExactlyFourteenTools(): void {
		$found = [];
		foreach ($this->curatedTools() as [$class, $method, $tool]) {
			$found[$class][] = (string)$tool->name;
			$this->assertSame(expected: $method, actual: $tool->name, message: 'a tool is named after its method');
		}

		$expected = self::CURATED;
		foreach ($expected as $class => $names) {
			sort($names);
			$namesFound = ($found[$class] ?? []);
			sort($namesFound);
			$this->assertSame(expected: $names, actual: $namesFound, message: $class);
		}

		$this->assertSame(expected: 14, actual: count($found, COUNT_RECURSIVE) - count($found));
		$this->assertSame(
			expected: [LeadService::class, TicketService::class],
			actual: (new PipelinqScannableServices())->getScannableServiceClasses()
		);
	}//end testTheCuratedSetIsExactlyFourteenTools()

	/**
	 * No schema outside the allow-list carries a block.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mcp-full-action-coverage/specs/crm-mcp-tool-surface/spec.md#requirement-excluded-surfaces-are-never-agent-invocable
	 */
	public function testOnlyAllowListedSchemasCarryAToolBlock(): void {
		$outside = array_values(array_diff(array_keys($this->mcpBlocks()), self::ALLOWED_SCHEMAS));

		$this->assertSame(expected: [], actual: $outside, message: 'schemas outside the MCP allow-list carry an x-openregister-mcp block');
	}//end testOnlyAllowListedSchemasCarryAToolBlock()

	/**
	 * No #[McpTool] exists outside LeadService and TicketService.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mcp-full-action-coverage/specs/crm-mcp-tool-surface/spec.md#requirement-excluded-surfaces-are-never-agent-invocable
	 */
	public function testNoCuratedToolOutsideTheTwoServices(): void {
		$lib = dirname(__DIR__, 3) . '/lib';
		$allowed = [$lib . '/Service/LeadService.php', $lib . '/Service/TicketService.php'];
		$outside = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
			if ($file->getExtension() !== 'php' || in_array($file->getPathname(), $allowed, true) === true) {
				continue;
			}

			if (preg_match('/#\[\s*(\\\\?OCA\\\\OpenRegister\\\\Mcp\\\\Attribute\\\\)?McpTool\s*\(/', (string)file_get_contents($file->getPathname())) === 1) {
				$outside[] = substr($file->getPathname(), strlen($lib) + 1);
			}
		}

		$this->assertSame(expected: [], actual: $outside, message: '#[McpTool] outside LeadService and TicketService');
	}//end testNoCuratedToolOutsideTheTwoServices()
}//end class
