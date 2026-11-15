<?php

/**
 * The platform properties of pipelinq, asserted from its own files.
 *
 * @category Tests
 * @package  OCA\Pipelinq\Tests\Unit\Platform
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
 *
 * @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Licence, required apps, the absence of seat checks and the access posture of every route.
 *
 * Reads files only; executes no app code.
 *
 * @coversNothing
 */
class AppInfoPropertiesTest extends TestCase {

	/**
	 * Every route reachable without a Nextcloud session, with the check it relies on instead.
	 *
	 * A new public route fails the test until it is added here with its check (REQ-PLAT-005).
	 *
	 * @var array<string, string>
	 */
	private const PUBLIC_ROUTES = [
		'PublicSurveyController::showInvitation' => 'the per-invitation survey token',
		'PublicSurveyController::submitInvitation' => 'the per-invitation survey token',
		'CtiController::webhook' => 'the telephony platform\'s webhook signature, checked by CtiService::handleWebhook',
		'HealthController::index' => 'none: returns only the app\'s health status, no record data',
		'WebFormController::submitBySource' => 'the registered form key, rate-limited per anonymous caller (AnonRateLimit)',
		'WebFormController::submit' => 'the registered form key, rate-limited per anonymous caller (AnonRateLimit)',
		'PosPaymentController::webhook' => 'the payment terminal provider\'s webhook signature',
		'PortalTenantController::config' => 'the portal tenant (requireTenant); returns its public configuration only',
		'PortalAuthController::login' => 'the portal tenant and credentials, then the portal session token (requireTenant, requireSession)',
		'PortalAuthController::logout' => 'the portal tenant and credentials, then the portal session token (requireTenant, requireSession)',
		'PortalAuthController::extendSession' => 'the portal tenant and credentials, then the portal session token (requireTenant, requireSession)',
		'PortalAuthController::passwordResetRequest' => 'the portal tenant and credentials, then the portal session token (requireTenant, requireSession)',
		'PortalAuthController::passwordReset' => 'the portal tenant and credentials, then the portal session token (requireTenant, requireSession)',
		'PortalAuthController::mfaEnroll' => 'the portal tenant and credentials, then the portal session token (requireTenant, requireSession)',
		'PortalAuthController::mfaVerify' => 'the portal tenant and credentials, then the portal session token (requireTenant, requireSession)',
		'PortalAccountController::profile' => 'the portal session token (requireSession)',
		'PortalAccountController::updateProfile' => 'the portal session token (requireSession)',
		'PortalAccountController::verifyEmail' => 'the portal session token (requireSession)',
		'PortalAccountController::requestExport' => 'the portal session token (requireSession)',
		'PortalAccountController::requestClose' => 'the portal session token (requireSession)',
		'PortalAccountController::confirmClose' => 'the portal session token (requireSession)',
		'PortalAuditController::index' => 'the portal session token (requireSession)',
		'PortalDelegationController::index' => 'the portal session token (requireSession)',
		'PortalDelegationController::create' => 'the portal session token (requireSession)',
		'PortalDelegationController::destroy' => 'the portal session token (requireSession)',
		'PortalDocumentController::sign' => 'the portal session token and the document token (requireSession, validateToken)',
		'PortalDocumentController::download' => 'the portal session token and the document token (requireSession, validateToken)',
		'PortalRequestController::index' => 'the portal session token (requireSession)',
		'PortalRequestController::create' => 'the portal session token (requireSession)',
		'PortalRequestController::show' => 'the portal session token (requireSession)',
		'PortalRequestController::reply' => 'the portal session token (requireSession)',
		'PortalDataController::invoices' => 'the portal session token and per-object access (requireSession, requireObjectAccess)',
		'PortalDataController::invoice' => 'the portal session token and per-object access (requireSession, requireObjectAccess)',
		'PortalDataController::contracts' => 'the portal session token and per-object access (requireSession, requireObjectAccess)',
		'PortalDataController::contract' => 'the portal session token and per-object access (requireSession, requireObjectAccess)',
		'PortalDataController::orders' => 'the portal session token and per-object access (requireSession, requireObjectAccess)',
		'PortalDataController::order' => 'the portal session token and per-object access (requireSession, requireObjectAccess)',
		'PortalController::services' => 'the signed booking link (validateSignedLink, HMAC)',
		'PortalController::availability' => 'the signed booking link (validateSignedLink, HMAC)',
		'PortalController::book' => 'the signed booking link (validateSignedLink, HMAC)',
		'PortalController::reschedule' => 'the signed booking link (validateSignedLink, HMAC)',
		'PortalController::cancel' => 'the signed booking link (validateSignedLink, HMAC)',
		'PortalController::getBooking' => 'the signed booking link (validateSignedLink, HMAC)',
		'PortalPageController::index' => 'none: serves the portal\'s page shell, which holds no record data',
		'PortalPageController::subpath' => 'none: serves the portal\'s page shell, which holds no record data',
		'BlastWebhookController::sendgrid' => 'the mail provider\'s webhook signature (verifySignature)',
		'BlastWebhookController::ses' => 'the mail provider\'s webhook signature (verifySignature)',
		'BlastWebhookController::twilio' => 'the mail provider\'s webhook signature (verifySignature)',
		'BlastWebhookController::brevo' => 'the mail provider\'s webhook signature (verifySignature)',
		'BlastWebhookController::mailjet' => 'the mail provider\'s webhook signature (verifySignature)',
		'BlastWebhookController::mailgun' => 'the mail provider\'s webhook signature (verifySignature)',
		'BlastWebhookController::postmark' => 'the mail provider\'s webhook signature (verifySignature)',
		'BlastTrackingController::open' => 'the per-recipient tracking token (verifyToken, hash_equals)',
		'BlastTrackingController::click' => 'the per-recipient tracking token (verifyToken, hash_equals)',
		'ListPublicController::confirm' => 'the subscriber\'s list token',
		'ListPublicController::unsubscribePage' => 'the subscriber\'s list token',
		'ListPublicController::unsubscribe' => 'the subscriber\'s list token',
		'ListPublicController::preferences' => 'the subscriber\'s list token',
		'ListPublicController::savePreferences' => 'the subscriber\'s list token',
		'ListPublicController::subscribe' => 'the subscriber\'s list token',
		'AppointmentPaymentWebhookController::callback' => 'the payment provider\'s HMAC signature (verifySignature, hash_equals)',
		'ZgwNotificationController::inbox' => 'the ZGW notification token (hash_equals)',
		'MessagingWebhookController::whatsapp' => 'the SMS / WhatsApp provider\'s webhook signature',
		'MessagingWebhookController::sms' => 'the SMS / WhatsApp provider\'s webhook signature',
		'PortalQuestionController::ask' => 'the portal assertion header (PortalAssertionVerifier)',
		'PortalQuestionController::reply' => 'the portal assertion header (PortalAssertionVerifier)',
		'BerichtenboxWebhookController::readReceipt' => 'the Berichtenbox webhook signature (signedPayload)',
		'BerichtenboxWebhookController::inboundReply' => 'the Berichtenbox webhook signature (signedPayload)',
		'BrpController::mutationWebhook' => 'the BRP mutation feed\'s X-Signature header',
		'EventCancelController::show' => 'the signed per-registration cancel token (EventEmailService)',
		'EventCancelController::cancel' => 'the signed per-registration cancel token (EventEmailService)',
		'OpenApiController::show' => 'none: serves the static OpenAPI document, no record data',
	];

	/**
	 * Routes that declare no access attribute and so take the Nextcloud default: admins only.
	 *
	 * Pinned, so a new route without an attribute fails the test (REQ-PLAT-005).
	 *
	 * @var array<int, string>
	 */
	private const ADMIN_DEFAULT_ROUTES = [
		'notes#deleteAll',
		'cti#updateConfig',
		'cti#testConnection',
		'cti#eventLog',
		'callback#reassign',
		'posRole#create',
		'posRole#update',
		'posRole#destroy',
		'posStaff#index',
		'posStaff#create',
		'posStaff#show',
		'posStaff#update',
		'posStaff#destroy',
		'posPayment#index',
		'posPayment#show',
		'posPayment#update',
		'posPayment#test',
		'messagingAdmin#getServiceAccount',
		'messagingAdmin#saveServiceAccount',
		'berichtenboxAdmin#retry',
		'berichtenboxAdmin#stats',
		'portalAdmin#getConfig',
		'portalAdmin#saveConfig',
		'portalAdmin#accounts',
		'portalAdmin#tenants',
		'portalAdmin#auditEvents',
		'portalAdmin#getServiceAccount',
		'portalAdmin#saveServiceAccount',
	];

	/**
	 * The words a seat or licence-key check would use (REQ-PLAT-003).
	 *
	 * @var string
	 */
	private const SEAT_CHECK = '/\b(maxUsers|seatCount|licenseKey|licenceKey)\b/i';

	/**
	 * A Nextcloud access attribute or annotation.
	 *
	 * @var string
	 */
	private const ACCESS = '/#\[(?:\\\\?OCP\\\\AppFramework\\\\Http\\\\Attribute\\\\)?(PublicPage|NoAdminRequired|AuthorizedAdminSetting)\b|@(PublicPage|NoAdminRequired|AuthorizedAdminSetting)\b/';

	/**
	 * The public-page marker alone.
	 *
	 * @var string
	 */
	private const PUBLIC_PAGE = '/#\[(?:\\\\?OCP\\\\AppFramework\\\\Http\\\\Attribute\\\\)?PublicPage\b|@PublicPage\b/';

	/**
	 * A file of the app, by path from the app root.
	 *
	 * @param string $path The relative path.
	 *
	 * @return string The contents.
	 */
	private function file(string $path): string {
		return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
	}//end file()

	/**
	 * info.xml and LICENSE both name the EUPL-1.2 (REQ-PLAT-002).
	 *
	 * @return void
	 */
	public function testTheLicenceIsTheEuplInBothPlaces(): void {
		$info = simplexml_load_string($this->file(path: 'appinfo/info.xml'));

		$this->assertSame('EUPL-1.2', (string)$info->licence);
		$this->assertStringStartsWith('EUROPEAN UNION PUBLIC LICENCE v. 1.2', $this->file(path: 'LICENSE'));
	}//end testTheLicenceIsTheEuplInBothPlaces()

	/**
	 * OpenRegister is the only app the manifest requires; every other one is optional (REQ-PLAT-001).
	 *
	 * @return void
	 */
	public function testOpenRegisterIsTheOnlyRequiredApp(): void {
		$manifest = json_decode($this->file(path: 'src/manifest.json'), true);

		$required = [];
		foreach ($manifest['dependencies'] as $dependency) {
			if (is_string($dependency) === true) {
				$required[] = $dependency;
				continue;
			}

			if (($dependency['required'] ?? true) !== false) {
				$required[] = $dependency['id'];
			}
		}

		$this->assertSame(['openregister'], $required);
	}//end testOpenRegisterIsTheOnlyRequiredApp()

	/**
	 * No file under lib/ counts seats or checks a licence key (REQ-PLAT-003).
	 *
	 * @return void
	 */
	public function testNothingInLibCountsSeats(): void {
		$found = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/lib'));
		foreach ($files as $file) {
			if ($file->isFile() === true && preg_match(self::SEAT_CHECK, (string)file_get_contents($file->getPathname()), $match) === 1) {
				$found[] = $file->getFilename() . ': ' . $match[0];
			}
		}

		$this->assertSame([], $found);
	}//end testNothingInLibCountsSeats()

	/**
	 * Every routed method declares its access posture; public ones are the listed ones (REQ-PLAT-005).
	 *
	 * @return void
	 */
	public function testEveryRouteDeclaresItsAccessPosture(): void {
		$undeclared = [];
		$public = [];
		foreach ($this->routedMethods() as $route => [$class, $method]) {
			[$head, $classHead] = $this->declarationOf(class: $class, method: $method);
			$declared = $head . $classHead;
			if (preg_match(self::ACCESS, $declared) !== 1) {
				$undeclared[] = $route;
			}

			if (preg_match(self::PUBLIC_PAGE, $declared) === 1) {
				$public[] = $class . '::' . $method;
			}
		}

		$this->assertSame(self::ADMIN_DEFAULT_ROUTES, $undeclared, 'a route without an access attribute');
		$listed = array_keys(self::PUBLIC_ROUTES);
		sort($listed);
		$public = array_values(array_unique($public));
		sort($public);
		$this->assertSame($listed, $public, 'a public route not listed with its check, or a listed one no longer public');
	}//end testEveryRouteDeclaresItsAccessPosture()

	/**
	 * Every public route names a check, or says why it needs none.
	 *
	 * @return void
	 */
	public function testEveryPublicRouteNamesItsCheck(): void {
		foreach (self::PUBLIC_ROUTES as $route => $check) {
			$this->assertNotSame('', trim($check), $route);
		}
	}//end testEveryPublicRouteNamesItsCheck()

	/**
	 * The routed controller methods, by route name, in routes.php order.
	 *
	 * @return array<string, array{0: string, 1: string}> Route name => [controller class, method].
	 */
	private function routedMethods(): array {
		$routes = require dirname(__DIR__, 3) . '/appinfo/routes.php';
		$methods = [];
		foreach ($routes as $list) {
			foreach ($list as $route) {
				[$controller, $method] = explode('#', $route['name']);
				$class = ucfirst(str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $controller)))) . 'Controller';
				$methods[$route['name']] = [$class, $method];
			}
		}

		return $methods;
	}//end routedMethods()

	/**
	 * The docblock and attributes in front of a method, and those in front of its class.
	 *
	 * @param string $class  The controller class name.
	 * @param string $method The method name.
	 *
	 * @return array{0: string, 1: string} The method's head and the class's head.
	 */
	private function declarationOf(string $class, string $method): array {
		$path = 'lib/Controller/' . $class . '.php';
		$this->assertFileExists(dirname(__DIR__, 3) . '/' . $path, 'routed controller ' . $class);
		$source = $this->file(path: $path);

		$found = preg_match(
			'/((?:\/\*\*(?:(?!\*\/).)*\*\/\s*)?(?:#\[[^\n]*\]\s*)*)public function ' . preg_quote($method, '/') . '\s*\(/s',
			$source,
			$head
		);
		$this->assertSame(1, $found, 'routed method ' . $class . '::' . $method);

		$classHead = '';
		if (preg_match('/((?:#\[[^\n]*\]\s*)*)(?:final |abstract )?class /', $source, $match) === 1) {
			$classHead = $match[1];
		}

		return [$head[1], $classHead];
	}//end declarationOf()
}//end class
