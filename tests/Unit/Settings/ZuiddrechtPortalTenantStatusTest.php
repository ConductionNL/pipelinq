<?php

/**
 * The Zuiddrecht set seeds two live portals and one draft.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Board PqBeheerPortaal lists three portals, two with a Live pill and one
 * with a Concept pill; every seeded status is one the schema allows.
 *
 * @spec openspec/changes/portal-tenants-on-one-admin-tab/tasks.md#t5
 */
class ZuiddrechtPortalTenantStatusTest extends TestCase {
	/**
	 * Two portals are live, one is a draft, and each status is in the schema's enum.
	 *
	 * @return void
	 */
	public function testTheSetSeedsTwoLivePortalsAndOneDraft(): void {
		$root     = __DIR__ . '/../../../lib/Settings';
		$fragment = json_decode(
			(string)file_get_contents($root . '/example-sets/zuiddrecht.d/64-admin-integrations.json'),
			true
		);
		$schema   = json_decode(
			(string)file_get_contents($root . '/register.d/99-zzzzz-portal-tenant-status.json'),
			true
		);
		$allowed  = $schema['components']['schemas']['portalTenantConfig']['properties']['status']['enum'];

		$statuses = [];
		foreach ($fragment['objects'] as $object) {
			if ($object['schema'] !== 'portalTenantConfig') {
				continue;
			}

			$statuses[$object['data']['tenantId']] = $object['data']['status'] ?? null;
		}

		self::assertSame(
			['default' => 'live', 'westervliet' => 'live', 'oosterhaven' => 'draft'],
			$statuses
		);
		foreach ($statuses as $status) {
			self::assertContains($status, $allowed);
		}
	}//end testTheSetSeedsTwoLivePortalsAndOneDraft()
}//end class
