# Bulk changes

Change one field on many clients or contacts at once, see the effect before it
is written, and undo the change later while the reversal window is open. The
write runs as an OpenRegister bulk job: a previewed, reversible job that
pipelinq never writes before you confirm.

## Select and change a field

1. Open **Clients** or **Contacts**.
2. Tick the records you want to change. A selection strip appears.
3. Choose **Change a field**.
4. Pick the field to change and enter the new value. The value input matches the
   field: a list for a field with fixed choices, a switch for a yes/no field, a
   number for a number field, otherwise a text box.
5. Press **Preview**. OpenRegister rehearses the change and writes nothing. The
   dialog shows how many records will change and how many already hold the value
   and are skipped.
6. Press **Confirm** to write the change. The list refreshes with the new value.

If the selection is larger than the instance allows, or OpenRegister refuses the
change for another reason, the dialog shows the reason and nothing is written.

## Undo a bulk change

Every bulk change can be undone for seven days, the reversal window OpenRegister
keeps.

1. Open **Bulk changes**.
2. Find the change in the list: when it was made, which field, how many records,
   and its state.
3. Press **Undo**. OpenRegister previews the reversal the same way, listing the
   records it will write back and any it will skip because they were edited by
   hand since.
4. Press **Confirm** to write the earlier values back.

A change past its seven-day window shows the date it stopped being reversible and
offers no **Undo**.

## The seven-day window

The reversal window is a setting in OpenRegister
(`AbstractPropertyWriteAction::REVERSAL_WINDOW`), not in pipelinq. Pipelinq shows
the real window and does not promise longer. A longer, configurable window is a
possible OpenRegister change.
