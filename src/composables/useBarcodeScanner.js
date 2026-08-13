/**
 * useBarcodeScanner — unified HID keyboard-wedge + camera barcode capture.
 *
 * Two input sources are unified under a single `scan(barcode)` event:
 *
 *  1. HID keyboard-wedge: USB/Bluetooth scanners emit a barcode string as a
 *     rapid keystroke burst terminated by Enter. A burst qualifies as a scan
 *     only when characters arrive faster than SCAN_MAX_INTERVAL_MS on average
 *     and the sequence is at least SCAN_MIN_LENGTH characters — so ordinary
 *     human typing is never mistaken for a scan and never hijacked.
 *  2. Camera: when the browser exposes the `BarcodeDetector` API, a rear-camera
 *     stream is polled and the first stable decode is emitted. When the API is
 *     absent the composable degrades silently to HID-only mode.
 *
 * The HID timing logic is exported as the pure `createHidBufferReducer` factory
 * so it can be unit-tested without a DOM.
 *
 * @spec openspec/specs/pos-barcode-scan/spec.md
 * @spec openspec/specs/pos-barcode-scan/spec.md
 * @spec openspec/specs/pos-barcode-scan/spec.md
 */

import { onMounted, onUnmounted, ref } from 'vue'

/** Minimum number of characters a HID burst must contain to count as a scan. */
export const SCAN_MIN_LENGTH = 4

/** Maximum average inter-character delay (ms) for a burst to count as a scan. */
export const SCAN_MAX_INTERVAL_MS = 50

/** Idle window (ms) after which a partial HID buffer is discarded. */
export const SCAN_IDLE_RESET_MS = 200

/** Barcode formats requested from the BarcodeDetector (V1 scope: EAN/UPC). */
export const BARCODE_FORMATS = ['ean_13', 'upc_a', 'ean_8', 'upc_e']

/** Maximum accepted barcode length — mirrors the backend guard. */
export const BARCODE_MAX_LENGTH = 64

/**
 * Whether a captured string is a syntactically acceptable barcode.
 *
 * Scanned input is untrusted; only the characters real linear symbologies emit
 * (digits, letters, space, hyphen, dot) within a bounded length are accepted.
 * This mirrors the server-side guard so the client never sends a payload that
 * the backend would reject anyway.
 *
 * @param {string} barcode The captured candidate.
 * @return {boolean} Whether the candidate is an acceptable barcode.
 */
export function isValidBarcode(barcode) {
	if (typeof barcode !== 'string') {
		return false
	}
	const trimmed = barcode.trim()
	if (trimmed === '' || trimmed.length > BARCODE_MAX_LENGTH) {
		return false
	}
	return /^[A-Za-z0-9 .-]+$/.test(trimmed)
}

/**
 * Create a stateful, DOM-free HID keystroke reducer.
 *
 * Feed it printable characters and Enter events with their timestamps; it
 * returns a completed barcode string on a qualifying burst, otherwise null.
 * Kept pure (no globals) so it is unit-testable in isolation.
 *
 * @param {object} [options] Override timing thresholds.
 * @param {number} [options.minLength] Minimum burst length.
 * @param {number} [options.maxIntervalMs] Maximum average inter-key delay.
 * @param {number} [options.idleResetMs] Idle window before the buffer resets.
 * @return {{push: Function, reset: Function}} The reducer.
 */
export function createHidBufferReducer(options = {}) {
	const minLength = options.minLength ?? SCAN_MIN_LENGTH
	const maxIntervalMs = options.maxIntervalMs ?? SCAN_MAX_INTERVAL_MS
	const idleResetMs = options.idleResetMs ?? SCAN_IDLE_RESET_MS

	let buffer = ''
	let intervals = []
	let lastTime = 0

	/**
	 * Reset the internal buffer.
	 */
	function reset() {
		buffer = ''
		intervals = []
		lastTime = 0
	}

	/**
	 * Feed one keystroke into the reducer.
	 *
	 * @param {object} event A normalised key event.
	 * @param {string} [event.char] The printable character (omit for Enter).
	 * @param {boolean} [event.enter] Whether this is an Enter/CR terminator.
	 * @param {number} event.time Monotonic timestamp in milliseconds.
	 * @return {string|null} The completed barcode, or null when not yet a scan.
	 */
	function push(event) {
		const time = event.time

		// Drop a stale partial buffer after an idle gap.
		if (lastTime !== 0 && time - lastTime > idleResetMs) {
			reset()
		}

		if (event.enter === true) {
			const completed = buffer
			const burstIntervals = intervals
			reset()

			if (completed.length < minLength) {
				return null
			}
			// Require the burst to be fast on average — guards against a human
			// typing then pressing Enter being read as a scan.
			if (burstIntervals.length > 0) {
				const avg =
					burstIntervals.reduce((a, b) => a + b, 0) / burstIntervals.length
				if (avg > maxIntervalMs) {
					return null
				}
			}
			return isValidBarcode(completed) ? completed.trim() : null
		}

		if (typeof event.char === 'string' && event.char.length === 1) {
			if (lastTime !== 0) {
				intervals.push(time - lastTime)
			}
			buffer += event.char
			lastTime = time
		}

		return null
	}

	return { push, reset }
}

/**
 * The barcode-scanner composable.
 *
 * @param {Function} onScan Callback invoked with a validated barcode string.
 * @return {object} Reactive state + camera controls.
 */
export function useBarcodeScanner(onScan) {
	const supported = ref(
		typeof window !== 'undefined' && 'BarcodeDetector' in window,
	)
	const scanning = ref(false)
	const videoEl = ref(null)

	const reducer = createHidBufferReducer()
	let stream = null
	let detector = null
	let rafId = null

	/**
	 * Emit a scan to the consumer when the value is a valid barcode.
	 *
	 * @param {string} barcode The captured barcode.
	 */
	function emitScan(barcode) {
		if (isValidBarcode(barcode) && typeof onScan === 'function') {
			onScan(barcode.trim())
		}
	}

	/**
	 * Global keydown handler implementing HID keyboard-wedge detection.
	 *
	 * Only single printable characters and Enter are fed to the reducer; this
	 * never calls preventDefault on ordinary keys, so typing into form fields is
	 * unaffected. A completed scan is consumed (preventDefault) so the wedge's
	 * trailing Enter does not also submit a focused form.
	 *
	 * @param {KeyboardEvent} e The keydown event.
	 */
	function handleKeydown(e) {
		if (e.key === 'Enter') {
			const barcode = reducer.push({ enter: true, time: e.timeStamp })
			if (barcode !== null) {
				e.preventDefault()
				emitScan(barcode)
			}
			return
		}
		// Ignore modifier combos and non-printable keys.
		if (e.key.length !== 1 || e.ctrlKey || e.metaKey || e.altKey) {
			return
		}
		reducer.push({ char: e.key, time: e.timeStamp })
	}

	/**
	 * Cancel the camera poll loop.
	 */
	function cancelLoop() {
		if (rafId !== null) {
			cancelAnimationFrame(rafId)
			rafId = null
		}
	}

	/**
	 * Stop the camera stream and tear down the detector.
	 */
	function stopCamera() {
		cancelLoop()
		if (stream !== null) {
			stream.getTracks().forEach((track) => track.stop())
			stream = null
		}
		if (videoEl.value) {
			videoEl.value.srcObject = null
		}
		detector = null
		scanning.value = false
	}

	/**
	 * Poll the BarcodeDetector for a decode, emitting the first valid result.
	 */
	async function scanLoop() {
		if (detector === null || videoEl.value === null) {
			return
		}
		try {
			const barcodes = await detector.detect(videoEl.value)
			const hit = (barcodes || []).find((b) => isValidBarcode(b.rawValue))
			if (hit) {
				const value = hit.rawValue.trim()
				stopCamera()
				emitScan(value)
				return
			}
		} catch (e) {
			// A transient detect() failure must not kill the loop.
		}
		rafId = requestAnimationFrame(scanLoop)
	}

	/**
	 * Open the rear camera and begin polling for barcodes.
	 *
	 * @return {Promise<void>} Resolves once the stream is live (or rejects).
	 */
	async function startCamera() {
		if (!supported.value || scanning.value) {
			return
		}
		stream = await navigator.mediaDevices.getUserMedia({
			video: { facingMode: 'environment' },
		})
		scanning.value = true
		// eslint-disable-next-line no-undef
		detector = new BarcodeDetector({ formats: BARCODE_FORMATS })
		// The <video> element is bound by the host component after scanning flips
		// true; wait a tick so videoEl.value is populated before the first poll.
		await Promise.resolve()
		if (videoEl.value) {
			videoEl.value.srcObject = stream
		}
		rafId = requestAnimationFrame(scanLoop)
	}

	onMounted(() => {
		window.addEventListener('keydown', handleKeydown)
	})

	onUnmounted(() => {
		window.removeEventListener('keydown', handleKeydown)
		if (scanning.value) {
			stopCamera()
		}
	})

	return {
		supported,
		scanning,
		videoEl,
		startCamera,
		stopCamera,
	}
}
