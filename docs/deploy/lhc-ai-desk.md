# LHC AI desk on staging

Date: 2026-10-27. Scope: staging only. Production wiring is a separate ticket.

## Components

- Live Helper Chat 4.97v at https://staging.metal-divider.com/chat.
- AI endpoint: POST /wp-json/md/v1/kb/answer, from mu-plugins/md-kb-endpoint.php (branch impl/155-unanswered-log).
- Knowledge: Rapls AI Chatbot 1.21.4, knowledge entry id 1 "Contact details" (WhatsApp and email). This is real public contact data, not a pattern spec.
- WeCom connector: mu-plugins/md-wecom-notify.php (branch impl/154-desk).
- Department Support id 1: bot_configuration = {"bot_id":3,"bot_foh":true}.

## Bot configuration

Bot 3 "Metal Divider AI", group 3 "AI assistant". Triggers:

| id | name | flags | actions |
| --- | --- | --- | --- |
| 7 | Welcome | default=1 | text greeting; conditions action {args.msg.msg} notempty -> trigger 11 |
| 8 | AI answer | - | text {content_1} |
| 9 | AI handoff | - | text {content_1}; command stopchat payload 0 |
| 10 | AI unavailable | - | text apology; command stopchat payload 0 |
| 11 | AI on every visitor message | default_unknown=1 | typing 2s until message; restapi 3 / kbanswer1 with output map transfer=9, answer=8, default_trigger=10 |

Do not use a default_always trigger with an empty event pattern for "every message".
LHC findEvent builds LIKE '<message>%', so the pattern must be a message prefix; an empty pattern matches nothing. The default_unknown trigger is the generic path for every visitor message after the chat starts.

The Welcome trigger's conditions action solves the start-question gap. LHC saves the widget's first question before setBot and then runs only the default trigger. The conditions action checks {args.msg.msg}; when it is not empty it calls trigger 11, so the first question reaches the AI without an empty request. With no first question, only the greeting is sent.

## REST action 3

- Name: Metal Divider KB.
- Host: https://staging.metal-divider.com
- Method: POST, raw JSON body, max_execution_time 60.
- Sub URL: /wp-json/md/v1/kb/answer
- Body: {"q": {{msg}}, "chat_id": {{chat_id}}, "page_url": {{lhc.referrer}}, "history": []}
- Authorization: bearer. The value is copied from the staging option md_kb_endpoint['bearer_token'] by a one-off setup script. It is never written to the repository.
- Output combinations:
  - transfer: HTTP 200, success_location msg, success_location_2 action, condition action eq transfer_operator, priority 10.
  - answer: HTTP 200, success_location msg, priority 0.
  - default_trigger: 10 (unavailable).
- The action's output map is {"transfer":9,"answer":8,"default_trigger":10}.

Endpoint contract:

- Request: {"q": string, "chat_id": int, "history": []}.
- Answer: {"msg": string, "action": "", "sources": [...]}.
- Handoff: {"msg": string, "action": "transfer_operator", "sources": [...]}.
- The endpoint calls Rapls search and DeepSeek; it is not a fine-tuned model.

## Acceptance on 2026-10-27

Test page: https://staging.metal-divider.com/md-158-ai-test.html (removed after the run). Browser: real Chrome through opencli only.

- Chat 4, first question "What is your WhatsApp number?" -> welcome plus "Our WhatsApp number is +852 6473 1687. You can also reach us by email at info@metal-divider.com." The first-question path worked.
- Chat 4, "Do you install screens in Iceland?" -> "I don't have that information — our team can help."; chat status changed to pending; WeCom textcard "Live chat #4 — Visitor" arrived; the card opened https://staging.metal-divider.com/chat/index.php/site_admin/mdmobile/chat/4 in the logged-in desk.
- Operator reply from the card-opened desk: status active, pill Active, message visible to the visitor.
- After takeover, visitor message "Are you still there?" produced no bot reply; the AI stays silent once the chat is active.
- Close conversation: two-step confirmation, chat status 2, pill Closed, composer disabled.
- Failure path: temporary REST suburl /wp-json/md/v1/kb/answer-missing -> widget showed "Sorry — the assistant is unavailable right now. I'm passing this to the team." and the chat became pending; suburl restored to /wp-json/md/v1/kb/answer.
- Evidence screenshots (local, not committed): .scratch/verify-158/first-question-answered.png, ai-transfer.png, wecom-chat4-card.png, wecom-card-opens-chat4.png, after-takeover-ai-silent.png, close-confirmation.png, chat4-closed.png, endpoint-failure-fallback.png.

Cleanup after acceptance:

- Deleted test chats 3, 4, 5 and their messages.
- Deleted the temporary lh_userdep row id 2 used to force the department online.
- Removed the test page and all md158-*.php setup/probe scripts from the staging web root.
- Kept bot 3, triggers 7-11, REST action 3, department bot_configuration, and the real Rapls contact knowledge.

## Re-running the widget test

1. Make the department online. The Support department's configured hours are 01:00-13:00 UTC (09:00-21:00 Beijing). Outside those hours, add a temporary lh_userdep row for user 1, department 1 with always_on=1 and last_activity=time(); remove it afterwards.
2. Create a staging page with the LHC embed code, or use an existing chat page with the widget. Do not change the production theme.
3. After deploying extension PHP or templates, remove chat/cache/cacheconfig/moduleCache_*.cache.php and chat/cache/cacheconfig/templateCache.cache.php, then reload.
4. Close or delete test chats when done.

## Known boundaries

- The AI answer quality is bounded by Rapls knowledge and DeepSeek, not by this wiring. Knowledge-index work is #102 and corpus evaluation is #160.
- LHC's transfertohuman module dispatches is_online=true without calling isOnline. The WeCom connector relies on chat status for cards; do not treat that dispatch as an online check.
- This is staging configuration. No production bot, department, or endpoint was changed.
