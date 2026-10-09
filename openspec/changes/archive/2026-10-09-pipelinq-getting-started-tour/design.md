# Design — pipelinq-getting-started-tour

## Builds on
- `cn-walkthrough-engine` (change 1): the `manifest.walkthrough` schema + engine.
- pipelinq's real routes: `Products`/`ProductDetail`, `Contacts`/`ContactDetail`,
  `Leads`/`LeadDetail`, `Pipeline`, `Contracts`/`ContractDetail`, `Clients`.

## The tour (manifest sketch)

```jsonc
"walkthrough": {
  "enabled": true, "version": 1, "completionConfigKey": "walkthrough_seen_version",
  "tours": [{
    "id": "getting-started", "title": "pipelinq.tour.gettingStarted.title",
    "trigger": "first-visit", "minAppVersion": "1.0.0",
    "steps": [
      { "id": "welcome", "placement": "center", "sinceVersion": "1.0.0",
        "title": "pipelinq.tour.welcome.title", "body": "pipelinq.tour.welcome.body",
        "target": { "kind": "page", "ref": "Products" },
        "advanceOn": { "type": "manual" } },

      { "id": "go-products", "sinceVersion": "1.0.0",
        "body": "pipelinq.tour.goProducts.body", "task": "pipelinq.tour.goProducts.task",
        "target": { "kind": "nav-item", "ref": "Products" },
        "advanceOn": { "type": "route-match", "route": "Products" } },

      { "id": "create-product", "sinceVersion": "1.0.0",
        "body": "pipelinq.tour.createProduct.body", "task": "pipelinq.tour.createProduct.task",
        "target": { "kind": "element", "ref": "products-add" },
        "advanceOn": { "type": "object-created", "register": "pipelinq", "schema": "product",
                       "capture": { "productId": ":id" } } },

      { "id": "go-contacts", "sinceVersion": "1.0.0", "target": { "kind": "nav-item", "ref": "Contacts" },
        "body": "...", "task": "...", "advanceOn": { "type": "route-match", "route": "Contacts" } },
      { "id": "create-contact", "sinceVersion": "1.0.0", "target": { "kind": "element", "ref": "contacts-add" },
        "body": "...", "task": "...",
        "advanceOn": { "type": "object-created", "register": "pipelinq", "schema": "contact",
                       "capture": { "contactId": ":id" } } },

      { "id": "go-leads", "sinceVersion": "1.0.0", "target": { "kind": "nav-item", "ref": "Leads" },
        "body": "...", "task": "...", "advanceOn": { "type": "route-match", "route": "Leads" } },
      { "id": "create-lead", "sinceVersion": "1.0.0", "target": { "kind": "element", "ref": "leads-add" },
        "body": "...", "task": "...",
        "advanceOn": { "type": "route-match", "route": "LeadDetail", "capture": { "leadId": ":id" } } },

      { "id": "move-pipeline", "sinceVersion": "1.0.0", "target": { "kind": "element", "ref": "pipeline-board" },
        "body": "pipelinq.tour.movePipeline.body", "task": "pipelinq.tour.movePipeline.task",
        "advanceOn": { "type": "object-created", "register": "pipelinq", "schema": "pipelinestage" },
        "allowManualNext": true },

      { "id": "create-quote", "sinceVersion": "1.0.0", "target": { "kind": "element", "ref": "lead-create-quote" },
        "body": "pipelinq.tour.createQuote.body", "task": "pipelinq.tour.createQuote.task",
        "advanceOn": { "type": "object-created", "register": "pipelinq", "schema": "contract",
                       "capture": { "contractId": ":id" } } },

      { "id": "quote-is-contract", "sinceVersion": "1.0.0", "target": { "kind": "nav-item", "ref": "Contracts" },
        "body": "pipelinq.tour.quoteIsContract.body",
        "advanceOn": { "type": "route-match", "route": "ContractDetail" } },

      { "id": "send-to-shillinq", "sinceVersion": "1.0.0",
        "title": "pipelinq.tour.sendToShillinq.title", "body": "pipelinq.tour.sendToShillinq.body",
        "task": "pipelinq.tour.sendToShillinq.task",
        "target": { "kind": "element", "ref": "contract-send-to-billing" },
        "advanceOn": { "type": "click-target" } }
    ]
  }]
}
```

## Instrumentation

Elements without a stable manifest identity get a `data-walkthrough-id` (reusing
`data-testid` where journeydoc already added it): `products-add`, `contacts-add`,
`leads-add`, `pipeline-board`, `lead-create-quote`, `contract-send-to-billing`.

## Quote = contract

pipelinq has no separate "quote" route; a signable quotation is modelled as a
`contract` (Contracts/ContractDetail). The `create-quote` step therefore captures a
`contract` object; the `quote-is-contract` step makes the conceptual point explicit.

## Cross-app hand-off to shillinq

The final `send-to-shillinq` step targets the contract's "send to billing" action.
Activating it deep-links to shillinq with `?cn_resume_tour=pipelinq:lead-to-bill&cn_resume_step=...`
(the engine's cross-app primitive). A later follow-up MAY add a short shillinq-side
continuation tour keyed to that resume token; this change only emits the hand-off.

## i18n

All `pipelinq.tour.*` keys added to `l10n/en.json` + `l10n/nl.json` (NL mirrors EN).
