<?php

/**
 * Pipelinq ListPublicController.
 *
 * The four doors a mailing-list subscriber uses, none of which they can
 * reach with a Nextcloud session: subscribe, confirm, unsubscribe and the
 * preference centre. Every one is `#[PublicPage]` because the caller is a
 * mail client or a browser following a link out of an email, and every one
 * carries the full ADR-082 pair, because either half alone is inert:
 * `#[AnonRateLimit]` caps the volume and `IThrottler::registerAttempt()`
 * counts each rejected token.
 *
 * These endpoints stay on pipelinq rather than moving to portaliq under
 * ADR-108. They are the other half of that ADR's split: the URL is printed
 * into a mail that may be years old and is named by an RFC 8058
 * `List-Unsubscribe` header a receiving provider reads, and when a
 * counterparty's configuration names the URL, the URL is not ours to move.
 *
 * Every refusal answers the same way. A list that does not exist, a list
 * closed to public signup, an address already on the list and a token that
 * does not verify are indistinguishable from outside, so none of them can
 * be used to find out whether a person is on a list. The server-side log
 * carries what the caller is not told.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-public-list-endpoints-are-throttled-and-fail-closed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Marketing\PreferenceCentreService;
use OCA\Pipelinq\Service\SubscriptionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for /api/lists/* — the subscriber-facing endpoints.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One membership service plus
 *  the throttler, the localiser and the logger the public surface needs; the
 *  four endpoints share all of them.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-public-list-endpoints-are-throttled-and-fail-closed
 */
class ListPublicController extends Controller {
	/**
	 * Brute-force throttler action for rejected list tokens.
	 *
	 * @var string
	 */
	private const THROTTLE_ACTION = 'pipelinq_mailing_list_token';

	/**
	 * The one answer every refusal gives. Naming the list, the address or
	 * the reason would turn these endpoints into an oracle for whether a
	 * given person is on a given list.
	 *
	 * @var string
	 */
	private const GENERIC_REFUSAL = 'This link is no longer valid';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param SubscriptionService $subscriptions Membership service.
	 * @param PreferenceCentreService $preferences The preference centre.
	 * @param IThrottler $throttler Brute-force throttler for rejected tokens.
	 * @param IL10N $l10n Localisation for the rendered pages.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private SubscriptionService $subscriptions,
		private PreferenceCentreService $preferences,
		private IThrottler $throttler,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * POST /api/lists/{id}/subscribe — public signup.
	 *
	 * Answers 202 whether the address was new, already pending or already
	 * confirmed, and answers 202 to a filled honeypot too. Only an unusable
	 * list (404) and a malformed address (400) answer differently, and
	 * neither reveals anything about a person.
	 *
	 * @param string $id MailingList UUID or slug.
	 * @param string $email The address that wants to subscribe.
	 * @param string $website The honeypot field. A real person leaves it
	 *                        empty; it is named for what a form-filling bot
	 *                        expects to see rather than for what it is.
	 *
	 * @return JSONResponse 202, 400 or 404.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 300)]
	public function subscribe(string $id, string $email = '', string $website = ''): JSONResponse {
		try {
			$result = $this->subscriptions->subscribe(
				listId: $id,
				email: $email,
				honeypot: $website,
			);
		} catch (Throwable $e) {
			$this->logger->warning('ListPublicController.subscribe: failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => self::GENERIC_REFUSAL], Http::STATUS_NOT_FOUND);
		}

		if ($result['status'] === 'not-found') {
			return new JSONResponse(['error' => self::GENERIC_REFUSAL], Http::STATUS_NOT_FOUND);
		}

		if ($result['status'] === 'invalid') {
			return new JSONResponse(
				['error' => $this->l10n->t('That does not look like an email address')],
				Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(
			['message' => $this->l10n->t('Check your inbox. We sent you a link to confirm.')],
			Http::STATUS_ACCEPTED
		);
	}//end subscribe()

	/**
	 * GET /api/lists/confirm/{token} — spend a confirmation link.
	 *
	 * @param string $token The signed confirmation token.
	 *
	 * @return DataDisplayResponse The confirmation page, or 410 on a token
	 *                             that does not verify.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-confirmation-token-is-verified-before-a-subscription-is-confirmed
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 300)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function confirm(string $token): DataDisplayResponse {
		$result = ['status' => 'invalid'];
		try {
			$result = $this->subscriptions->confirm(
				token: $token,
				ipAddress: $this->request->getRemoteAddress(),
			);
		} catch (Throwable $e) {
			$this->logger->warning('ListPublicController.confirm: failed', ['exception' => $e->getMessage()]);
		}

		if ($result['status'] !== 'confirmed') {
			$this->registerRejectedToken();
			return $this->gonePage();
		}

		$listName = (string)(($result['list']['name'] ?? ''));
		return $this->page(
			title: $this->l10n->t('You are subscribed'),
			heading: $this->l10n->t('You are subscribed'),
			body: $this->l10n->t('You will receive %s from now on. Every message carries a link to stop.', [$listName]),
			form: '',
		);
	}//end confirm()

	/**
	 * GET /api/lists/unsubscribe/{token} — the unsubscribe page.
	 *
	 * Renders and changes nothing. The button posts back to the same URL,
	 * as a plain form, so the page works with scripting off.
	 *
	 * @param string $token The signed unsubscribe token.
	 *
	 * @return DataDisplayResponse The page, or 410 on a token that does not
	 *                             verify.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-unsubscribe-is-first-party-and-takes-one-click
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 300)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function unsubscribePage(string $token): DataDisplayResponse {
		$peek = null;
		try {
			$peek = $this->subscriptions->peekUnsubscribeToken(token: $token);
		} catch (Throwable $e) {
			$this->logger->warning('ListPublicController.unsubscribePage: failed', ['exception' => $e->getMessage()]);
		}

		if ($peek === null) {
			$this->registerRejectedToken();
			return $this->gonePage();
		}

		$listName = (string)($peek['list']['name'] ?? '');
		$action = $this->escape(value: $this->request->getRequestUri());
		$form = '<form method="post" action="' . $action . '">'
			. '<button type="submit" name="confirmed" value="1">'
			. $this->escape(value: $this->l10n->t('Unsubscribe me'))
			. '</button>'
			. '<label><input type="checkbox" name="global" value="1"> '
			. $this->escape(value: $this->l10n->t('Also stop every other list from this sender'))
			. '</label>'
			. '</form>';

		return $this->page(
			title: $this->l10n->t('Unsubscribe'),
			heading: $this->l10n->t('Unsubscribe from %s', [$listName]),
			body: $this->l10n->t('You are about to stop receiving this list. Nothing has changed yet.'),
			form: $form,
		);
	}//end unsubscribePage()

	/**
	 * POST /api/lists/unsubscribe/{token} — the one-click unsubscribe.
	 *
	 * Answers 200 without a redirect, which is what RFC 8058 requires of the
	 * URL a `List-Unsubscribe-Post` header names.
	 *
	 * @param string $token The signed unsubscribe token.
	 * @param string $global Set to leave every list from this sender.
	 * @param string $reason What the subscriber gave as the reason.
	 *
	 * @return JSONResponse 200 on success, 410 otherwise.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-unsubscribe-is-first-party-and-takes-one-click
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 300)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function unsubscribe(string $token, string $global = '', string $reason = ''): JSONResponse {
		$result = ['status' => 'invalid', 'count' => 0];
		try {
			$result = $this->subscriptions->unsubscribeByToken(
				token: $token,
				global: ($global !== '' && $global !== '0'),
				reason: $reason,
			);
		} catch (Throwable $e) {
			$this->logger->warning('ListPublicController.unsubscribe: failed', ['exception' => $e->getMessage()]);
		}

		if ($result['status'] !== 'unsubscribed') {
			$this->registerRejectedToken();
			return new JSONResponse(['error' => self::GENERIC_REFUSAL], Http::STATUS_GONE);
		}

		return new JSONResponse(
			[
				'message' => $this->l10n->t('You are unsubscribed. You will not hear from this list again.'),
				'count' => $result['count'],
			],
			Http::STATUS_OK
		);
	}//end unsubscribe()

	/**
	 * GET /api/lists/preferences/{token} — the preference centre.
	 *
	 * @param string $token The signed preferences token.
	 *
	 * @return JSONResponse The lists this contact may hold, each with its
	 *                      current state, or 410 on an unusable token.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 300)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function preferences(string $token): JSONResponse {
		$rows = null;
		try {
			$rows = $this->preferences->preferencesForToken(token: $token);
		} catch (Throwable $e) {
			$this->logger->warning('ListPublicController.preferences: failed', ['exception' => $e->getMessage()]);
		}

		if ($rows === null) {
			$this->registerRejectedToken();
			return new JSONResponse(['error' => self::GENERIC_REFUSAL], Http::STATUS_GONE);
		}

		return new JSONResponse(['lists' => $rows]);
	}//end preferences()

	/**
	 * POST /api/lists/preferences/{token} — save a preference choice.
	 *
	 * @param string $token The signed preferences token.
	 * @param array<int, string> $lists The list ids that were ticked.
	 *
	 * @return JSONResponse 200 with what changed, or 410.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 300)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function savePreferences(string $token, array $lists = []): JSONResponse {
		$result = ['status' => 'invalid', 'confirmed' => 0, 'unsubscribed' => 0];
		try {
			$result = $this->preferences->savePreferences(token: $token, selectedListIds: $lists);
		} catch (Throwable $e) {
			$this->logger->warning('ListPublicController.savePreferences: failed', ['exception' => $e->getMessage()]);
		}

		if ($result['status'] !== 'saved') {
			$this->registerRejectedToken();
			return new JSONResponse(['error' => self::GENERIC_REFUSAL], Http::STATUS_GONE);
		}

		return new JSONResponse(
			[
				'message' => $this->l10n->t('Your choices are saved.'),
				'confirmed' => $result['confirmed'],
				'unsubscribed' => $result['unsubscribed'],
			],
			Http::STATUS_OK
		);
	}//end savePreferences()

	/**
	 * Count a rejected token with the brute-force throttler.
	 *
	 * The half that COUNTS; `#[BruteForceProtection]` is the half that
	 * ENFORCES. Either alone is inert — see ADR-082.
	 *
	 * @return void
	 */
	private function registerRejectedToken(): void {
		try {
			$this->throttler->registerAttempt(
				action: self::THROTTLE_ACTION,
				ip: $this->request->getRemoteAddress()
			);
		} catch (Throwable $throttlerFailure) {
			$this->logger->warning(
				'ListPublicController: registerAttempt failed: ' . $throttlerFailure->getMessage()
			);
		}
	}//end registerRejectedToken()

	/**
	 * Render the shared refusal page.
	 *
	 * @return DataDisplayResponse A 410 page.
	 */
	private function gonePage(): DataDisplayResponse {
		return $this->page(
			title: $this->l10n->t('Link expired'),
			heading: $this->l10n->t('This link no longer works'),
			body: $this->l10n->t('It may have been used already, or it may be too old. Ask for a new one from any message you received.'),
			form: '',
			status: Http::STATUS_GONE,
		);
	}//end gonePage()

	/**
	 * Build one of the three subscriber-facing pages.
	 *
	 * Rendered here rather than through a Nextcloud template because the
	 * reader has never seen this instance: a template would pull in the page
	 * shell, the theme and a login-aware header for one sentence and one
	 * button. Every interpolated value is escaped, the document declares its
	 * language, and it carries no script.
	 *
	 * @param string $title The document title.
	 * @param string $heading The visible heading.
	 * @param string $body One sentence of explanation.
	 * @param string $form Pre-escaped markup for the action, or an empty
	 *                     string for a page with nothing to do.
	 * @param int $status HTTP status.
	 *
	 * @phpstan-param Http::STATUS_OK|Http::STATUS_GONE $status
	 *
	 * @return DataDisplayResponse The rendered page.
	 */
	private function page(
		string $title,
		string $heading,
		string $body,
		string $form,
		int $status = Http::STATUS_OK,
	): DataDisplayResponse {
		$html = '<!DOCTYPE html><html lang="' . $this->escape(value: $this->l10n->getLanguageCode()) . '">'
			. '<head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex, nofollow">'
			. '<title>' . $this->escape(value: $title) . '</title>'
			. '<style>body{font-family:system-ui,sans-serif;margin:0;padding:3rem 1.5rem;max-width:34rem}'
			. 'h1{font-size:1.4rem}button{font:inherit;padding:.6rem 1.2rem;margin:1rem 0}'
			. 'label{display:block;font-size:.9rem}</style>'
			. '</head><body>'
			. '<h1>' . $this->escape(value: $heading) . '</h1>'
			. '<p>' . $this->escape(value: $body) . '</p>'
			. $form
			. '</body></html>';

		return new DataDisplayResponse(
			$html,
			$status,
			[
				'Content-Type' => 'text/html; charset=UTF-8',
				'Cache-Control' => 'no-store, no-cache, must-revalidate',
				'X-Robots-Tag' => 'noindex, nofollow',
			]
		);
	}//end page()

	/**
	 * Escape a value for HTML text and attribute context.
	 *
	 * @param string $value The raw value.
	 *
	 * @return string The escaped value.
	 */
	private function escape(string $value): string {
		return htmlspecialchars($value, (ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5), 'UTF-8');
	}//end escape()
}//end class
