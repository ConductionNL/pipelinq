<?php

/**
 * Unit tests for ExportSinkRegistry.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Adapter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Adapter;

use InvalidArgumentException;
use OCA\Pipelinq\Adapter\ExportSinkInterface;
use OCA\Pipelinq\Adapter\ExportSinkRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Adapter\ExportSinkRegistry
 */
class ExportSinkRegistryTest extends TestCase {

	/**
	 * Build a sink that reports the given type.
	 *
	 * @param string $type The sink type.
	 *
	 * @return ExportSinkInterface The sink.
	 */
	private function sink(string $type): ExportSinkInterface {
		$sink = $this->createMock(ExportSinkInterface::class);
		$sink->method('getType')->willReturn($type);
		return $sink;
	}//end sink()

	/**
	 * Sinks passed to the constructor are registered under their type.
	 *
	 * @return void
	 */
	public function testConstructorRegistersEverySinkByType(): void {
		$s3       = $this->sink('s3');
		$sftp     = $this->sink('sftp');
		$registry = new ExportSinkRegistry([$s3, $sftp]);

		$this->assertTrue($registry->supports('s3'));
		$this->assertTrue($registry->supports('sftp'));
		$this->assertFalse($registry->supports('gcs'));
		$this->assertSame($s3, $registry->get('s3'));
		$this->assertSame($sftp, $registry->get('sftp'));
	}//end testConstructorRegistersEverySinkByType()

	/**
	 * The constructor accepts any iterable, such as a generator.
	 *
	 * @return void
	 */
	public function testConstructorAcceptsAGenerator(): void {
		$gcs       = $this->sink('gcs');
		$generator = (static function () use ($gcs) {
			yield $gcs;
		})();

		$registry = new ExportSinkRegistry($generator);

		$this->assertSame($gcs, $registry->get('gcs'));
	}//end testConstructorAcceptsAGenerator()

	/**
	 * An empty registry supports nothing.
	 *
	 * @return void
	 */
	public function testEmptyRegistrySupportsNothing(): void {
		$registry = new ExportSinkRegistry();

		$this->assertFalse($registry->supports('s3'));
		$this->assertFalse($registry->supports(''));
	}//end testEmptyRegistrySupportsNothing()

	/**
	 * A later sink of the same type replaces the earlier one.
	 *
	 * @return void
	 */
	public function testRegisterReplacesASinkOfTheSameType(): void {
		$first    = $this->sink('s3');
		$second   = $this->sink('s3');
		$registry = new ExportSinkRegistry([$first]);

		$registry->register($second);

		$this->assertSame($second, $registry->get('s3'));
	}//end testRegisterReplacesASinkOfTheSameType()

	/**
	 * Asking for an unregistered type throws, naming the type.
	 *
	 * @return void
	 */
	public function testGetThrowsForAnUnknownType(): void {
		$registry = new ExportSinkRegistry([$this->sink('s3')]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("No export sink adapter registered for type 'snowflake'.");

		$registry->get('snowflake');
	}//end testGetThrowsForAnUnknownType()
}//end class
