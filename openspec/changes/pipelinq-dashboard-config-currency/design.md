# Design — Config-driven dashboard currency formatting

## The appConfig wiring (how the currency reaches `@config.currency`)

`@conduction/nextcloud-vue` resolves the `@config.<key>` token in
`src/utils/resolveFilterTokens.js` against `ctx.config`. For dashboard widgets,
`CnStatWidget` builds `ctx` from `inject('cnAppConfig', ref({}))`, and that inject
is **provided by `CnDashboardPage`**, seeded from (and kept in sync with) its
`appConfig` prop:

```
CnDashboardPage.props.appConfig (default {})
  → provide('cnAppConfig', ref({ ...appConfig }))   // watched, deep
    → CnStatWidget inject('cnAppConfig')
      → resolveFilterValue/format: @config.currency → ctx.config.currency
```

`CnDashboardPage`'s docblock states a manifest renderer "typically seeds this from
`loadState(appId, 'config', {})`". Neither `CnAppRoot` nor `CnPageRenderer` seed
it (CnAppRoot has no `appConfig` prop; CnPageRenderer forwards only `config.*` and
a fixed list of top-level page fields). So the seed is a **pipelinq-side**
responsibility — no nc-vue change is needed, and none was made.

pipelinq renders dashboards declaratively: `App.vue` → `CnAppRoot` → `CnPageRenderer`
(per route) → `CnDashboardPage`. `CnPageRenderer.resolvedProps` returns
`{ ...topLevel, ...config, ...routeParams }` and forwards it to the dispatched page
component. `CnDashboardPage` declares an `appConfig` prop, so **any
`config.appConfig` on a dashboard page lands on that prop**.

Therefore the seed is: put the runtime `appConfig` onto each dashboard page's
`config.appConfig` at manifest-assembly time.

- **Backend** (`Application::boot`): `provideInitialState('config', { currency:
  IAppConfig::getValueString('pipelinq', 'currency', 'EUR') })`. The per-app
  `IInitialState` namespaces this as `initial-state-pipelinq-config`. Default `EUR`
  when the wizard has not run.
- **Frontend** (`main.js`): a `seedDashboardAppConfig(manifest)` step runs after
  `mergeManifestFragments`, reads `loadState('pipelinq', 'config', {})`, and sets
  `page.config = { appConfig, ...page.config }` on every `type: "dashboard"` page
  (explicit per-page `config.appConfig` still wins; none exists today).

## Why not seed via App.vue provide

`CnDashboardPage` always calls `provide('cnAppConfig', ref({ ...props.appConfig }))`,
which **shadows** any ancestor `cnAppConfig` provide with `{}` when its own prop is
empty. So an App.vue-level provide would be overridden to empty on every dashboard.
Feeding the `appConfig` prop is the only reliable path, and it is exactly the
seed the library documents.

## Widgets switched (currency KPIs only)

| Page | Widget | source |
|------|--------|--------|
| Commercial overview | `revenue` | SUM lead.value |
| Commercial overview | `won-value` | SUM lead.value (won) |
| Commercial overview | `weighted-forecast` | weighted lead.value |
| Commercial overview | `mrr` (Recurring revenue) | SUM SalesOrderLine.maandWaarde |
| Commercial overview | `pipeline-coverage` (gauge) | SUM lead.value (open) |
| Operational overview | `pipeline-value` | SUM lead.value |
| Klantbeeld-360 | `open-pipeline-value` | endpoint analytics summary |

`format.currency`: `"EUR"` → `"@config.currency"`. `style:"currency"` and
`decimals` are unchanged. The resolver falls back to the literal `EUR` default when
`@config.currency` is unset, so behaviour is unchanged on a never-configured
instance.

**Left untouched** (deliberately): `style:"number"` / `style:"percent"` KPIs;
`type:"index"` column formats (`budgetAmount`, `price`) — those render in
`CnDataTable` cells, which do not provide/inject `cnAppConfig`, so a `@config.*`
token would leak through unresolved into a table cell.

## Verification

Build, set `currency=USD` (wizard or `occ config:app:set pipelinq currency`),
reload Commercial dashboard → KPIs show `$`. Set `EUR` → `€`. 0 new console errors.
