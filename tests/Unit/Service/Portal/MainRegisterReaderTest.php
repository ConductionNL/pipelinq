<?php

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\Portal\MainRegisterReader;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The portal's write into the main register.
 *
 * @spec exclude the portal backend has no owning requirement (see MainRegisterReader::save)
 */
class MainRegisterReaderTest extends TestCase {
	/**
	 * A portal write arrives without a Nextcloud user: a resident is proven by
	 * the signed portal assertion and the caller's own checks, never by a
	 * Nextcloud session. Saving under OpenRegister's per-user RBAC therefore
	 * ran as "Anonymous" and was refused for every ticket ("User 'Anonymous'
	 * does not have permission to 'create' objects in schema 'Ticket'", found
	 * by the Woo journey e2e, J4). The register and schema stay pinned by the
	 * reader, so the save runs without per-user RBAC and tenant scoping.
	 *
	 * @return void
	 */
	public function testAPortalWriteIsNotRefusedForHavingNoNextcloudUser(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => '7',
				'ticket_schema' => '42',
				default => $default,
			}
		);

		$saved = $this->createMock(\OCA\OpenRegister\Contract\ObjectEntityInterface::class);
		$saved->method('jsonSerialize')->willReturn(['id' => 't-1', 'title' => 'Vraag']);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->expects($this->once())
			->method('saveObject')
			->with(
				$this->equalTo(['title' => 'Vraag']),
				$this->anything(),
				$this->equalTo('7'),
				$this->equalTo('42'),
				$this->isNull(),
				$this->isFalse(),
				$this->isFalse(),
			)
			->willReturn($saved);

		$reader = new MainRegisterReader($appConfig, $this->createMock(LoggerInterface::class), $objectService);
		$reader->save(schemaKey: 'ticket', data: ['title' => 'Vraag']);
	}//end testAPortalWriteIsNotRefusedForHavingNoNextcloudUser()
}//end class
