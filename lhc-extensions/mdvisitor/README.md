# mdvisitor

Staging-only Live Helper Chat 4.97 visitor widget skin for Metal Divider. It does not add routes, database tables, or a second chat backend, and it does not edit LHC core.

## What it overrides

LHC resolves an enabled extension's `design/<theme>/tpl` before the active theme (`lib/core/lhcore/lhdesign.php`, `designtpl`). These two overrides are therefore global for every widget that uses the default template names:

- `design/mdvisitortheme/tpl/lhchat/chatwidget.tpl.php` — Knocket-style start page. The native start form contract is unchanged: `method="post"`, action `chat/chatwidget` plus the native `department` / `priority` / `vid` / `hash_resume` / `leaveamessage` / `offline` / theme appends, hidden `onlyBotOnline`, `URLRefer`, `r`, `operator`, `StartChat=1`, and the native `onsubmit` branch (`lhinst.prestartChat` when `message_auto_start`, otherwise `lhinst.addCaptchaSubmit`).
- `design/mdvisitortheme/tpl/lhchat/part/chat_status.tpl.php` — the native 4.97 status partial, plus a Knocket-style header (avatar, `Metal Divider AI`, live status chip, close control) and one stylesheet link. It adds no message HTML and no send/poll script. A small inline MutationObserver keeps the chip in sync with the native status row (AI assistant / Connecting you to the team / Team online / Chat closed).

Styles and the start-page script are extension assets. Their URLs are built from `erLhcoreClassSystem::instance()->wwwDir()`, not `baseurl()`, because `baseurl()` injects `index.php`.

`bootstrap/bootstrap.php` only declares `erLhcoreClassExtensionMdvisitor::run()`. The method is empty.

## Start page

Charcoal/brass is the default hero. `?mdvtheme=forest` sets `data-theme="forest"` on the card. Quick Connect cards and common-question chips write into the native `Question` field and focus it. They do not submit. The round control beside the question is the native start-chat send partial, which submits `#form-start-chat`. Home / Chat / FAQ are in-page views. Escape closes an open FAQ answer, then the FAQ view. It does not close the parent window.

The question textarea keeps the native `user_msg_height` inline style when that setting is greater than zero. That is the only inline style, and it is copied so a configured composer height still applies. Everything else is in the CSS files.

## Chat page

`mdvisitor-chat.css` only restyles the server-rendered widget chat page (`body.widget-chat`): header, status/operator row, `#messages`, `.msgBlock`, message rows, `#CSChatMessage`, and `#lhc-send-icon`. The native status row is hidden because the header chip carries the same state; the native Typing bubble and operator profile block are hidden as well. It does not change send/poll JavaScript or message markup. The chat page does not receive `mdvtheme`; after submit, LHC navigates to `chatwidgetchat` without that query. The chat skin is charcoal. A forest chat skin would need a core or route change, which this extension does not add.

## Preview

`preview/mdvisitor-preview.html` is a same-origin staging page, not the production embed. Open it on the staging host that serves `/chat/`. The launcher is a black pill, "Ask Metal Divider". The card is 400×680 and contains an iframe to `/chat/index.php/chat/chatwidget/(department)/1`. Under 560px the card is full screen. The close button hides the card. The iframe close control posts `{source:'mdvisitor', type:'mdvisitor-close'}` to `window.location.origin` only.

## Deploy

From this worktree, after review:

```sh
rsync -a --delete lhc-extensions/mdvisitor/ user@staging:/path/to/chat/extension/mdvisitor/
```

Then, in the LHC site config, add `mdvisitor` to `site.extensions` and clear the compiled caches:

```sh
rm -f chat/cache/cacheconfig/moduleCache_*.cache.php chat/cache/cacheconfig/templateCache.cache.php
rm -f chat/cache/compiledtemplates/chatwidget_*.php chat/cache/compiledtemplates/chat_*.php
```

Reload PHP / the web server if opcode cache is enabled. Do not deploy this extension to production from this ticket.

## Revert

Remove `mdvisitor` from `site.extensions`, delete `chat/extension/mdvisitor`, and remove the same two cache files. The next request falls back to the core templates. No database rollback is required.

## Known limitations

- Staging currently hides name, email, and phone. This template still includes those native branches. If a later setting shows one of them, it renders in the composer stack above the question; it is not dropped. The same is true for department (when the URL has no department), product, timezone, and terms.
- If the question field is hidden (`message_hidden`, bot-only hide, or message not visible in the page widget), `$hasExtraField` is set and the native `StartChatAction` button is shown. Do not hide `.btn-group` in that case; it is the only submit control.
- Offline mode still includes `lhchat/offline_form.tpl.php`. That form is not restyled as a Knocket screen.
- The first FAQ answer is open on purpose, matching the prototype. Escape closes it.
- An LHC upgrade that changes `chatwidget.tpl.php`, `chat_status.tpl.php`, or the chat message class names (`.message-row.response`, `.message-row.message-admin`, `#CSChatMessage`, `#lhc-send-icon`) will need this override re-diffed. Extension template priority means a stale override keeps winning after an upgrade.
- The preview iframe assumes department id `1` exists on staging. If it does not, the start page will show LHC's department error inside the card.
- The staging acceptance used a 390px and 400×680 iframe host, and the real session crossed AI reply, human handoff, takeover and close. The screenshots live under `.scratch/verify-162/`, outside this tracked directory.
