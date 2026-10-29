> DRAFT for adversarial review — not yet owner-approved

# Live chat: WeCom notifications and retention — clause draft

Customer-visible clause for the live-chat surface, drafted to join the privacy policy (`/privacy/`). Tone follows the site's existing English copy: plain, concrete, no legalese. Nothing here promises capability the implementation does not have. English body: 136 words.

## Customer-visible text (EN)

### What happens when you go offline or ask for a person

When you leave a message while we're offline, or ask to talk to a person in the chat, our team gets a notification. It carries a short excerpt of your message — at most 120 characters — and the nickname you used in the chat, delivered through WeCom (Tencent), the messaging service our team uses. A team member taps the notification to open our service desk and read the full conversation.

### How long we keep a conversation

Conversations are kept on our server until you delete them — there is no automatic expiry. In the chat window, "Delete this conversation" removes the conversation from our records. This cannot be undone.

Your browser also remembers your last chat (in localStorage), so the chat window can offer to continue where you left off. That memory lives in your browser only: clear your browser data, and it's gone.

## 中文对照

### 离线留言或转人工时会发生什么

您在我们离线时留言，或在聊天中要求转人工时，我们的团队会收到一条通知。通知里带有您消息的摘录（最长 120 字符）和您在聊天中使用的昵称，经企业微信（腾讯，我们团队使用的通讯工具）送达。团队成员点开通知，进入我们的服务台查看完整对话。

### 我们把对话保留多久

对话保存在我们的服务器上，直到您自行删除——没有自动到期。在聊天窗口使用 "Delete this conversation"，即可把该对话从我们的记录中删除。删除后无法恢复。

您的浏览器还会记住您上一次的聊天（localStorage），聊天窗口因此可以提供"继续上次对话"。这份记忆只存在于您的浏览器中：清除浏览器数据，它即被移除。

## Internal notes — not customer copy

- Authority: owner ruling of 2026-10-28 (AGENTS.md; docs/research/2026-10-28-guest-inquiry-email-and-retention.md, "Decision"): server-side permanent retention — `lh_chat_config` `cleanup_cronjob = 0`, `mdays_older = N`, `mdays_older_visitor = N` (staging-verified); visitor self-service delete; browser-local resume. Owner confirmation 2026-10-29 (GOAL.md, 评审团接管 section): the WeCom clause as written here — 120-character excerpt plus nickname egress to Tencent.
- Implemented behavior the text leans on (all verified, no invented claims): the WeCom card is a textcard titled `Live chat #<id> — <nick>` with the description cut at 120 characters (docs/deploy/lhc-wecom.md); the widget's "Delete this conversation" control posts `chat_id` + `hash` to `POST /wp-json/md/v1/chat/delete` and removes the conversation rows (runbook, One live probe then delete); resume reads `mdv:lastChat` from localStorage with no expiry (research doc).
- Deliberately not claimed: no "encrypted storage" or security wording beyond what exists; no SCC / cross-border transfer basis for Tencent — the current policy §7 claims SCCs for non-EEA processors, and whether that extends to WeCom is an owner call at finalization, not something to promise now; no retention period for chats other than "until you delete them" — the 5-year inquiry-record rule in §6 is a separate record class and must not be conflated.
- Integration points when this lands in `plugins/md-site/content/livecanvas/privacy.html`: ① add a "Live chat" item to §2 What we collect; ② fix §4 — the page currently says "the site does not use local storage", which the chat widget contradicts; ③ add Tencent (WeCom) to §5 processors, scoped to chat notifications only (not the inquiry form); ④ extend §6 Retention with the chat rule above.
