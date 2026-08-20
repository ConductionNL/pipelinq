<?php

/**
 * Unit tests for EmailMatchService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/email-calendar-sync/spec.md#requirement-emails-must-be-automatically-linked-to-crm-contacts
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\EmailMatchService;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for EmailMatchService.
 *
 * Focus: the pure-matching helpers (matchEmailToEntities,
 * matchDomainToOrganization, isPublicDomain) and the
 * settings/status persistence round-trip. Mail/DB interactions and
 * the OR leaf-link call are exercised separately in the integration
 * suite — these unit tests mock the OR ObjectService + container.
 */
class EmailMatchServiceTest extends TestCase {

	/**
	 * Build a service under test with a stubbed ObjectService.
	 *
	 * @param array<int,array<string,mixed>> $contacts Rows returned for `contact_schema` lookups.
	 * @param array<int,array<string,mixed>> $clients Rows returned for `client_schema` lookups.
	 *
	 * @return array{0: EmailMatchService, 1: IAppConfig}
	 */
	private function buildService(array $contacts = [], array $clients = []): array {
		$objectService = new class($contacts, $clients) {

			public function __construct(
				private array $contacts,
				private array $clients,
			) {
			}

			public function findAll(array $config): array {
				$schema = $config['filters']['schema'] ?? '';
				if ($schema === 'contact') {
					return $this->contacts;
				}

				if ($schema === 'client') {
					return $this->clients;
				}

				return [];
			}

		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $name) use ($objectService) {
				if ($name === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				if ($name === 'OCA\\OpenRegister\\Service\\EmailLinkService') {
					throw new RuntimeException('not used in this test');
				}

				throw new RuntimeException('unknown service: ' . $name);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$store = [];
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$store): string {
				$defaults = [
					'register' => 'pipelinq',
					'contact_schema' => 'contact',
					'client_schema' => 'client',
				];

				if (isset($store[$key]) === true) {
					return $store[$key];
				}

				return ($defaults[$key] ?? $default);
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$store): bool {
				$store[$key] = $value;
				return true;
			}
		);

		$service = new EmailMatchService(
			container: $container,
			appConfig: $appConfig,
			db: $this->createMock(IDBConnection::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		return [$service, $appConfig];
	}//end buildService()

	/**
	 * Exact address match returns the corresponding entity.
	 *
	 * @return void
	 */
	public function testMatchEmailToEntitiesReturnsExactMatch(): void {
		[$service] = $this->buildService(
			contacts: [
				['@self' => ['id' => 'c-1'], 'email' => 'jan@example.com'],
				['@self' => ['id' => 'c-2'], 'email' => 'piet@example.com'],
			]
		);

		$matches = $service->matchEmailToEntities(address: 'JAN@example.com');

		$this->assertSame(
			[['entityType' => 'contact', 'entityId' => 'c-1']],
			$matches
		);

	}//end testMatchEmailToEntitiesReturnsExactMatch()

	/**
	 * An unknown address yields no matches.
	 *
	 * @return void
	 */
	public function testMatchEmailToEntitiesEmptyOnUnknown(): void {
		[$service] = $this->buildService(
			contacts: [['@self' => ['id' => 'c-1'], 'email' => 'jan@example.com']]
		);

		$this->assertSame([], $service->matchEmailToEntities(address: 'unknown@example.com'));

	}//end testMatchEmailToEntitiesEmptyOnUnknown()

	/**
	 * Address-with-no-@ returns no matches (defensive).
	 *
	 * @return void
	 */
	public function testMatchEmailToEntitiesEmptyOnBlank(): void {
		[$service] = $this->buildService();

		$this->assertSame([], $service->matchEmailToEntities(address: ''));

	}//end testMatchEmailToEntitiesEmptyOnBlank()

	/**
	 * A corporate-domain match resolves to the organization client.
	 *
	 * @return void
	 */
	public function testMatchDomainToOrganizationReturnsOrg(): void {
		[$service] = $this->buildService(
			clients: [
				['@self' => ['id' => 'org-1'], 'type' => 'organization', 'email' => 'info@conduction.nl'],
				['@self' => ['id' => 'org-2'], 'type' => 'organization', 'email' => 'info@example.org'],
			]
		);

		$hit = $service->matchDomainToOrganization(domain: 'conduction.nl');

		$this->assertSame(['entityType' => 'client', 'entityId' => 'org-1'], $hit);

	}//end testMatchDomainToOrganizationReturnsOrg()

	/**
	 * Public domains (gmail, outlook, etc.) NEVER resolve via domain match.
	 *
	 * @return void
	 */
	public function testMatchDomainToOrganizationSkipsPublicDomains(): void {
		[$service] = $this->buildService(
			clients: [
				['@self' => ['id' => 'org-1'], 'type' => 'organization', 'email' => 'info@gmail.com'],
			]
		);

		$this->assertNull($service->matchDomainToOrganization(domain: 'gmail.com'));

	}//end testMatchDomainToOrganizationSkipsPublicDomains()

	/**
	 * isPublicDomain matches known providers (case-insensitive).
	 *
	 * @return void
	 */
	public function testIsPublicDomainTrueForGmail(): void {
		[$service] = $this->buildService();

		$this->assertTrue($service->isPublicDomain(domain: 'gmail.com'));
		$this->assertTrue($service->isPublicDomain(domain: 'OUTLOOK.COM'));
		$this->assertTrue($service->isPublicDomain(domain: 'yahoo.com'));
		$this->assertTrue($service->isPublicDomain(domain: 'icloud.com'));

	}//end testIsPublicDomainTrueForGmail()

	/**
	 * isPublicDomain returns false for corporate / non-listed domains.
	 *
	 * @return void
	 */
	public function testIsPublicDomainFalseForCorporate(): void {
		[$service] = $this->buildService();

		$this->assertFalse($service->isPublicDomain(domain: 'conduction.nl'));
		$this->assertFalse($service->isPublicDomain(domain: 'example.com'));
		$this->assertFalse($service->isPublicDomain(domain: ''));

	}//end testIsPublicDomainFalseForCorporate()

	/**
	 * Settings round-trip preserves account + enabled + excludedAddresses.
	 *
	 * @return void
	 */
	public function testSettingsRoundTrip(): void {
		[$service] = $this->buildService();

		$service->writeSettings(
			userId: 'alice',
			settings: [
				'account' => 7,
				'enabled' => true,
				'excludedAddresses' => ['noreply@example.org', 'NOT-AN-EMAIL', 'mailer@example.org'],
				'cursor' => 42,
			]
		);

		$read = $service->getSettings(userId: 'alice');

		$this->assertSame(7, $read['account']);
		$this->assertTrue($read['enabled']);
		$this->assertSame(
			['noreply@example.org', 'mailer@example.org'],
			$read['excludedAddresses']
		);
		$this->assertSame(42, $read['cursor']);

	}//end testSettingsRoundTrip()

	/**
	 * Status round-trip preserves the last-run summary.
	 *
	 * @return void
	 */
	public function testStatusRoundTrip(): void {
		[$service] = $this->buildService();

		$service->writeStatus(userId: 'alice', linked: 5, scanned: 17, error: null);

		$status = $service->getStatus(userId: 'alice');

		$this->assertSame(5, $status['linked']);
		$this->assertSame(17, $status['scanned']);
		$this->assertNull($status['error']);
		$this->assertNotNull($status['lastRunAt']);

	}//end testStatusRoundTrip()

	/**
	 * runForUser is a no-op when sync is disabled or no account is set.
	 *
	 * @return void
	 */
	public function testRunForUserNoopWhenDisabled(): void {
		[$service] = $this->buildService();

		$service->writeSettings(
			userId: 'bob',
			settings: ['account' => 0, 'enabled' => true, 'excludedAddresses' => [], 'cursor' => 0]
		);

		$result = $service->runForUser(userId: 'bob');

		$this->assertSame(['linked' => 0, 'scanned' => 0], $result);

	}//end testRunForUserNoopWhenDisabled()

}//end class
