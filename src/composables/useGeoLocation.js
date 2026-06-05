// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// useGeoLocation — optional GPS capture attached to the time-tracker leaf
// capture metadata payload on timer start.
//
// No pipelinq schema field is added for GPS data. When granted, the
// location is passed as metadata to the leaf's capture endpoint.
// When denied or unavailable, the capture proceeds without location.
//
// @spec openspec/changes/time-entry-mobile/tasks.md#task-4.1

import { ref } from 'vue'

/**
 * The geolocation composable.
 *
 * @return {object} Reactive state and capture control.
 */
export function useGeoLocation() {
	/** Whether the Geolocation API is available in this browser. */
	const supported = ref(
		typeof navigator !== 'undefined' && 'geolocation' in navigator,
	)

	/** Whether a position fix is currently in progress. */
	const isCapturing = ref(false)

	/** The most recently captured latitude, or null. */
	const latitude = ref(null)

	/** The most recently captured longitude, or null. */
	const longitude = ref(null)

	/** Accuracy of the last fix in metres, or null. */
	const accuracy = ref(null)

	/** Whether GPS permission was explicitly denied by the user. */
	const isDenied = ref(false)

	/** The last error message, or null. */
	const lastError = ref(null)

	/**
	 * Attempt to capture the current position.
	 *
	 * Resolves with a metadata object `{ latitude, longitude, accuracy }` when
	 * a fix is obtained, or `{}` when unavailable / denied / timed-out.
	 *
	 * The caller MUST NOT block the timer start on this — call it in parallel
	 * and attach the result to the capture metadata payload once resolved.
	 *
	 * @return {Promise<object>} The captured location metadata, or {}.
	 */
	function capturePosition() {
		if (!supported.value || isDenied.value) {
			return Promise.resolve({})
		}

		isCapturing.value = true
		lastError.value = null

		return new Promise((resolve) => {
			navigator.geolocation.getCurrentPosition(
				(position) => {
					latitude.value = position.coords.latitude
					longitude.value = position.coords.longitude
					accuracy.value = position.coords.accuracy
					isCapturing.value = false
					resolve({
						latitude: position.coords.latitude,
						longitude: position.coords.longitude,
						accuracy: position.coords.accuracy,
					})
				},
				(error) => {
					isCapturing.value = false
					if (error.code === error.PERMISSION_DENIED) {
						isDenied.value = true
					}
					lastError.value = error.message
					// Degrade gracefully — return empty metadata.
					resolve({})
				},
				{
					enableHighAccuracy: true,
					timeout: 8000,
					maximumAge: 30000,
				},
			)
		})
	}

	/**
	 * Clear the cached position.
	 */
	function clearPosition() {
		latitude.value = null
		longitude.value = null
		accuracy.value = null
	}

	return {
		supported,
		isCapturing,
		latitude,
		longitude,
		accuracy,
		isDenied,
		lastError,
		capturePosition,
		clearPosition,
	}
}
