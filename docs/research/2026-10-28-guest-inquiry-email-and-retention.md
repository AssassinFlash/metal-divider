# Guest email and browser-local continuity

Date: 2026-10-28. Scope: the Metal Divider inquiry site's AI customer-service
surface (visitor widget, operator desk, staging). Owner decision: the site has
no login or registration; an email is enough for follow-up; the owner keeps
chat records permanently; visitors should be able to see the sources behind an
answer, as Knocket does.

## Decision

- **Do not gate the online chat behind email.** A visitor can start, ask, and
  receive an answer immediately, with no account and no email.
- **Ask once, after value.** The optional email card appears only after a real
  visitor message and a later bot or operator answer. It is dismissible and
  never blocks the composer.
- **Store the email on the chat.** `lh_chat.email` is the one place the desk
  reads. There is no second profile table.
- **No automated transcript email.** The copy says the team can follow up; it
  does not promise an email copy of the conversation.
- **Keep the transcript permanently on the server.** LHC's cleanup cron is
  disabled and both age settings are "N"; this is the owner's choice.
- **Resume is browser-local.** The last chat URL is kept in `localStorage`, not
  a cookie and not a server-side visitor identity. Clearing browser storage
  removes the resume link.
- **No visitor file upload.** `active_user_upload` is false; operator uploads
  remain available inside the desk.

## Evidence

| Product | What it does | What it supports here |
| --- | --- | --- |
| [Knocket web widget](https://knocket.trtc.io/products/web-widget/) | "Do visitors need to sign up to chat? No sign-up." Offline mode collects email/phone; custom user tags can carry an email. | Chat must start without an account. Email belongs to the offline/lead path, not a chat gate. |
| [Intercom: require leads to give email](https://www.intercom.com/help/en/articles/2301967-require-leads-to-give-their-email-to-start-a-conversation) | Requiring email "Always" reduces conversation volume by around 30% on average; "Only outside of office hours" by around 5%. Intercom recommends the latter. | A hard gate costs conversations. Collect after the first useful answer instead. |
| [Chatwoot pre-chat forms](https://www.chatwoot.com/hc/user-guide/articles/1677688647-how-to-use-pre-chat-forms) and [website settings](https://www.chatwoot.com/hc/user-guide/articles/1678735779-how-to-use-website-settings) | Pre-chat fields have a per-field required toggle; "Enable email collect box" is on by default and prompts once the customer starts a conversation. | An optional after-start prompt is an established pattern. |
| [Tidio: remove chat history](https://help.tidio.com/hc/en-us/articles/12425531041052-How-visitors-can-remove-their-chat-history) | History is kept in browser `localStorage`, not cookies. | Browser-local resume is normal; no account is needed. |
| [Crisp: GDPR compliance](https://help.crisp.chat/en/article/how-to-be-legally-compliant-to-your-customers-with-crisp-1ih9v85/) | History is kept by default; deletion is a manual compliance action; a policy should state what is kept. | Permanent retention is valid when stated; deletion is a manual desk action. |
| [Live Helper Chat start form](https://doc.livehelperchat.com/docs/chat/start-chat-form-settings) | Start fields, including email, are configurable and optional. | The native form can stay email-free. |
| [Chatwoot fair-use retention](https://www.chatwoot.com/fair-use-policy) | Hacker 30 days, Startups 1 year, Business 2 years, Enterprise 3 years or contract. | Retention is a policy choice; this site chooses indefinite retention. |

## Implementation contract

- Endpoint: `POST /wp-json/md/v1/chat/email`, body
  `{"chat_id": <int>, "hash": "<40-char chat hash>", "email": "<address>"}`.
  The hash is the capability; a wrong hash is refused. Addresses are limited
  to 100 characters, validated, and stored on `lh_chat.email`.
- Success: `{"ok": true, "email_saved": true}`; the card hides.
- Failures: 400 invalid address, 403 wrong hash, 404 missing chat, 409 closed
  or anonymised chat, 429 one attempt per minute per address/chat, 500 write
  failure. The visitor sees a short message; no internal detail leaks.
- The card is inserted between the chat header and the message list, is
  labelled by its heading, and has a status live region. It is not a modal.
- Resume link: the start page reads `mdv:lastChat` and shows "Continue your
  last conversation" only for a `/chat/` URL that contains
  `/chatwidgetchat/`. No expiry; a stale link is acceptable.

## Retention and uploads (verified on staging)

- `lh_chat_config`: `cleanup_cronjob = 0`,
  `mdays_older = N`, `mdays_older_visitor = N`.
- `lh_chat_file` has no rows; `active_user_upload` is false; there is no file
  input in the visitor chat.
- Emails are personal data. The prompt says: "Optional. We only use it to
  reply to this inquiry. No account needed." The desk already shows the
  address in visitor details. The site does not yet have a privacy-policy page,
  so the prompt deliberately links none rather than pointing at a 404.

## Why not put email on the start form

It is the simplest change, but it contradicts both the owner's "leaving an
email is enough for later follow-up" intent and the strongest evidence:
Intercom's measured ~30% loss, Knocket's no-signup chat, and Chatwoot's
default after-start prompt. The chosen design keeps the inquiry path at zero
friction and asks only when the visitor has already received something useful.

## Verification

- Unit tests for the pure validators and the route.
- Staging: valid save writes `lh_chat.email`, the desk shows it, a reload no
  longer prompts, invalid input is refused without a write, the resume link
  opens the same chat, and the browser console has no errors.
- The probe email is removed from the chat after the acceptance run; the test
  chat itself is deleted if it was created only for the run.
