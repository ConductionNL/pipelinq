# Tasks: contract-contact-ref

- [x] salesContract.contactRef (optional, uuid, $ref contact), schema 1.1.0 (register.d fragment)
- [x] Contact page Gerelateerd: contracts by contactRef, "Contract {contractNumber}", ContractDetail
- [x] Tests red first: SalesContractContactRefSchemaTest, contactPersonPage vitest
- [x] Data item for DQ-DATA (queued in DQ-DATA/INBOX.md): CON-2026-0031 contactRef Joke Smit
  - Built in the Zuiddrecht example set: `zuiddrecht.d/79-quote-0042-contact-history.json` change `contract-sportvelden-contact` sets `contactRef` on `contract-sportvelden-2026` (CON-2026-0031) to `contact-joke-smit`; `ZuiddrechtQuoteAndExportRunsTest` pins it.
- [x] salesContract.renewalLeadOutcome accepts null (type string|null, null in the enum); test red first
