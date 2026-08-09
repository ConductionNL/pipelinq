<?php

/**
 * Pipelinq PresenceSubscribingInterface.
 *
 * Optional CTI capability: platforms whose presence events must be SUBSCRIBED
 * to before they are delivered.
 *
 * This is deliberately separate from CtiAdapterInterface. Presence reaches
 * pipelinq through CtiService::handleWebhook() -> syncPresenceFromEvent(), and
 * two of the three platforms need nothing to make that happen: Asterisk pushes
 * device-state events over the existing Stasis webhook stream once the
 * application is registered, and CallVoip posts presence with its other
 * webhooks. Only RingCentral requires an explicit POST to
 * /restapi/v1.0/subscription naming the extension's presence event filter.
 *
 * Declaring the method on the shared interface therefore forced two adapters to
 * carry an empty body that accepted a user id and ignored it — which is both
 * the shape hydra gate-3 reports as an unfinished stub (ADR-021) and, read
 * plainly, a contract that says every platform can do something two of them
 * cannot. Callers ask `instanceof` instead.
 *
 * NOT YET WIRED. Nothing calls subscribeToPresence() today, so for RingCentral
 * the presence pipeline is unreachable: syncPresenceFromEvent() can only fire
 * for events the platform never sends, because no subscription is ever created.
 * Wiring it needs a subscription-lifecycle decision (when to subscribe, how to
 * renew before expiry, how to avoid creating a duplicate subscription on every
 * call) that is not a mechanical change and is not made here.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/cti-screenpop-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti;

/**
 * Implemented by CTI adapters whose platform requires an explicit presence
 * subscription before it will deliver presence events.
 */
interface PresenceSubscribingInterface
{
    /**
     * Subscribe to presence updates for the given user / extension.
     *
     * @param string $userId    NC user UID.
     * @param string $extension Agent extension.
     *
     * @return void
     */
    public function subscribeToPresence(string $userId, string $extension): void;
}//end interface
