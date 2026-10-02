<?php

/**
 * The portal resolves its schemas under the keys the install writes.
 *
 * Builds the REAL PortalObjectRepository and MainRegisterReader over an app
 * config that holds only what the register import writes. Before pipelinq#2037
 * the repository asked for `crmPortalAccount_schema` (the install writes the
 * pinned `portalAccount_schema`) and the reader asked for bare keys such as
 * `posTransaction` (the install writes `posTransaction_schema`), so no resident
 * could log in and every request, invoice, order and contract list was empty.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
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

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\Portal\MainRegisterReader;
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Real key resolution for the portal repository and main-register reader.
 */
class PortalSchemaKeyResolutionTest extends TestCase {
	/**
	 * Portal-register slugs every portal service reads.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function portalSlugs(): array {
		return [
			'account (login, reset, profile)' => ['crmPortalAccount'],
			'session' => ['crmPortalSession'],
			'delegation' => ['portalDelegation'],
			'audit event' => ['portalAuditEvent'],
			'tenant config' => ['portalTenantConfig'],
		];
	}//end portalSlugs()

	/**
	 * Main-register slugs the portal's read facades and cleanup read.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function mainSlugs(): array {
		return [
			'invoices and orders' => ['posTransaction'],
			'contracts (pinned to contract_schema)' => ['salesContract'],
			'contacts (cleanup)' => ['contact'],
		];
	}//end mainSlugs()

	/**
	 * Every portal-register schema resolves to the id the install stored.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return void
	 */
	#[DataProvider('portalSlugs')]
	public function testPortalRepositoryResolvesTheInstalledSchema(string $slug): void {
		$repository = new PortalObjectRepository(
			InstalledAppConfig::wire($this->createMock(IAppConfig::class)),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ObjectServiceInterface::class)
		);

		$this->assertSame(InstalledAppConfig::schemaId(slug: $slug), $repository->schemaId(schemaSlug: $slug));
	}//end testPortalRepositoryResolvesTheInstalledSchema()

	/**
	 * A login lookup queries OpenRegister in the installed account schema.
	 *
	 * @return void
	 */
	public function testAccountLookupQueriesTheInstalledAccountSchema(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->once())
			->method('findAll')
			->with(
				$this->callback(
					static fn (array $config): bool => ($config['filters']['register'] ?? null) === InstalledAppConfig::PORTAL_REGISTER
						&& ($config['filters']['schema'] ?? null) === InstalledAppConfig::schemaId(slug: 'crmPortalAccount')
						&& ($config['filters']['email'] ?? null) === 'resident@example.org'
				)
			)
			->willReturn([['email' => 'resident@example.org', '@self' => ['id' => 'acc-1']]]);

		$repository = new PortalObjectRepository(
			InstalledAppConfig::wire($this->createMock(IAppConfig::class)),
			$this->createMock(LoggerInterface::class),
			$objects
		);

		$found = $repository->findAll(schemaSlug: 'crmPortalAccount', filters: ['email' => 'resident@example.org']);
		$this->assertCount(1, $found);
	}//end testAccountLookupQueriesTheInstalledAccountSchema()

	/**
	 * Every main-register schema the portal reads is configured and queried
	 * under the id the install stored.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return void
	 */
	#[DataProvider('mainSlugs')]
	public function testMainRegisterReaderQueriesTheInstalledSchema(string $slug): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->once())
			->method('findAll')
			->with(
				$this->callback(
					static fn (array $config): bool => ($config['filters']['register'] ?? null) === InstalledAppConfig::MAIN_REGISTER
						&& ($config['filters']['schema'] ?? null) === InstalledAppConfig::schemaId(slug: $slug)
				)
			)
			->willReturn([['@self' => ['id' => 'row-1']]]);

		$reader = new MainRegisterReader(
			InstalledAppConfig::wire($this->createMock(IAppConfig::class)),
			$this->createMock(LoggerInterface::class),
			$objects
		);

		$this->assertTrue($reader->hasSchema(schemaKey: $slug));
		$this->assertCount(1, $reader->findAll(schemaKey: $slug));
	}//end testMainRegisterReaderQueriesTheInstalledSchema()

	/**
	 * A schema the install does not write stays unconfigured: the reader
	 * must not report a slug as configured just because some key exists.
	 *
	 * @return void
	 */
	public function testSchemaTheInstallDoesNotWriteIsUnconfigured(): void {
		$reader = new MainRegisterReader(
			InstalledAppConfig::wire($this->createMock(IAppConfig::class)),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ObjectServiceInterface::class)
		);

		$this->assertFalse($reader->hasSchema(schemaKey: 'noSuchSchema'));
	}//end testSchemaTheInstallDoesNotWriteIsUnconfigured()
}//end class
