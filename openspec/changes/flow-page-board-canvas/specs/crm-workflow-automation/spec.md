## ADDED Requirements

### Requirement: The flow page is the board canvas

The flow page MUST use the canvas layout of the shared flow board, as boards PqFlow and PqFlowRun draw it, and MUST NOT mount an app sidebar. The flow settings (name, description, trigger, steps) MUST stay editable in the canvas inspector.

#### Scenario: A flow opens on the canvas
@e2e exclude Manifest configuration over a nextcloud-vue layout, covered by flowPageBoardCanvas.spec.js; the layout itself is tested in nextcloud-vue.
- GIVEN a pipelinq flow
- WHEN the user opens it
- THEN the actions stand under the title and the canvas fills the page
- AND opening the side panel shows the flow's name, description and trigger
