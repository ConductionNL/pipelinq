# Web chat

A chat on your organisation's website. A visitor needs no account and gets no cookie. When you switch it on, an assistant answers first from content your organisation has published. When the visitor asks for a person, or the assistant cannot answer, the chat becomes a ticket in the Queue and a colleague carries on in the same window.

Openspec change: `requests-web-chat-and-bot`.

## Add a channel

1. Go to **Administration settings > Pipelinq > Web chat**.
2. Fill in a **Name**, the **Websites** the chat may run on (one per line, exact origins such as `https://www.example.org`), a **Greeting** and the **Text when nobody is available**.
3. Choose whether **The assistant answers first**.
4. Press **Add channel**. A new channel is off.
5. Switch the channel on.

A chat window only loads in a frame on one of the channel's websites. Any other site that frames it gets nothing.

## Paste the line

Each channel shows the line to paste into your website, just before `</body>`:

```html
<script src="https://cloud.example.org/apps/pipelinq/js/pipelinq-web-chat.js?chat=..." async></script>
```

Copy it with **Copy**. Visitors of that page see a chat button at the bottom right.

## The assistant

With **The assistant answers first** switched on and the hermiq app installed, the assistant answers each question from public content first, shows its sources and says it is an AI. When it answers twice in a row without a source, or the visitor presses **Talk to a person**, the chat goes to a colleague. Without hermiq the first message goes to a colleague straight away. Pipelinq calls no AI model itself.

## What a colleague does

1. A chat that is handed over becomes a request ticket in the **Queue**, with the conversation so far. The team gets a notification and a web push.
2. Open the ticket. The **Website chat** section shows who said what.
3. Press **Take this chat**. The ticket is assigned to you and leaves the Queue.
4. Type your answer and press **Send**. The visitor sees it in their window, from "Colleague".
5. Press **End chat** when you are done. The ticket stays as it is.

When no colleague is available (every agent profile is set to unavailable), or nobody takes a handed-over chat within the wait time, the window asks for an email address. The ticket stays in the Queue with that address, and you answer by mail.

## Privacy

The chat stores no IP address and only a hash of the visitor's chat secret. The secret lives in the visitor's browser tab, so closing the tab ends the chat. A chat ends by itself after thirty minutes without a message. The window asks visitors not to type their citizen service number.
