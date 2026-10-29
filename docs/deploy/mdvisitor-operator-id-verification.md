# mdvisitor operator identity — deploy & verification

Fixes the W1 drill finding (2026-10-29, GOAL.md "W1 在线转人工演练"): operator replies
rendered identically to bot messages and the chat header stayed on `AI assistant`.

## What changed

Three files inside `lhc-extensions/mdvisitor` only (no LHC core edits, no DB writes):

1. `design/mdvisitortheme/css/mdvisitor-chat.css`
   - Human operator messages (`.message-row.message-admin[data-op-id]` with a positive id)
     get a gold bubble outline (`#c9a962`, the existing brand gold) and a small name label
     above the bubble — the native `.op-nick-title` span, previously hidden by the blanket
     `.usr-tit{display:none!important}` rule. Bot rows render `data-op-id="-2"` and visitor
     rows carry `.response`, so neither matches; their look is unchanged.
   - Label color `#7a6438` and gold `#c9a962` are existing palette values from this file —
     no new colors introduced.
2. `design/mdvisitortheme/tpl/lhchat/part/chat_status.tpl.php`
   - Server-side: ACTIVE chip label `Team online` → `Metal Divider team` (tone `online`
     unchanged). Stylesheet link bumped to `?v=20261029a`.
   - Inline `sync()`: operator detection no longer filters "generic" names (that regex is
     what kept the header on `AI assistant` — the staging operator's support name is
     `Metal Divider Support`, which the old filter dropped). Detection now prefers the
     message rows themselves: any `.message-row.message-admin` whose `data-op-id` is a
     positive integer means a human operator message exists. `.op-name-widget` from the
     operator profile remains a secondary name source. Title = operator support name
     (`lh_msg.name_support` → LHC user `chat_nickname` when set); chip = `Metal Divider team`
     + online dot. AI and pending states are unchanged.
3. `README.md` — two sentences updated to match (header states + operator label).

Discriminators this rides on (LHC 4.97, verified on staging): `lh_msg.user_id` — `0` visitor
(`.response`), `-2` bot (`.message-admin[data-op-id="-2"]`), `-1` system (not rendered to the
visitor), `>0` human operator (`.message-admin[data-op-id="<uid>"]`). `name_support` =
`chat_nickname` if set, else `name + surname` (user model magic getter). `is_online` from
`transfertohuman` is hardcoded true upstream and is deliberately not used.

## Deploy sequence (staging, then production)

From the repo root (the worktree that has this commit checked out):

```bash
# 1. Copy the two changed assets (same paths on both targets)
rsync -av lhc-extensions/mdvisitor/design/mdvisitortheme/css/mdvisitor-chat.css \
    hostinger-metal-divider:/home/u388076051/domains/staging.metal-divider.com/public_html/chat/extension/mdvisitor/design/mdvisitortheme/css/
rsync -av lhc-extensions/mdvisitor/design/mdvisitortheme/tpl/lhchat/part/chat_status.tpl.php \
    hostinger-metal-divider:/home/u388076051/domains/staging.metal-divider.com/public_html/chat/extension/mdvisitor/design/mdvisitortheme/tpl/lhchat/part/

# 2. Clear the compiled-template / cache layers so the tpl change takes effect
ssh hostinger-metal-divider 'cd /home/u388076051/domains/staging.metal-divider.com/public_html/chat &&
  rm -rf cache/compiled_templates/* cache/cacheconfig/* 2>/dev/null; true'
```

(Production repeats the same two paths under the production chat directory once staging passes.)

## Verification (agent, staging, opencli over real Chrome)

Precondition: one operator account logged into the LHC back office (staging user id 1,
support name currently `Metal Divider Support`).

1. **Bot stage unchanged** — open the widget from `staging.metal-divider.com`, ask a question,
   let the bot answer. Expect: header `Metal Divider AI` + chip `AI assistant`; bot bubble
   keeps the neutral `#e5dfd1` outline and NO name label (bot rows are `data-op-id="-2"`).
2. **Transfer to human** — type the transfer trigger ("talk to a person"), accept from the
   back office, and reply once. Expect in the SAME live session (no reload):
   - the operator bubble shows a gold outline and the name label `Metal Divider Support`
     above it (`document.querySelectorAll('#messages .message-row.message-admin[data-op-id]:not([data-op-id="-2"])')`
     length ≥ 1; its `.op-nick-title.textContent` is the label text);
   - header title becomes `Metal Divider Support` and the chip becomes `Metal Divider team`
     with the green dot (`document.getElementById('mdv-chat-title').textContent`,
     `.mdv-chat-state` classList contains `mdv-chat-state-online`).
3. **Reload persistence** — reload the chat page at ACTIVE status: server render keeps the
   same title/chip (chip label from the PHP branch, `?v=20261029a` in the stylesheet URL).
4. **Personal-name path (optional, proves no hardcoding)** — set the operator's
   `chat_nickname` (e.g. `Chester`) in the back office and send a new operator message:
   the new message's label and the header use `Chester` with no code change. Revert the
   nickname afterwards.
5. **Closed state** — close the chat from the back office: title `Metal Divider Support`,
   chip `Chat closed`, labels/outline remain on past operator messages.

Pass criteria: all five checks match; no console errors from the inline script; visitor and
bot bubbles visually unchanged from before this change.

## Rollback

`git revert <this-commit>` on the branch, rsync the same two files back to the target, and
clear the template cache again (same rm command). No DB or settings changes to undo — the
feature is fully contained in the two files plus README.

## Notes

- The start page (`chatwidget.tpl.php`) still shows its static `AI assistant · Replies
  instantly` status line; operator messages cannot exist there, so it is intentionally
  untouched.
- The extension's `preview/mdvisitor-preview.html` does not model message rows; no preview
  update is required.
