<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="portal-profile">
		<h1>{{ t('pipelinq', 'My details') }}</h1>
		<form @submit.prevent="save">
			<div class="portal-field">
				<label for="portal-name">{{ t('pipelinq', 'Name') }}</label>
				<input id="portal-name" v-model="form.displayName" type="text">
			</div>
			<div class="portal-field">
				<label for="portal-phone">{{ t('pipelinq', 'Phone') }}</label>
				<input id="portal-phone"
					v-model="form.phone"
					type="tel"
					autocomplete="tel">
			</div>
			<div class="portal-field">
				<label for="portal-address">{{ t('pipelinq', 'Address') }}</label>
				<input id="portal-address"
					v-model="form.address"
					type="text"
					autocomplete="street-address">
			</div>
			<div v-if="form.accountType === 'b2b'" class="portal-field">
				<label for="portal-jobtitle">{{ t('pipelinq', 'Job title') }}</label>
				<input id="portal-jobtitle" v-model="form.jobTitle" type="text">
			</div>
			<div class="portal-field">
				<label for="portal-locale">{{ t('pipelinq', 'Language') }}</label>
				<select id="portal-locale" v-model="form.locale">
					<option value="nl">
						Nederlands
					</option>
					<option value="en">
						English
					</option>
					<option value="de">
						Deutsch
					</option>
					<option value="fr">
						Français
					</option>
				</select>
			</div>
			<div class="portal-field">
				<label for="portal-change-email">{{ t('pipelinq', 'Email address') }}</label>
				<input id="portal-change-email"
					v-model="form.email"
					type="email"
					autocomplete="email">
				<small v-if="pendingEmail">{{ t('pipelinq', 'A verification link has been sent to {email}.', { email: pendingEmail }) }}</small>
			</div>
			<p v-if="message"
				role="status"
				aria-live="polite"
				class="portal-success">
				{{ message }}
			</p>
			<p v-if="error"
				id="portal-profile-error"
				role="alert"
				class="portal-error">
				{{ error }}
			</p>
			<button type="submit" class="portal-button-primary">
				{{ t('pipelinq', 'Save') }}
			</button>
		</form>
	</div>
</template>

<script>
import { portalApi } from '../portalApi.js'

export default {
	name: 'PortalProfile',
	data() {
		return {
			form: { displayName: '', phone: '', address: '', jobTitle: '', locale: 'nl', email: '', accountType: 'b2c' },
			pendingEmail: '',
			message: '',
			error: '',
		}
	},
	mounted() {
		this.load()
	},
	methods: {
		async load() {
			try {
				const profile = await portalApi.profile()
				this.form = {
					displayName: profile.displayName || '',
					phone: profile.phone || '',
					address: profile.address || '',
					jobTitle: profile.jobTitle || '',
					locale: profile.locale || 'nl',
					email: profile.email || '',
					accountType: profile.accountType || 'b2c',
				}
				this.pendingEmail = profile.pendingEmail || ''
			} catch (e) {
				this.error = e.message
			}
		},
		async save() {
			this.message = ''
			this.error = ''
			try {
				const updated = await portalApi.updateProfile({ ...this.form })
				this.pendingEmail = updated.pendingEmail || ''
				this.message = t('pipelinq', 'Your details have been saved.')
			} catch (e) {
				this.error = e.message || t('pipelinq', 'Could not save your details.')
			}
		},
	},
}
</script>
