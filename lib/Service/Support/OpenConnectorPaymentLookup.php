<?php

/**
 * Pipelinq OpenConnectorPaymentLookup.
 *
 * Looks up the connector PaymentService for appointment deposits.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Support
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Support;

use Throwable;

/**
 * Resolves the connector's PaymentService from the host's `$container`, or
 * null when it is not bound.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait OpenConnectorPaymentLookup {

	/**
	 * The connector PaymentService from the DI container, or null when it is absent.
	 *
	 * @return object|null
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function connectorPaymentService(): ?object {
		try {
			// NOT REPOINTED, AND NOT REPOINTABLE (verified 2026-09-10). The name
			// `openconnector` is retired and its successor is `integriq`, but
			// `OCA\Integriq\Service\PaymentService` does not exist, and neither did
			// the old spelling: `git log -S PaymentService` over integriq's 3,960
			// commits, which span the whole openconnector era because the repo was
			// renamed rather than forked, returns nothing at all. The connector has
			// never published a payment service under either name.
			//
			// Swapping the namespace here would produce a `get()` that misses
			// exactly as it misses now, lands in the same catch below, and returns
			// the same null, on a diff that reads to the next person as a fix
			// already applied. That is worse than the stale name, which at least
			// still says out loud that the integration is unfinished.
			//
			// @stale-fleet-app-id exclude integriq publishes no PaymentService under
			// either name; git log -S over its full 3,960-commit history, which
			// covers the openconnector era, finds the class was never added. The
			// deposit flow needs an integriq-side payment surface before any name
			// written here resolves.
			$service = $this->container->get('OCA\\OpenConnector\\Service\\PaymentService');
			if (is_object($service) === true) {
				return $service;
			}
		} catch (Throwable $e) {
			// Openconnector not installed / class not bound; soft skip.
		}

		return null;
	}//end connectorPaymentService()
}//end trait
