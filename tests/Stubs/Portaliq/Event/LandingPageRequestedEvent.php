<?php

/**
 * Test stub for OCA\Portaliq\Event\LandingPageRequestedEvent.
 *
 * Mirrors the real class (portaliq `lib/Event/LandingPageRequestedEvent.php`,
 * merged into portaliq `development` on 2026-09-04): the same positional
 * constructor and the same six result slots.
 *
 * Declaration only. It is scanned by psalm and phpstan so the landing-page
 * hand-off type-checks against the real shape instead of against
 * suppressions, and it is never autoloaded at runtime: this app's PSR-4 map
 * covers `OCA\Pipelinq\` only, and the `class_exists()` guard yields to
 * portaliq's own class the moment portaliq is installed. Same pattern as the
 * OpenRegister stubs beside it.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Portaliq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Portaliq\Event;

use OCP\EventDispatcher\Event;

if (class_exists(LandingPageRequestedEvent::class) === false) {
	/**
	 * Stub for portaliq's landing-page request event.
	 */
	class LandingPageRequestedEvent extends Event {

		/**
		 * The created page's id (result slot).
		 *
		 * @var string|null
		 */
		private ?string $pageId = null;

		/**
		 * The created form's id (result slot).
		 *
		 * @var string|null
		 */
		private ?string $formId = null;

		/**
		 * The page's public URL (result slot).
		 *
		 * @var string|null
		 */
		private ?string $publicUrl = null;

		/**
		 * The machine-readable failure code (result slot).
		 *
		 * @var string|null
		 */
		private ?string $error = null;

		/**
		 * Whether portaliq's listener handled the request (result slot).
		 *
		 * @var boolean
		 */
		private bool $handled = false;

		/**
		 * Construct the request event.
		 *
		 * @param string $sourceApp The contributing app.
		 * @param string $portal The target portal's slug.
		 * @param string $route The desired in-portal route.
		 * @param string $title The page title.
		 * @param string $locale The page's content locale.
		 * @param array<string, mixed> $article `{summary, body, heroImageRef, links}`.
		 * @param array<string, mixed> $form `{fields, submitLabel, consentText}`.
		 * @param array<string, mixed> $utm `{campaign, source, medium}`.
		 * @param string $externalReference The requester's own reference.
		 * @param string $correlationId Correlation id for tracing.
		 *
		 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Mirrors a published cross-app contract.
		 */
		public function __construct(
			private readonly string $sourceApp,
			private readonly string $portal,
			private readonly string $route,
			private readonly string $title,
			private readonly string $locale,
			private readonly array $article,
			private readonly array $form,
			private readonly array $utm = [],
			private readonly string $externalReference = '',
			private readonly string $correlationId = '',
		) {
			parent::__construct();
		}//end __construct()

		/**
		 * The contributing app that requested the page.
		 *
		 * @return string The app id.
		 */
		public function getSourceApp(): string {
			return $this->sourceApp;
		}//end getSourceApp()

		/**
		 * The target portal's slug.
		 *
		 * @return string The slug.
		 */
		public function getPortal(): string {
			return $this->portal;
		}//end getPortal()

		/**
		 * The requested in-portal route.
		 *
		 * @return string The route.
		 */
		public function getRoute(): string {
			return $this->route;
		}//end getRoute()

		/**
		 * The requested page title.
		 *
		 * @return string The title.
		 */
		public function getTitle(): string {
			return $this->title;
		}//end getTitle()

		/**
		 * The requested content locale.
		 *
		 * @return string The locale.
		 */
		public function getLocale(): string {
			return $this->locale;
		}//end getLocale()

		/**
		 * The article payload.
		 *
		 * @return array<string, mixed> The payload.
		 */
		public function getArticle(): array {
			return $this->article;
		}//end getArticle()

		/**
		 * The form payload.
		 *
		 * @return array<string, mixed> The payload.
		 */
		public function getForm(): array {
			return $this->form;
		}//end getForm()

		/**
		 * The requested UTM block.
		 *
		 * @return array<string, mixed> The block.
		 */
		public function getUtm(): array {
			return $this->utm;
		}//end getUtm()

		/**
		 * The requester's own external reference.
		 *
		 * @return string The reference.
		 */
		public function getExternalReference(): string {
			return $this->externalReference;
		}//end getExternalReference()

		/**
		 * The correlation id.
		 *
		 * @return string The id.
		 */
		public function getCorrelationId(): string {
			return $this->correlationId;
		}//end getCorrelationId()

		/**
		 * The created page's id.
		 *
		 * @return string|null The id, or null.
		 */
		public function getPageId(): ?string {
			return $this->pageId;
		}//end getPageId()

		/**
		 * Record the created page's id.
		 *
		 * @param string $pageId The id.
		 *
		 * @return void
		 */
		public function setPageId(string $pageId): void {
			$this->pageId = $pageId;
		}//end setPageId()

		/**
		 * The created form's id.
		 *
		 * @return string|null The id, or null.
		 */
		public function getFormId(): ?string {
			return $this->formId;
		}//end getFormId()

		/**
		 * Record the created form's id.
		 *
		 * @param string $formId The id.
		 *
		 * @return void
		 */
		public function setFormId(string $formId): void {
			$this->formId = $formId;
		}//end setFormId()

		/**
		 * The page's public URL.
		 *
		 * @return string|null The URL, or null.
		 */
		public function getPublicUrl(): ?string {
			return $this->publicUrl;
		}//end getPublicUrl()

		/**
		 * Record the page's public URL.
		 *
		 * @param string|null $publicUrl The URL, or null.
		 *
		 * @return void
		 */
		public function setPublicUrl(?string $publicUrl): void {
			$this->publicUrl = $publicUrl;
		}//end setPublicUrl()

		/**
		 * The machine-readable failure code.
		 *
		 * @return string|null The code, or null on success.
		 */
		public function getError(): ?string {
			return $this->error;
		}//end getError()

		/**
		 * Record the machine-readable failure code.
		 *
		 * @param string|null $error The code, or null.
		 *
		 * @return void
		 */
		public function setError(?string $error): void {
			$this->error = $error;
		}//end setError()

		/**
		 * Whether portaliq's listener handled the request.
		 *
		 * @return bool True once it has run.
		 */
		public function isHandled(): bool {
			return $this->handled;
		}//end isHandled()

		/**
		 * Mark whether portaliq's listener handled the request.
		 *
		 * @param bool $handled True once it has run.
		 *
		 * @return void
		 */
		public function setHandled(bool $handled): void {
			$this->handled = $handled;
		}//end setHandled()
	}//end class
}//end if
