/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The product page's own widgets as titled cards, as board PqProduct draws
 * them: "Tarieventabel (leges)" in the Overview stack and "Levering" in the
 * side column. The card title is the widget's `content.title`.
 *
 * @spec openspec/specs/product-catalog/spec.md
 */

import ProductSupplyWidget from './ProductSupplyWidget.vue'
import ProductTariffsWidget from './ProductTariffsWidget.vue'
import { sectionWidget } from '../widgets/sectionWidget.js'

const productProps = ({ objectId, objectData }) => ({ objectId, objectData })

export const ProductTariffsCard = sectionWidget('ProductTariffsWidget', ProductTariffsWidget, ({ objectData }) => ({ objectData }), { card: true })

export const ProductSupplyCard = sectionWidget('ProductSupplyWidget', ProductSupplyWidget, productProps, { card: true })
