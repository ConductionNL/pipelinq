<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/platform-phone-on-the-road/specs/mobile-experience/spec.md#requirement-numbers-addresses-and-mail-are-links-req-mob-001 -->
<template>
	<ul v-if="links.length" class="contact-links" data-testid="contact-links">
		<li v-for="link in links" :key="link.kind">
			<a
				class="contact-links__link"
				:href="link.href"
				:data-testid="'contact-link-' + link.kind">
				<component :is="link.icon" :size="20" class="contact-links__icon" />
				<span class="contact-links__text">
					<span class="contact-links__label">{{ link.label }}</span>
					<span class="contact-links__value">{{ link.value }}</span>
				</span>
			</a>
		</li>
	</ul>
</template>

<script>
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import MapMarkerOutline from 'vue-material-design-icons/MapMarkerOutline.vue'
import PhoneOutline from 'vue-material-design-icons/PhoneOutline.vue'
import { mailtoHref, mapHref, telHref } from '../services/contactLinks.js'

/**
 * Call, mail and directions links for a client or contact, shown at the
 * top of the detail page so they are one tap away on a phone. Each link
 * keeps the value as the user typed it; only the target is normalised.
 * A value that cannot be a link (no digits, not an address) is left out.
 *
 * @spec openspec/changes/platform-phone-on-the-road/specs/mobile-experience/spec.md#requirement-numbers-addresses-and-mail-are-links-req-mob-001
 */
export default {
	name: 'ContactLinks',

	props: {
		/** Phone number as typed. */
		phone: {
			type: String,
			default: '',
		},

		/** Mail address as typed. */
		email: {
			type: String,
			default: '',
		},

		/** Postal address as typed. */
		address: {
			type: String,
			default: '',
		},
	},

	computed: {
		/**
		 * @return {Array<{kind: string, href: string, label: string, value: string, icon: object}>}
		 * @spec openspec/changes/platform-phone-on-the-road/specs/mobile-experience/spec.md#requirement-numbers-addresses-and-mail-are-links-req-mob-001
		 */
		links() {
			const out = []
			const tel = telHref(this.phone)
			if (tel) {
				out.push({
					kind: 'phone',
					href: tel,
					label: t('pipelinq', 'Call'),
					value: this.phone,
					icon: PhoneOutline,
				})
			}
			const mail = mailtoHref(this.email)
			if (mail) {
				out.push({
					kind: 'email',
					href: mail,
					label: t('pipelinq', 'Mail'),
					value: this.email,
					icon: EmailOutline,
				})
			}
			const map = mapHref(this.address)
			if (map) {
				out.push({
					kind: 'address',
					href: map,
					label: t('pipelinq', 'Directions'),
					value: this.address,
					icon: MapMarkerOutline,
				})
			}
			return out
		},
	},
}
</script>

<style scoped>
.contact-links {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 0 0 12px;
	padding: 0;
	list-style: none;
}

.contact-links li {
	min-width: 0;
	max-width: 100%;
}

.contact-links__link {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	min-height: 44px;
	max-width: 100%;
	padding: 4px 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-large);
	color: var(--color-main-text);
	text-decoration: none;
}

.contact-links__link:hover,
.contact-links__link:focus-visible {
	background: var(--color-background-hover);
}

.contact-links__link:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.contact-links__text {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.contact-links__label {
	font-weight: 600;
}

.contact-links__value {
	overflow-wrap: anywhere;
	color: var(--color-text-maxcontrast);
}
</style>
