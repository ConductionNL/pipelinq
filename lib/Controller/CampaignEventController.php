<?php

/**
 * Pipelinq CampaignEventController.
 *
 * The campaign page's Create event: a marketer who can read the campaign
 * creates its event from the dialog. The event is written as the caller, so
 * OpenRegister's access rules decide whether they may; a campaign the caller
 * cannot read does not exist for them.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
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
 *
 * @spec openspec/specs/marketing-events-and-attendees/spec.md#requirement-a-campaign-can-have-an-event-req-mev-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\Event\CampaignEventCreator;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Create a campaign's event.
 *
 * @spec openspec/specs/marketing-events-and-attendees/spec.md#requirement-a-campaign-can-have-an-event-req-mev-001
 */
class CampaignEventController extends Controller
{

    /**
     * The dialog fields an event is made from.
     *
     * @var list<string>
     */
    private const FIELDS = ['title', 'startAt', 'endAt', 'online', 'venue', 'joinUrl', 'capacity', 'openNow'];

    /**
     * Constructor.
     *
     * @param IRequest             $request     The request.
     * @param CampaignEventCreator $creator     Checks and writes the event.
     * @param IUserSession         $userSession The caller.
     * @param LoggerInterface      $logger      The logger.
     *
     * @spec openspec/specs/marketing-events-and-attendees/spec.md#requirement-a-campaign-can-have-an-event-req-mev-001
     */
    public function __construct(
        IRequest $request,
        private readonly CampaignEventCreator $creator,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: AppIdentity::APP_ID, request: $request);

    }//end __construct()

    /**
     * Create the event of a campaign.
     *
     * @param string $id The campaign id.
     *
     * @return JSONResponse 201 with the event; 400 for invalid input or a refused write,
     *                      401 signed out, 404 when the caller cannot read the campaign.
     *
     * @spec openspec/specs/marketing-events-and-attendees/spec.md#requirement-a-campaign-can-have-an-event-req-mev-001
     */
    #[NoAdminRequired]
    public function create(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->creator->campaignExists(campaignId: $id) === false) {
            return new JSONResponse(['message' => 'Campaign not found'], Http::STATUS_NOT_FOUND);
        }

        $input = [];
        foreach (self::FIELDS as $field) {
            $input[$field] = $this->request->getParam($field);
        }

        try {
            $event = $this->creator->create(campaignId: $id, input: array_filter($input, static fn (mixed $value): bool => $value !== null));
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Pipelinq: the campaign event could not be created',
                ['app' => AppIdentity::APP_ID, 'campaign' => $id, 'exception' => $e->getMessage()]
            );
            return new JSONResponse(['message' => 'The event could not be created'], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($event, Http::STATUS_CREATED);

    }//end create()
}//end class
