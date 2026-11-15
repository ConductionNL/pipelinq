<?php

/**
 * Unit tests for PortalTenantService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCA\Pipelinq\Service\Portal\PortalSessionManager;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCA\Pipelinq\Util\ContrastRatioCalculator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tenant resolution, config reads and writes, feature flags and the
 * brand-colour contrast check.
 *
 * @covers \OCA\Pipelinq\Service\Portal\PortalTenantService
 * @uses   \OCA\Pipelinq\Util\ContrastRatioCalculator
 * @uses   \OCA\Pipelinq\Service\Portal\PortalException
 */
class PortalTenantServiceTest extends TestCase {
	/**
	 * Repository mock.
	 *
	 * @var PortalObjectRepository&MockObject
	 */
	private PortalObjectRepository&MockObject $repository;

	/**
	 * Service under test.
	 *
	 * @var PortalTenantService
	 */
	private PortalTenantService $service;

	/**
	 * Build the service around a mocked repository and a real calculator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->repository = $this->createMock(PortalObjectRepository::class);
		$this->service    = new PortalTenantService($this->repository, new ContrastRatioCalculator());
	}//end setUp()

	/**
	 * Make findOneBy answer from a map of "<key>=<value>" to a row.
	 *
	 * @param array<string, array<string, mixed>> $rows The rows by filter.
	 *
	 * @return void
	 */
	private function rows(array $rows): void {
		$this->repository->method('findOneBy')->willReturnCallback(
			static function (string $schema, array $filters) use ($rows): ?array {
				TestCase::assertSame('portalTenantConfig', $schema);
				$key = array_key_first($filters) . '=' . $filters[array_key_first($filters)];
				return ($rows[$key] ?? null);
			}
		);
	}//end rows()

	/**
	 * A custom domain match wins, lower-cased and trimmed.
	 *
	 * @return void
	 */
	public function testResolveTenantIdByCustomDomain(): void {
		$this->rows(['customDomain=portal.example.nl' => ['tenantId' => 'acme']]);

		$this->assertSame('acme', $this->service->resolveTenantId('  Portal.Example.NL ', 'widget'));
	}//end testResolveTenantIdByCustomDomain()

	/**
	 * A domain row without a tenantId falls back to the default tenant.
	 *
	 * @return void
	 */
	public function testResolveTenantIdDomainRowWithoutIdIsDefault(): void {
		$this->rows(['customDomain=portal.example.nl' => ['displayName' => 'x']]);

		$this->assertSame('default', $this->service->resolveTenantId('portal.example.nl', null));
	}//end testResolveTenantIdDomainRowWithoutIdIsDefault()

	/**
	 * Without a domain match the first label is tried as subdomain.
	 *
	 * @return void
	 */
	public function testResolveTenantIdBySubdomain(): void {
		$this->rows(['subdomain=gemeente' => ['tenantId' => 'gem']]);

		$this->assertSame('gem', $this->service->resolveTenantId('gemeente.portal.nl', 'widget'));
	}//end testResolveTenantIdBySubdomain()

	/**
	 * A single-label host has no subdomain lookup and falls to the widget tenant, trimmed.
	 *
	 * @return void
	 */
	public function testResolveTenantIdSingleLabelHostUsesWidgetTenant(): void {
		$this->repository->expects($this->once())->method('findOneBy')
			->with('portalTenantConfig', ['customDomain' => 'localhost'])
			->willReturn(null);

		$this->assertSame('widgetco', $this->service->resolveTenantId('localhost', '  widgetco '));
	}//end testResolveTenantIdSingleLabelHostUsesWidgetTenant()

	/**
	 * No host and a blank widget tenant give the default without any lookup.
	 *
	 * @return void
	 */
	public function testResolveTenantIdFallsBackToDefault(): void {
		$this->repository->expects($this->never())->method('findOneBy');

		$this->assertSame('default', $this->service->resolveTenantId(null, '   '));
		$this->assertSame('default', $this->service->resolveTenantId('', null));
	}//end testResolveTenantIdFallsBackToDefault()

	/**
	 * Admin config reports whether a stored row exists, else returns defaults.
	 *
	 * @return void
	 */
	public function testGetAdminConfig(): void {
		$this->rows(['tenantId=acme' => ['tenantId' => 'acme', 'displayName' => 'Acme']]);

		$this->assertSame(
			['config' => ['tenantId' => 'acme', 'displayName' => 'Acme'], 'configured' => true],
			$this->service->getAdminConfig('acme')
		);

		$fresh = $this->service->getAdminConfig('other');
		$this->assertFalse($fresh['configured']);
		$this->assertSame('other', $fresh['config']['tenantId']);
		$this->assertSame('Klantportaal', $fresh['config']['displayName']);
		$this->assertSame('#21468B', $fresh['config']['brandPrimaryColor']);
	}//end testGetAdminConfig()

	/**
	 * Public config keeps only whitelisted fields and forces the asked tenant id.
	 *
	 * @return void
	 */
	public function testGetPublicConfigFiltersPrivateFields(): void {
		$this->rows(
			[
				'tenantId=acme' => [
					'tenantId'         => 'stored',
					'displayName'      => 'Acme',
					'supportEmail'     => 'help@acme.test',
					'customDomain'     => 'secret.acme.test',
					'mfaEnforced'      => true,
					'widgetAllowedOrigins' => ['https://a.test'],
				],
			]
		);

		$this->assertSame(
			['tenantId' => 'acme', 'displayName' => 'Acme', 'supportEmail' => 'help@acme.test'],
			$this->service->getPublicConfig('acme')
		);
	}//end testGetPublicConfigFiltersPrivateFields()

	/**
	 * Public config of an unconfigured tenant is built from the defaults.
	 *
	 * @return void
	 */
	public function testGetPublicConfigUsesDefaults(): void {
		$this->rows([]);

		$public = $this->service->getPublicConfig('new');
		$this->assertSame('new', $public['tenantId']);
		$this->assertSame(['invoices', 'contracts', 'orders', 'requests', 'documents', 'profile'], $public['enabledFeatures']);
		$this->assertTrue($public['b2bEnabled']);
	}//end testGetPublicConfigUsesDefaults()

	/**
	 * Saving an existing tenant updates its row under the existing id.
	 *
	 * @return void
	 */
	public function testSaveConfigUpdatesExistingRow(): void {
		$existing = ['tenantId' => 'acme', '@self' => ['id' => 'row-1']];
		$this->rows(['tenantId=acme' => $existing]);
		$this->repository->expects($this->once())->method('idOf')->with($existing)->willReturn('row-1');
		$this->repository->expects($this->once())->method('save')
			->with('portalTenantConfig', ['displayName' => 'Acme', 'brandPrimaryColor' => '#21468B', 'tenantId' => 'acme'], 'row-1')
			->willReturn(['saved' => true]);

		$this->assertSame(
			['saved' => true],
			$this->service->saveConfig('acme', ['displayName' => 'Acme', 'brandPrimaryColor' => '#21468B', 'tenantId' => 'spoofed'])
		);
	}//end testSaveConfigUpdatesExistingRow()

	/**
	 * Saving a new tenant creates a row (null id) and skips contrast without a primary colour.
	 *
	 * @return void
	 */
	public function testSaveConfigCreatesNewRow(): void {
		$this->rows([]);
		$this->repository->expects($this->never())->method('idOf');
		$this->repository->expects($this->once())->method('save')
			->with('portalTenantConfig', ['brandPrimaryColor' => '', 'tenantId' => 'new'], null)
			->willReturn(['id' => 'n']);

		$this->assertSame(['id' => 'n'], $this->service->saveConfig('new', ['brandPrimaryColor' => '']));
	}//end testSaveConfigCreatesNewRow()

	/**
	 * A low-contrast colour pair is refused with 422 and the ratio in the context.
	 *
	 * @return void
	 */
	public function testSaveConfigRefusesLowContrast(): void {
		$this->repository->expects($this->never())->method('save');

		try {
			$this->service->saveConfig('acme', ['brandPrimaryColor' => '#FFFFFF', 'brandBackgroundColor' => '#FFFFFF']);
			$this->fail('Expected PortalException');
		} catch (PortalException $e) {
			$this->assertSame(422, $e->getStatus());
			$this->assertSame('contrastRatioBelowMinimum', $e->getErrorCode());
			$this->assertSame(['contrastRatio' => 1.0, 'requiredMinimum' => 4.5], $e->getContext());
			$this->assertStringContainsString('(1:1)', $e->getMessage());
		}
	}//end testSaveConfigRefusesLowContrast()

	/**
	 * A malformed colour is refused as invalidColor, checked against a white default background.
	 *
	 * @return void
	 */
	public function testSaveConfigRefusesInvalidColor(): void {
		$this->repository->expects($this->never())->method('save');

		try {
			$this->service->saveConfig('acme', ['brandPrimaryColor' => 'blue']);
			$this->fail('Expected PortalException');
		} catch (PortalException $e) {
			$this->assertSame(422, $e->getStatus());
			$this->assertSame('invalidColor', $e->getErrorCode());
		}
	}//end testSaveConfigRefusesInvalidColor()

	/**
	 * Configs default their status to live and are sorted by display name.
	 *
	 * @return void
	 */
	public function testListConfigsSortsAndDefaultsStatus(): void {
		$this->repository->expects($this->once())->method('findAll')->with('portalTenantConfig')->willReturn(
			[
				['displayName' => 'Zeta', 'status' => 'draft'],
				['displayName' => 'Alpha'],
				['tenantId' => 'nameless'],
			]
		);

		$this->assertSame(
			[
				['tenantId' => 'nameless', 'status' => 'live'],
				['displayName' => 'Alpha', 'status' => 'live'],
				['displayName' => 'Zeta', 'status' => 'draft'],
			],
			$this->service->listConfigs()
		);
	}//end testListConfigsSortsAndDefaultsStatus()

	/**
	 * Feature flags read the stored list strictly, or the defaults.
	 *
	 * @return void
	 */
	public function testIsFeatureEnabled(): void {
		$this->rows(
			[
				'tenantId=acme'  => ['enabledFeatures' => ['invoices']],
				'tenantId=broken' => ['enabledFeatures' => 'invoices'],
			]
		);

		$this->assertTrue($this->service->isFeatureEnabled('acme', 'invoices'));
		$this->assertFalse($this->service->isFeatureEnabled('acme', 'orders'));
		$this->assertFalse($this->service->isFeatureEnabled('broken', 'invoices'));
		$this->assertTrue($this->service->isFeatureEnabled('unconfigured', 'orders'));
		$this->assertFalse($this->service->isFeatureEnabled('unconfigured', 'webshop'));
	}//end testIsFeatureEnabled()

	/**
	 * requireFeature passes an enabled feature and refuses a disabled one with 404.
	 *
	 * @return void
	 */
	public function testRequireFeature(): void {
		$this->rows(['tenantId=acme' => ['enabledFeatures' => ['orders']]]);

		$this->service->requireFeature('acme', 'orders');

		try {
			$this->service->requireFeature('acme', 'invoices');
			$this->fail('Expected PortalException');
		} catch (PortalException $e) {
			$this->assertSame(404, $e->getStatus());
			$this->assertSame('featureNotEnabled', $e->getErrorCode());
			$this->assertSame('Deze functie is niet beschikbaar.', $e->getMessage());
		}
	}//end testRequireFeature()

	/**
	 * MFA enforcement and self-signup need a stored, strictly-true flag.
	 *
	 * @return void
	 */
	public function testStrictBooleanFlags(): void {
		$this->rows(
			[
				'tenantId=on'    => ['mfaEnforced' => true, 'selfSignupAllowed' => true],
				'tenantId=truthy' => ['mfaEnforced' => 1, 'selfSignupAllowed' => 'yes'],
			]
		);

		$this->assertTrue($this->service->mfaEnforced('on'));
		$this->assertTrue($this->service->isSelfSignupAllowed('on'));
		$this->assertFalse($this->service->mfaEnforced('truthy'));
		$this->assertFalse($this->service->isSelfSignupAllowed('truthy'));
		$this->assertFalse($this->service->mfaEnforced('missing'));
		$this->assertFalse($this->service->isSelfSignupAllowed('missing'));
	}//end testStrictBooleanFlags()

	/**
	 * Session TTL uses a positive stored value, else the session manager default.
	 *
	 * @return void
	 */
	public function testSessionTtlHours(): void {
		$this->rows(
			[
				'tenantId=long' => ['sessionTtlHours' => '24'],
				'tenantId=zero' => ['sessionTtlHours' => 0],
				'tenantId=neg'  => ['sessionTtlHours' => -3],
			]
		);

		$this->assertSame(24, $this->service->sessionTtlHours('long'));
		$this->assertSame(PortalSessionManager::DEFAULT_TTL_HOURS, $this->service->sessionTtlHours('zero'));
		$this->assertSame(PortalSessionManager::DEFAULT_TTL_HOURS, $this->service->sessionTtlHours('neg'));
		$this->assertSame(PortalSessionManager::DEFAULT_TTL_HOURS, $this->service->sessionTtlHours('missing'));
	}//end testSessionTtlHours()

	/**
	 * Widget origins must be enabled, given, and listed (trailing slashes ignored).
	 *
	 * @return void
	 */
	public function testIsWidgetOriginAllowed(): void {
		$this->rows(
			[
				'tenantId=on'  => ['widgetEmbedAllowed' => true, 'widgetAllowedOrigins' => ['https://a.test/', 'https://b.test']],
				'tenantId=off' => ['widgetEmbedAllowed' => false, 'widgetAllowedOrigins' => ['https://a.test']],
				'tenantId=bad' => ['widgetEmbedAllowed' => true, 'widgetAllowedOrigins' => 'https://a.test'],
			]
		);

		$this->assertTrue($this->service->isWidgetOriginAllowed('on', ' https://a.test '));
		$this->assertTrue($this->service->isWidgetOriginAllowed('on', 'https://b.test/'));
		$this->assertFalse($this->service->isWidgetOriginAllowed('on', 'https://c.test'));
		$this->assertFalse($this->service->isWidgetOriginAllowed('on', null));
		$this->assertFalse($this->service->isWidgetOriginAllowed('on', '  '));
		$this->assertFalse($this->service->isWidgetOriginAllowed('off', 'https://a.test'));
		$this->assertFalse($this->service->isWidgetOriginAllowed('bad', 'https://a.test'));
		$this->assertFalse($this->service->isWidgetOriginAllowed('missing', 'https://a.test'));
	}//end testIsWidgetOriginAllowed()
}//end class
