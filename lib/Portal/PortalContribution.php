<?php

/**
 * Pipelinq Portal Contribution
 *
 * The portal contribution as the other components of pipelinq read it. The
 * provider portaliq discovers stays plain and duck-typed, so this class
 * carries the contract for in-app callers and hands every call to it.
 *
 * @category  Portal
 * @package   OCA\Pipelinq\Portal
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Portal;

use OCA\Pipelinq\Kernel\Contracts\PortalContributionInterface;

/**
 * Pipelinq's portal contribution behind its in-app contract.
 *
 * @category Portal
 * @package  OCA\Pipelinq\Portal
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://pipelinq.nl
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
final class PortalContribution implements PortalContributionInterface {
	/**
	 * Constructor.
	 *
	 * @param PortalContributionProvider $provider The provider portaliq discovers.
	 */
	public function __construct(
		private readonly PortalContributionProvider $provider,
	) {
	}//end __construct()

	/**
	 * Build the declarative portal manifest for one resolved subject.
	 *
	 * @param array<string, mixed> $subject The resolved portal subject.
	 *
	 * @return array<string, mixed>|null The manifest, or null when not contributing.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function getContribution(array $subject): ?array {
		return $this->provider->getContribution(subject: $subject);
	}//end getContribution()
}//end class
