# The flow page is the board canvas

## Why
Boards PqFlow and PqFlowRun draw a flow as a canvas page: the actions under the title, the canvas on the page ground, and the inspector as a floating panel over it. pipelinq drew the canvas in a card with the flow settings in Nextcloud's app sidebar.

## What changes
- FlowDetail sets `config.layout: "canvas"` (nextcloud-vue flow-board-canvas-layout, 24t2u).
- FlowDetail drops `sidebarComponent`. The canvas inspector embeds CnFlowSidebar, so naming, describing, the trigger and step editing stay. The app's FlowDetailSidebar component and its registry entry go.

## Needs
nextcloud-vue 24t2u feat/flow-board-canvas-layout. Before it the page draws the card layout without the app sidebar.
