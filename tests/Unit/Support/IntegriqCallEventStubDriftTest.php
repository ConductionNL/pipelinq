<?php

/**
 * The stub of integriq's call event may not drift from the class it doubles.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Support;

use OCA\Pipelinq\Service\Calls\CallMirror;
use PHPUnit\Framework\TestCase;

/**
 * Compares tests/Stubs/Integriq/Event/CallEvent.php with integriq's own file.
 *
 * CallEventListener reads integriq's event by its public properties, and the
 * unit suite dispatches the stub. When integriq renames a property, the stub
 * still agrees with the listener, so the suite stays green while no call is
 * mirrored on a real instance. This test reads both files and fails on the
 * difference.
 *
 * It reads source, not reflection: the stub and the real class share one name,
 * so one process can only ever load one of them.
 *
 * It skips without a sibling integriq checkout, because there is then nothing
 * to compare with. The shared PHPUnit job installs integriq development beside
 * pipelinq, so in CI the comparison runs. Locally, point PIPELINQ_INTEGRIQ_LIB
 * at an integriq `lib/` directory.
 *
 * @coversNothing
 */
class IntegriqCallEventStubDriftTest extends TestCase {
	/**
	 * The stub.
	 */
	private const STUB = __DIR__ . '/../../Stubs/Integriq/Event/CallEvent.php';

	/**
	 * Where the shared PHPUnit job puts integriq: beside this app.
	 */
	private const SIBLING_LIB = __DIR__ . '/../../../../integriq/lib';

	/**
	 * Integriq's own file, or null when no checkout is there.
	 *
	 * @return string|null The path.
	 */
	private function realFile(): ?string {
		$lib = getenv('PIPELINQ_INTEGRIQ_LIB');
		if (is_string($lib) === false || $lib === '') {
			$lib = self::SIBLING_LIB;
		}

		$file = realpath($lib . '/Event/CallEvent.php');
		if ($file === false) {
			return null;
		}

		return $file;
	}//end realFile()

	/**
	 * Integriq's source, or a skip that says what is missing.
	 *
	 * @return string The source.
	 */
	private function realSource(): string {
		$file = $this->realFile();
		if ($file === null) {
			$this->markTestSkipped(
				'No integriq checkout at ' . self::SIBLING_LIB . ' and PIPELINQ_INTEGRIQ_LIB is not set: '
				. 'there is nothing to compare the stub with. CI installs integriq, so this runs there.'
			);
		}

		return (string)file_get_contents($file);
	}//end realSource()

	/**
	 * The constructor's parameters, one normalised string each.
	 *
	 * @param string $source A PHP file.
	 *
	 * @return array<int, string> For example `public readonly string $kind`.
	 */
	private function constructorParameters(string $source): array {
		$this->assertSame(1, preg_match('/function\s+__construct\s*\((.*?)\)\s*\{/s', $source, $found), 'No constructor found.');
		$parameters = [];
		foreach (explode(',', $found[1]) as $parameter) {
			$parameter = trim((string)preg_replace('/\s+/', ' ', $parameter));
			if ($parameter !== '') {
				$parameters[] = $parameter;
			}
		}

		return $parameters;
	}//end constructorParameters()

	/**
	 * What the class extends.
	 *
	 * @param string $source A PHP file.
	 *
	 * @return string The parent's short name.
	 */
	private function parentOf(string $source): string {
		$this->assertSame(1, preg_match('/class\s+CallEvent\s+extends\s+(\w+)/', $source, $found), 'No class CallEvent found.');
		return $found[1];
	}//end parentOf()

	/**
	 * The parser sees a renamed property: without this the comparison could pass on two empty lists.
	 *
	 * @return void
	 */
	public function testTheParserReadsTheStub(): void {
		$stub = (string)file_get_contents(self::STUB);
		$parameters = $this->constructorParameters(source: $stub);

		$this->assertCount(9, $parameters);
		$this->assertSame('public readonly string $kind', $parameters[0]);
		$this->assertSame('public readonly int $durationSeconds = 0', $parameters[8]);
		$this->assertNotSame(
			$parameters,
			$this->constructorParameters(source: str_replace('$callerNumber', '$from', $stub)),
			'A renamed property must change what the parser returns.'
		);
	}//end testTheParserReadsTheStub()

	/**
	 * The stub has the real class's parent and the real constructor, name for name and type for type.
	 *
	 * @return void
	 */
	public function testTheStubHasIntegriqsConstructor(): void {
		$real = $this->realSource();
		$stub = (string)file_get_contents(self::STUB);

		$this->assertSame($this->parentOf(source: $real), $this->parentOf(source: $stub));
		$this->assertSame(
			$this->constructorParameters(source: $real),
			$this->constructorParameters(source: $stub),
			'Integriq changed CallEvent. Copy its constructor into tests/Stubs/Integriq/Event/CallEvent.php '
			. 'and check what CallEventListener reads. Compared with ' . $this->realFile() . '.'
		);
	}//end testTheStubHasIntegriqsConstructor()

	/**
	 * The mirror accepts exactly the kinds integriq can send.
	 *
	 * @return void
	 */
	public function testTheMirrorKnowsEveryKindIntegriqSends(): void {
		$real = $this->realSource();
		$this->assertGreaterThan(0, preg_match_all("/const\s+KIND_\w+\s*=\s*'([a-z]+)'/", $real, $found), 'No KIND_ constants found.');

		$kinds = $found[1];
		sort($kinds);
		$known = CallMirror::KINDS;
		sort($known);
		$this->assertSame($kinds, $known);
	}//end testTheMirrorKnowsEveryKindIntegriqSends()
}//end class
