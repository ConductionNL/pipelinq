# Design: the BRP monitor moves to integriq

## Decisions

### 1. One connection, owned by integriq

integriq already seeds a `brp-haalcentraal` source (`BrpPropertySource::SOURCE_SLUG`) and pipelinq already calls it first. After this change it is the only path. pipelinq keeps what the Wet BRP asks of the app that looks people up: the purpose (doelbinding), the audit trail, the cache and who may look up.

### 2. Settings move by repair step, secrets go to keepiq

`MoveBrpConnectionToIntegriq` runs on upgrade:

1. Skip with a log line when integriq is not installed (`isInstalled('integriq')`; integriq ships the id `integriq` today) or when none of the `brp.*` connection keys is set.
2. Fill the `brp-haalcentraal` source's base URL, OAuth endpoint and client id when the source has none. It never overwrites a value an administrator set in integriq; a conflict is reported, not resolved.
3. Hand the client secret (decrypted from `brp.client_secret_encrypted`) and the PEM contents of `brp.cert_path`, `brp.key_path` and `brp.ca_bundle` to integriq through a string-named event. integriq mints keepiq secrets through the credential broker and stores only the references (integriq's change).
4. Clear the pipelinq keys only after integriq confirms the references resolve. Otherwise leave them and report why.

pipelinq never writes a secret into integriq's source object, and never logs one.

### 3. Monitoring is integriq's job

Query performance is measured where the call is made: integriq's outbound call log for the `brp-haalcentraal` source. pipelinq's `bsnAuditRecord` stays the legal audit trail and is no longer aggregated for a monitor. Cache hits are pipelinq's, and are not shown in integriq; integriq shows calls that reached the source.

### 4. No fallback

Today a broken integriq silently routes BRP lookups around it. After this change a lookup without a working source fails with "BRP lookups go through integriq. Ask your administrator to set up the BRP connection there." This is the point of the decision: one place to see whether the connection works.

## Risks

- **An instance with settings in pipelinq and no integriq** loses BRP lookups. The repair step reports it and the admin page names integriq. Release notes say so.
- **A certificate file that is unreadable** at migration time is reported, the keys are kept, and the lookup keeps failing until an administrator uploads the certificate in keepiq.
