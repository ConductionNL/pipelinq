# Proposal: AI Sentiment Analysis on Interactions

## Status
PROPOSED

## Problem
There is no insight into customer sentiment across interactions in Pipelinq. Organizations cannot identify dissatisfied citizens proactively or measure overall service quality trends. 623 tender sources demand sentiment analysis capabilities in CRM solutions.

## Solution
Use the Nextcloud AI integration (via the TaskProcessing API) to analyze sentiment of contactmomenten notes, categorize interactions as positive / neutral / negative, and surface sentiment trends on client detail views and the dashboard.

## Features
- **Sentiment score on each contactmoment**: positive / neutral / negative classification with confidence percentage
- **Sentiment computation via Nextcloud AI TaskProcessing API** using text classification task type
- **Sentiment trend chart** on client detail view showing sentiment over time for that client's interactions
- **Filter and sort by sentiment** on the contactmomenten list view
- **Aggregate sentiment KPI** on the dashboard showing overall sentiment distribution across all recent interactions
- **Backend-agnostic**: works with any Nextcloud AI backend (Ollama, OpenAI, Nextcloud Assistant, etc.)

## Standards
- TEC CRM 4.2 (Analytics and Reporting)
- Nextcloud AI Integration API (TaskProcessing)
- OCP\TextProcessing for fallback compatibility

## Dependencies
- Nextcloud AI backend (any provider that supports text classification via TaskProcessing)

## Demand
623 tender sources demand sentiment analysis in CRM solutions.

## Risks
- Depends on a Nextcloud AI backend being configured and available; must degrade gracefully (hide sentiment UI, no errors) when no AI backend is present
- Sentiment analysis accuracy varies by language; Dutch-language interactions may require a model with Dutch support
- Processing sentiment asynchronously is recommended to avoid blocking the UI on save
