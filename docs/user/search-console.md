---
title: Search Console
sidebar_position: 41
---

# Search queries from Google Search Console

Pipelinq can import the search queries people typed before they found your site, straight from Google Search Console. The queries land under **Marketing, Search queries** next to your blasts, so acquisition through search sits beside acquisition through mail.

There is no OAuth flow and no browser login to Google. Pipelinq uses a **service account**: a robot identity you create once in Google Cloud and add as a user on the Search Console property. The daily import then runs on its own.

## What you need

- A Google Search Console property for the site (a URL prefix such as `https://example.org/` or a domain property such as `sc-domain:example.org`), with you as an owner or full user.
- A Google Cloud project. Any project you already have is fine; a new one costs nothing.
- Admin rights in Pipelinq.

## Step 1: create the service account

1. Open the [Google Cloud console](https://console.cloud.google.com/) and pick the project.
2. Under **APIs and services, Library**, enable the **Google Search Console API**.
3. Under **IAM and admin, Service accounts**, click **Create service account**. Give it a name such as `pipelinq-search-console`. It needs no project roles.
4. Open the new account, go to **Keys**, and choose **Add key, Create new key, JSON**. A file downloads. That file is the key Pipelinq needs; keep it as you would a password.
5. Note the account's email address. It looks like `pipelinq-search-console@your-project.iam.gserviceaccount.com`.

## Step 2: give the account access to the property

1. Open [Search Console](https://search.google.com/search-console) and pick the property.
2. Under **Settings, Users and permissions**, click **Add user**.
3. Enter the service account's email address and choose **Full** permission. Restricted works too, but Full is what Google documents for the Search Analytics API.

Repeat this for every property you want imported.

## Step 3: connect Pipelinq

1. Open **Admin settings, Pipelinq** and find the **Marketing traffic** section.
2. Under **Search Console properties**, enter one property per line, exactly as Search Console spells it: `https://example.org/` with the trailing slash, or `sc-domain:example.org`.
3. Paste the contents of the downloaded JSON file into **Service account key (JSON)** and save.

After saving, the section shows the service account's email address, so you can check it is the one you added on the property. The key itself is stored encrypted and is never shown again, not in the settings and not through the API. To replace it, paste a new one. To remove it, click **Remove the stored key**.

## What gets imported

Every day the import reads the last three days for each property: one row per day, query and page, with clicks, impressions, click-through rate and average position. Search Console publishes a day about two days after the fact and may still revise it, which is why the same days are read again on the next run. Nothing is counted twice: a row is stored once per property, day, query and page and updated when Google revises it.

The **Search queries** page shows the top queries by clicks over the last 7, 28 or 90 days, with clicks and impressions summed, the click-through rate recomputed from those sums, and the average position weighted by impressions.

## Running the import by hand

An admin with shell access can run the import without waiting for the daily job, for instance right after connecting a property:

```bash
occ pipelinq:marketing:search-console:import --days=30
```

The command reports how many rows it imported per property and names a property Google refused. The most common refusal is a permission error, which means the service account is not yet a user on that property.

## Troubleshooting

- **"User does not have sufficient permission for site"**: the service account's email is not on the property, or the property is spelled differently in Pipelinq than in Search Console. A URL prefix property needs its trailing slash.
- **The page stays empty after a day**: check the last import time in the settings section. When it is empty, the daily job has not run yet; when it is recent, Google has not published the days in the window yet.
- **The key is refused on save**: the file must be the JSON key of a service account (`"type": "service_account"`), not an OAuth client id file.

## Privacy

Search Console reports counts only. No visitor, session or IP address is part of the data, and nothing is sent to Google except the request for those counts.
