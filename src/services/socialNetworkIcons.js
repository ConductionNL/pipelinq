// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import AlphaXBox from 'vue-material-design-icons/AlphaXBox.vue'
import At from 'vue-material-design-icons/At.vue'
import ButterflyOutline from 'vue-material-design-icons/ButterflyOutline.vue'
import Facebook from 'vue-material-design-icons/Facebook.vue'
import Instagram from 'vue-material-design-icons/Instagram.vue'
import Linkedin from 'vue-material-design-icons/Linkedin.vue'
import Mastodon from 'vue-material-design-icons/Mastodon.vue'
import ShareVariantOutline from 'vue-material-design-icons/ShareVariantOutline.vue'

/** Each network's icon; an unknown network falls back to the share icon. */
const NETWORK_ICONS = {
	mastodon: Mastodon,
	bluesky: ButterflyOutline,
	linkedin: Linkedin,
	x: AlphaXBox,
	facebook: Facebook,
	instagram: Instagram,
	threads: At,
}

/**
 * The icon component a network is shown with.
 *
 * @param {string} network The network.
 * @return {object} Its icon component.
 */
export function networkIcon(network) {
	return NETWORK_ICONS[network] || ShareVariantOutline
}
