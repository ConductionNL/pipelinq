<?php

/**
 * Unit tests for ContactObjectReader::page().
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/example-set-zuiddrecht/tasks.md#task-2.5
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ContactObjectReader;
use OCA\Pipelinq\Service\RegisterResolverService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ContactObjectReader::page().
 */
class ContactObjectReaderPageTest extends TestCase {

	/**
	 * The app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * OpenRegister's object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * Build the collaborators: register 'reg', the given client schema.
	 *
	 * @param string $schema The configured client schema.
	 *
	 * @return ContactObjectReader
	 */
	private function reader(string $schema): ContactObjectReader {
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnMap([['pipelinq', 'client_schema', '', $schema]]);
		$registers = $this->createMock(originalClassName: RegisterResolverService::class);
		$registers->method('resolve')->willReturn('reg');
		$this->objects = $this->createMock(originalClassName: ObjectServiceInterface::class);

		return new ContactObjectReader(
			appConfig: $this->appConfig,
			registerResolver: $registers,
			objectService: $this->objects,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end reader()

	/**
	 * A page reads the client schema in the contact register with the limit and offset given.
	 *
	 * @return void
	 */
	public function testReadsOnePageOfClients(): void {
		$reader = $this->reader(schema: 'cli');
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'reg', 'schema' => 'cli'], 'limit' => 50, 'offset' => 100])
			->willReturn([['id' => 'c-1', 'name' => 'A']]);

		$this->assertSame(expected: [['id' => 'c-1', 'name' => 'A']], actual: $reader->page(objectType: 'client', limit: 50, offset: 100));
	}//end testReadsOnePageOfClients()

	/**
	 * An unknown type, or a schema that is not configured, reads nothing.
	 *
	 * @return void
	 */
	public function testRefusesAnUnknownTypeOrAMissingSchema(): void {
		$reader = $this->reader(schema: '');
		$this->objects->expects($this->never())->method('findAll');

		$this->assertNull(actual: $reader->page(objectType: 'client', limit: 10, offset: 0));
		$this->assertNull(actual: $reader->page(objectType: 'lead', limit: 10, offset: 0));
	}//end testRefusesAnUnknownTypeOrAMissingSchema()
}//end class
