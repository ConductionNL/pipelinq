<?php

/**
 * Unit tests for the concrete export sink adapters.
 *
 * Each adapter is driven through upload() against a fake OpenConnector
 * CallService, so the test asserts the acknowledgement each one derives from
 * the warehouse's response and its fallback order.
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

use OCA\Pipelinq\Adapter\AbstractOpenConnectorSink;
use OCA\Pipelinq\Adapter\AzureDataLakeExportAdapter;
use OCA\Pipelinq\Adapter\BigQueryExportAdapter;
use OCA\Pipelinq\Adapter\GcsExportAdapter;
use OCA\Pipelinq\Adapter\PostgresExportAdapter;
use OCA\Pipelinq\Adapter\S3ExportAdapter;
use OCA\Pipelinq\Adapter\SftpExportAdapter;
use OCA\Pipelinq\Adapter\SnowflakeExportAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Pipelinq\Adapter\AzureDataLakeExportAdapter
 * @covers \OCA\Pipelinq\Adapter\BigQueryExportAdapter
 * @covers \OCA\Pipelinq\Adapter\GcsExportAdapter
 * @covers \OCA\Pipelinq\Adapter\PostgresExportAdapter
 * @covers \OCA\Pipelinq\Adapter\S3ExportAdapter
 * @covers \OCA\Pipelinq\Adapter\SftpExportAdapter
 * @covers \OCA\Pipelinq\Adapter\SnowflakeExportAdapter
 * @uses   \OCA\Pipelinq\Adapter\AbstractOpenConnectorSink
 * @uses   \OCA\Pipelinq\Support\FleetAppId
 */
class ExportAdaptersTest extends TestCase {

	/**
	 * Build an adapter whose CallService::put() returns the given result.
	 *
	 * @param class-string<AbstractOpenConnectorSink> $class  The adapter class.
	 * @param mixed                                   $result The put() result.
	 *
	 * @return AbstractOpenConnectorSink The adapter.
	 */
	private function adapter(string $class, mixed $result): AbstractOpenConnectorSink {
		$service = new class($result) {
			public function __construct(private mixed $result) {
			}

			public function put(array $credentials, array $destination, string $remotePath, string $contents): mixed {
				return $this->result;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($service);

		return new $class($container, $this->createMock(LoggerInterface::class));
	}//end adapter()

	/**
	 * Every adapter reports its own sink type.
	 *
	 * @return array<string,array{0:class-string<AbstractOpenConnectorSink>,1:string}>
	 */
	public static function typeProvider(): array {
		return [
			'azure'     => [AzureDataLakeExportAdapter::class, 'azure_data_lake'],
			'bigquery'  => [BigQueryExportAdapter::class, 'bigquery'],
			'gcs'       => [GcsExportAdapter::class, 'gcs'],
			'postgres'  => [PostgresExportAdapter::class, 'postgres'],
			's3'        => [S3ExportAdapter::class, 's3'],
			'sftp'      => [SftpExportAdapter::class, 'sftp'],
			'snowflake' => [SnowflakeExportAdapter::class, 'snowflake'],
		];
	}//end typeProvider()

	/**
	 * Each adapter reports the type it is registered under.
	 *
	 * @param class-string<AbstractOpenConnectorSink> $class    The adapter class.
	 * @param string                                  $expected The expected type.
	 *
	 * @return void
	 *
	 * @dataProvider typeProvider
	 */
	public function testGetType(string $class, string $expected): void {
		$this->assertSame($expected, $this->adapter($class, [])->getType());
	}//end testGetType()

	/**
	 * Acknowledgements per adapter, in their fallback order.
	 *
	 * @return array<string,array{0:class-string<AbstractOpenConnectorSink>,1:mixed,2:string}>
	 */
	public static function acknowledgementProvider(): array {
		return [
			's3 etag'                   => [S3ExportAdapter::class, ['etag' => 'e1', 'ETag' => 'E1', 'path' => 'p'], 'e1'],
			's3 ETag'                   => [S3ExportAdapter::class, ['ETag' => 'E1', 'path' => 'p'], 'E1'],
			's3 path'                   => [S3ExportAdapter::class, ['path' => 'p'], 'p'],
			's3 nothing'                => [S3ExportAdapter::class, [], ''],
			'azure etag'                => [AzureDataLakeExportAdapter::class, ['etag' => 'e', 'blobProperties' => 'b'], 'e'],
			'azure blobProperties'      => [AzureDataLakeExportAdapter::class, ['blobProperties' => 'b', 'path' => 'p'], 'b'],
			'azure path'                => [AzureDataLakeExportAdapter::class, ['path' => 'p'], 'p'],
			'bigquery loadJobId'        => [BigQueryExportAdapter::class, ['loadJobId' => 'l', 'jobId' => 'j'], 'l'],
			'bigquery jobId'            => [BigQueryExportAdapter::class, ['jobId' => 'j', 'path' => 'p'], 'j'],
			'bigquery path'             => [BigQueryExportAdapter::class, ['path' => 'p'], 'p'],
			'gcs generation'            => [GcsExportAdapter::class, ['generation' => 17, 'md5Hash' => 'm'], '17'],
			'gcs md5Hash'               => [GcsExportAdapter::class, ['md5Hash' => 'm', 'path' => 'p'], 'm'],
			'gcs path'                  => [GcsExportAdapter::class, ['path' => 'p'], 'p'],
			'postgres copyCount'        => [PostgresExportAdapter::class, ['copyCount' => 42, 'path' => 'p'], 'COPY 42'],
			'postgres path'             => [PostgresExportAdapter::class, ['path' => 'p'], 'p'],
			'postgres nothing'          => [PostgresExportAdapter::class, [], ''],
			'sftp remotePath'           => [SftpExportAdapter::class, ['remotePath' => 'r', 'path' => 'p'], 'r'],
			'sftp path'                 => [SftpExportAdapter::class, ['path' => 'p'], 'p'],
			'sftp nothing'              => [SftpExportAdapter::class, [], ''],
			'snowflake queryId'         => [SnowflakeExportAdapter::class, ['queryId' => 'q', 'query_id' => 'q2'], 'q'],
			'snowflake query_id'        => [SnowflakeExportAdapter::class, ['query_id' => 'q2', 'path' => 'p'], 'q2'],
			'snowflake path'            => [SnowflakeExportAdapter::class, ['path' => 'p'], 'p'],
			'non-array result -> path'  => [SnowflakeExportAdapter::class, true, 'exports/out.csv'],
		];
	}//end acknowledgementProvider()

	/**
	 * upload() returns the acknowledgement the adapter derives from put()'s result.
	 *
	 * @param class-string<AbstractOpenConnectorSink> $class    The adapter class.
	 * @param mixed                                   $result   The put() result.
	 * @param string                                  $expected The acknowledgement.
	 *
	 * @return void
	 *
	 * @dataProvider acknowledgementProvider
	 */
	public function testUploadAcknowledgement(string $class, mixed $result, string $expected): void {
		$ack = $this->adapter($class, $result)->upload(['key' => 'k'], ['bucket' => 'b'], 'exports/out.csv', 'a,b');

		$this->assertSame($expected, $ack);
	}//end testUploadAcknowledgement()
}//end class
