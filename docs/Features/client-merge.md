# Merging clients and contacts, and undoing a merge

Two client records that turn out to be one organisation or person can be merged into one, and the merge can be undone for thirty days. The same holds for two contact records. Pipelinq does not run the merge itself: OpenRegister's merge engine previews it, executes it and reverses it. Pipelinq tells OpenRegister that a client and a contact may be merged, and moves its own links along.

## What happens on a merge

- You choose the record that stays. The other record is the merged-away record.
- OpenRegister copies the values that win onto the record that stays and marks the merged-away record as merged (`accountStatus` `merged` on a client, `recordStatus` `merged` on a contact).
- Every pipelinq record that pointed at the merged-away record now points at the record that stays. For a client that is its contacts, leads, tickets, tasks, enquiries, quotes, contracts, programmes, time and expense entries, till transactions, survey invitations and responses, journey runs, competitor and social entries, and the relationships it is one end of. For a contact it is its leads, tickets, enquiries, quotes, contracts, event registrations, till transactions, relationships, conversations and messages, consent records, subscriptions, deliveries and touchpoints.
- The merged-away record keeps the list of the records that were moved, and shows which record it was merged into, with a link. It can no longer be changed.

## The thirty days

For thirty days after the merge, the merge can be undone. Undoing it brings both records back as they were before and points every record on the list back at the merged-away record.

A record that someone changed by hand after the merge, for example a lead moved to a third client, is left where it is: it no longer points at the record that stayed, so undoing the merge does not touch it. It is reported in the log, with the merge it belonged to.

After thirty days the merge is final.

## Where to find it

- On the merged-away record: the line **merged into**, with a link to the record that stayed.
- Coming: **Merge into another record** on the client and contact page, which shows the preview before anything changes, and the **Merges** section on the record that stayed, with **Undo** while the thirty days last. Until then a merge and its reversal run through OpenRegister's merge endpoints.
