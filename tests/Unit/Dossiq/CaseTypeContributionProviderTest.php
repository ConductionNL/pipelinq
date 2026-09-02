<?php

/**
 * Unit tests for the dossiq case-type contribution provider.
 *
 * Dossiq owns case management; pipelinq contributes `ticket` as a case type
 * rather than running a parallel system. Dossiq discovers this class by
 * convention FQCN and duck-types it, so what these tests protect is the
 * CONTRACT, not an interface a compiler would enforce:
 *
 *   - the class stays dependency-free, so pipelinq is installable and inert
 *     without dossiq (dossiq is declared `required: false`);
 *   - the method dossiq probes for keeps its name;
 *   - the three ticket kinds stay ONE case type with a discriminator, matching
 *     the single `ticket` schema unify-ticket-supertype produced.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Dossiq
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/request-management/spec.md#requirement-request-status-lifecycle-mvp
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Dossiq;

use OCA\Pipelinq\Dossiq\CaseTypeContributionProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for CaseTypeContributionProvider.
 */
class CaseTypeContributionProviderTest extends TestCase {

	/**
	 * The provider under test.
	 *
	 * @var CaseTypeContributionProvider
	 */
	private CaseTypeContributionProvider $provider;

	/**
	 * Build the provider directly, with no container and no dossiq.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->provider = new CaseTypeContributionProvider();
	}//end setUp()

	/**
	 * The class takes no constructor dependencies.
	 *
	 * This is what lets dossiq construct it through the server container while
	 * pipelinq stays installable alone. A dependency added here would fail no
	 * compiler; it would fail at discovery time, on someone else's instance.
	 *
	 * @return void
	 */
	public function testTakesNoConstructorDependencies(): void {
		$constructor = (new ReflectionClass(CaseTypeContributionProvider::class))->getConstructor();

		$this->assertTrue(
			($constructor === null || $constructor->getNumberOfParameters() === 0),
			'the provider must construct without arguments'
		);
	}//end testTakesNoConstructorDependencies()

	/**
	 * The class imports nothing and implements nothing.
	 *
	 * @return void
	 */
	public function testIsPlain(): void {
		$reflected = new ReflectionClass(CaseTypeContributionProvider::class);

		$this->assertSame([], $reflected->getInterfaceNames(), 'the provider must implement no interface');

		$source = (string)file_get_contents((string)$reflected->getFileName());
		$this->assertSame(
			0,
			preg_match_all('/^use\s+/m', $source),
			'the provider must import nothing: importing dossiq would break pipelinq without it'
		);
	}//end testIsPlain()

	/**
	 * The probed method keeps its name.
	 *
	 * Dossiq narrows on `method_exists($provider, 'getCaseTypes')`. Renaming it
	 * makes the provider silently invisible: no error, no case types, and
	 * nothing in any log naming pipelinq.
	 *
	 * @return void
	 */
	public function testExposesTheProbedMethod(): void {
		$this->assertTrue(method_exists($this->provider, 'getCaseTypes'));
	}//end testExposesTheProbedMethod()

	/**
	 * Ticket is contributed with the fields the registry requires.
	 *
	 * @return void
	 */
	public function testContributesTicketWithIdentifierAndTitle(): void {
		$types = $this->provider->getCaseTypes();

		$this->assertCount(1, $types);
		$this->assertSame('pipelinq-ticket', $types[0]['identifier']);
		$this->assertNotSame('', trim((string)$types[0]['title']));
		$this->assertSame('ticket', $types[0]['schema']);
	}//end testContributesTicketWithIdentifierAndTitle()

	/**
	 * The three kinds stay one case type with a discriminator.
	 *
	 * Declaring three case types would re-split, on dossiq's side, exactly what
	 * unify-ticket-supertype joined into one `ticket` schema.
	 *
	 * @return void
	 */
	public function testKeepsTheThreeKindsAsSubtypesOfOneCaseType(): void {
		$types = $this->provider->getCaseTypes();

		$this->assertSame('ticketType', $types[0]['discriminator']);
		$this->assertSame(
			['request', 'complaint', 'interaction'],
			array_column($types[0]['subtypes'], 'value')
		);
	}//end testKeepsTheThreeKindsAsSubtypesOfOneCaseType()
}//end class
