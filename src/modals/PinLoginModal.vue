<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - PinLoginModal — POS terminal staff PIN entry.
  -
  - The cashier picks their name from a list (loaded from /api/pos/staff),
  - then enters the 4-6 digit PIN on the numeric keypad. The keypad is
  - keyboard-navigable and the PIN field is masked. On submit the modal
  - POSTs to /api/pos/staff/auth and, on success, calls
  - posSessionStore.openSession() with the returned envelope.
  -
  - @spec openspec/changes/pos-staff-pin-permissions/tasks.md#7.1
  -->
<template>
	<NcDialog
		:name="t('pipelinq', 'POS terminal — staff sign-in')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="pin-login">
			<NcSelect
				v-model="selectedStaffId"
				:options="staffOptions"
				:inputLabel="t('pipelinq', 'Staff member')"
				:placeholder="t('pipelinq', 'Select your name')"
				:reduce="(o) => o.value"
				label="label"
				:disabled="loading || submitting" />
			<NcTextField
				v-model="pin"
				:label="t('pipelinq', 'PIN')"
				type="password"
				inputmode="numeric"
				autocomplete="off"
				maxlength="6"
				:disabled="!selectedStaffId || submitting"
				@keydown.enter="submit" />
			<div
				class="pin-keypad"
				role="group"
				:aria-label="t('pipelinq', 'PIN keypad')">
				<NcButton
					v-for="digit in ['1', '2', '3', '4', '5', '6', '7', '8', '9']"
					:key="digit"
					class="pin-keypad__btn"
					:disabled="!selectedStaffId || submitting || pin.length >= 6"
					@click="press(digit)">
					{{ digit }}
				</NcButton>
				<NcButton
					class="pin-keypad__btn"
					:disabled="!selectedStaffId || submitting"
					@click="clearPin">
					{{ t('pipelinq', 'Clear') }}
				</NcButton>
				<NcButton
					class="pin-keypad__btn"
					:aria-label="t('pipelinq', 'Digit 0')"
					:disabled="!selectedStaffId || submitting || pin.length >= 6"
					@click="press('0')">
					0
				</NcButton>
				<NcButton
					class="pin-keypad__btn"
					:aria-label="t('pipelinq', 'Backspace')"
					:disabled="!selectedStaffId || submitting || !pin"
					@click="backspace">
					⌫
				</NcButton>
			</div>
			<p v-if="errorMessage" class="pin-login__error" role="alert">
				{{ errorMessage }}
			</p>
			<p v-if="lockedUntil" class="pin-login__error" role="alert">
				{{
					t('pipelinq', 'Account is locked. Try again after {time}.', {
						time: lockedUntil,
					})
				}}
			</p>
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!canSubmit" @click="submit">
				{{
					submitting
						? t('pipelinq', 'Signing in…')
						: t('pipelinq', 'Sign in')
				}}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'
import { usePosSessionStore } from '../store/modules/posSessionStore.js'

export default {
	name: 'PinLoginModal',
	components: { NcButton, NcDialog, NcSelect, NcTextField },
	emits: ['close', 'loginSuccess'],
	data() {
		return {
			staff: [],
			selectedStaffId: '',
			pin: '',
			loading: false,
			submitting: false,
			errorMessage: '',
			lockedUntil: '',
		}
	},

	computed: {
		staffOptions() {
			return this.staff
				.filter((s) => s.isActive !== false)
				.map((s) => ({ value: s.id, label: s.displayName || s.id }))
		},

		/**
		 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#7.1
		 */
		canSubmit() {
			return (
				!!this.selectedStaffId
				&& /^\d{4,6}$/.test(this.pin)
				&& !this.submitting
			)
		},
	},

	async mounted() {
		await this.loadStaff()
	},

	methods: {
		/**
		 * @spec exclude the pos-staff-pin-permissions change is archived and no spec
		 *   inherited POS staff, PIN login or roles
		 */
		async loadStaff() {
			this.loading = true
			try {
				const url = generateUrl('/apps/pipelinq/api/pos/staff')
				const response = await axios.get(url)
				this.staff = response?.data?.staff || []
			} catch {
				// Non-admin users may not list staff; fall back to a manual id field.
				this.staff = []
			} finally {
				this.loading = false
			}
		},

		press(digit) {
			if (this.pin.length >= 6) {
				return
			}
			this.pin += digit
			this.errorMessage = ''
		},

		clearPin() {
			this.pin = ''
			this.errorMessage = ''
		},

		backspace() {
			this.pin = this.pin.slice(0, -1)
		},

		async submit() {
			if (!this.canSubmit) {
				return
			}
			this.submitting = true
			this.errorMessage = ''
			this.lockedUntil = ''
			try {
				const url = generateUrl('/apps/pipelinq/api/pos/staff/auth')
				const response = await axios.post(url, {
					staffId: this.selectedStaffId,
					pin: this.pin,
				})
				const session = response?.data?.session
				if (!session?.staffId) {
					throw new Error(t('pipelinq', 'Sign-in failed'))
				}
				const store = usePosSessionStore()
				store.openSession(session)
				this.$emit('loginSuccess', session)
				this.$emit('close')
			} catch (error) {
				const status = error?.response?.status
				const serverMessage = error?.response?.data?.error || ''
				if (status === 403) {
					if (
						serverMessage.toLowerCase().includes('geblokkeerd')
						|| serverMessage.toLowerCase().includes('locked')
					) {
						this.lockedUntil = serverMessage
					} else {
						this.errorMessage =
							serverMessage || t('pipelinq', 'Incorrect PIN')
					}
				} else {
					this.errorMessage =
						serverMessage
						|| t('pipelinq', 'An unexpected error occurred')
				}
				this.pin = ''
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.pin-login {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-width: 320px;
}

.pin-keypad {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 8px;
	margin-top: 8px;
}

.pin-keypad__btn {
	min-height: 48px;
	font-size: 1.2rem;
}

.pin-login__error {
	color: var(--color-error);
	margin: 0;
	font-size: 0.9rem;
}
</style>
