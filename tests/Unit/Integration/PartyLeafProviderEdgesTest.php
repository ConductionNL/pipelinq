<?php

/**
 * PartyLeafProvider: an unreadable party and an unconfigured field set.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Integration
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Integration;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Integration\PartyLeafProvider;
use OCA\Pipelinq\Service\PartyIndicatorService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A party that cannot be read is refused; without a field-set schema the
 * panel shows no fields and asks OpenRegister for none.
 *
 * @covers \OCA\Pipelinq\Integration\PartyLeafProvider
 */
class PartyLeafProviderEdgesTest extends TestCase {

	/**
	 * Object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The provider with the given app config.
	 *
	 * @param array<string, string> $config App config values.
	 *
	 * @return PartyLeafProvider
	 */
	private function provider(array $config): PartyLeafProvider {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$indicators = $this->createMock(PartyIndicatorService::class);
		$indicators->method('resolve')->willReturn([['label' => 'VIP']]);

		return new PartyLeafProvider($appConfig, $this->objects, $indicators, $this->logger);
	}//end provider()

	/**
	 * A read that throws is logged and refused as forbidden.
	 *
	 * @return void
	 */
	public function testAPartyThatCannotBeReadIsRefused(): void {
		$this->objects->expects($this->once())->method('find')->with('p-1')->willThrowException(new RuntimeException('no access'));
		$this->logger->expects($this->once())->method('debug')->with(
			'PartyLeafProvider: the party could not be read',
			['party' => 'p-1', 'exception' => 'no access']
		);

		$this->assertSame(
			['status' => 403, 'error' => 'You may not read this party.'],
			$this->provider(config: ['register' => 'r'])->describe(partyId: ' p-1 ')
		);
	}//end testAPartyThatCannotBeReadIsRefused()

	/**
	 * A party whose serialised form is not an array is refused.
	 *
	 * @return void
	 */
	public function testAPartyWithoutAnArrayPayloadIsRefused(): void {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn('not an array');
		$this->objects->method('find')->willReturn($entity);

		$this->assertSame(403, $this->provider(config: [])->describe(partyId: 'p-1')['status']);
	}//end testAPartyWithoutAnArrayPayloadIsRefused()

	/**
	 * Without a field-set schema the party is described with no fields.
	 *
	 * @return void
	 */
	public function testWithoutAFieldSetSchemaThereAreNoFields(): void {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn(['type' => 'person', 'fieldValues' => ['shoe' => 42]]);
		$this->objects->method('find')->willReturn($entity);
		$this->objects->expects($this->never())->method('findAll');

		$this->assertSame(
			[
				'status' => 200,
				'party' => ['id' => 'p-1', 'kind' => 'person', 'parentOrganisation' => '', 'organisationPath' => ''],
				'fields' => [],
				'indicators' => [['label' => 'VIP']],
			],
			$this->provider(config: ['register' => 'r'])->describe(partyId: 'p-1')
		);
	}//end testWithoutAFieldSetSchemaThereAreNoFields()
}//end class
