# mdvisitor

Staging-only Live Helper Chat 4.97 visitor widget skin for Metal Divider. It does not add routes, database tables, or a second chat backend, and it does not edit LHC core.

## What it overrides

LHC resolves an enabled extension's `design/<theme>/tpl` before the active theme (`lib/core/lhcore/lhdesign.php`, `designtpl`). These two overrides are therefore global for every widget that uses the default template names:

- `design/mdvisitortheme/tpl/lhchat/chatwidget.tpl.php` — Knocket-style start page. The native start form contract is unchanged: `method="post"`, action `chat/chatwidget` plus the native `department` / `priority` / `vid` / `hash_resume` / `leaveamessage` / `offline` / theme appends, hidden `onlyBotOnline`, `URLRefer`, `r`, `operator`, `StartChat=1`, and the native `onsubmit` branch (`lhinst.prestartChat` when `message_auto_start`, otherwise `lhinst.addCaptchaSubmit`).
- `design/mdvisitortheme/tpl/lhchat/part/chat_status.tpl.php` — the native 4.97 status partial, plus a Knocket-style header (avatar, `Metal Divider AI`, live status chip, close control) and one stylesheet link. It adds no message HTML and no send/poll script. A small inline MutationObserver keeps the chip in sync with the native status row (AI assistant / Connecting you to the team / Metal Divider team once a human operator message exists or the operator profile appears / Chat closed).

Styles and the start-page script are extension assets. Their URLs are built from `erLhcoreClassSystem::instance()->wwwDir()`, not `baseurl()`, because `baseurl()` injects `index.php`.

`bootstrap/bootstrap.php` only declares `erLhcoreClassExtensionMdvisitor::run()`. The method is empty.

## Start page

Charcoal/brass is the default hero. `?mdvtheme=forest` sets `data-theme="forest"` on the card. Quick Connect cards, common-question chips, and every FAQ row write into the native `Question` field and focus it. They do not submit. The FAQ screen lists questions only; it carries no answer copy. The round control beside the question is the native start-chat send partial, which submits `#form-start-chat`. Home and FAQ are in-page views, online and offline. The Chat tab is not a separate view: before a chat exists it returns to Home and focuses the same native question field, because there is no conversation to show yet. After the native form submits, LHC navigates to the chat page and the start-page tabs are gone. Escape closes the FAQ view first; on Home it closes the card through the parent postMessage, falling back to `window.close()` when no parent origin resolves.

The question textarea keeps the native `user_msg_height` inline style when that setting is greater than zero. That is the only inline style, and it is copied so a configured composer height still applies. Everything else is in the CSS files.

## Offline

Outside the department duty window (staging: 01:00–13:00 UTC = 09:00–21:00 Beijing), the offline card says the desk is closed and does not claim an instant reply. The form underneath is LHC's native leave-message form: same action, same `onsubmit` (`lhinst.addCaptcha`), same field names (`Username`, `Email`, `Phone`, `Question`, terms, department, file). This theme does not add a second handler.

The offline card keeps the same Home/Chat/FAQ tabs and FAQ list. FAQ rows prefill the native leave-message question field and never submit it.

The card does not appear by itself. Two staging settings have to be on; both are applied on staging as of 2026-10-28:

- `lh_chat_config.start_chat_data.force_leave_a_message` must be true. The admin checkbox is `ForceLeaveMessage` (`lib/core/lhchat/lhchatadminvalidatorhelper.php:850-852`, label in `design/defaulttheme/tpl/lhchat/startchatformsettings/form.tpl.php:49`). `modules/lhchat/chatwidget.php:288` copies it into `$leaveamessage`. Staging has no `lh_chat_start_settings` row, so the global `start_chat_data` row is the one that counts (`chatwidget.php:172` then `:176-177`). Staging value: true.
- Department 1 `bot_configuration.bot_foh` must be true. The checkbox is "Bot follows online hours" (`design/defaulttheme/tpl/lhdepartment/parts/bot_configuration.tpl.php:49`, written at `lib/core/lhdepartament/lhdepartament.php:786-789`). Without it, `erLhcoreClassChat::isOnline` returns true whenever `bot_id` is set (`lib/core/lhchat/lhchat.php:1127` and `:1191`), including outside the duty window. Department 1 has `bot_id` 3 and `bot_foh` true on staging.

Duty hours are the department weekday columns (`lh_departament.mod_start_hour` … `sud_end_hour`), every day start `100` end `1300`, compared as `date('G').date('i')` against the server clock (`lhchat.php:1088-1090`); the custom-work-hours table is empty. The server zone is UTC, so that window is 09:00–21:00 Beijing. With both settings on, the widget's else branch (`chatwidget.tpl.php`) includes the offline form. With either still off, a visitor at 01:30 Beijing still gets the online Welcome form, or, once the bot stops covering the hours but leave-a-message stays off, the core "Chat is currently unavailable" include.

A submitted message is a pending chat, not a closed ticket. `saveOfflineRequest` (`lib/core/lhchat/lhchatvalidator.php:2227`) writes `lh_chat.status` `0` (`STATUS_PENDING_CHAT`), `status_sub` `7` (`STATUS_SUB_OFFLINE_REQUEST`), `time` and `pnd_time` as `time()`, and the question as `lh_msg` with `user_id` `-1`. It does not dispatch `chat.addmsguser`. The controller then dispatches `chat.chat_offline_request_saved` (`modules/lhchat/chatwidget.php:360-397`).

The WeCom connector accepts that one event and cards it immediately, because it is not a later visitor message. It still ignores every other event. The staging deployment applies the required row; the extension itself does not write it:

- `lh_webhook` has rows for `chat.genericbot_chat_command_transfer`, `chat.chat_transfered`, `chat.addmsguser`, and (staging row 4) `chat.chat_offline_request_saved` with `trigger_id` `1` and `disabled` `0`. Trigger 1 already posts to `/wp-json/md/v1/wecom/notify`. Missing `{{args.is_online}}` and `{{args.action.content.command}}` render as JSON `null` (`lhgenericbotactionrestapi.php:1055`), so the existing body stays valid.
- Do not create `lh_chat_config.offline_settings` with `do_not_save_offline` `1` (the message is not stored) or `close_offline` `1` (the chat is closed before the event, and the connector refuses status `2`). There is no `offline_settings` row today. `suggest_leave_msg` is `1` and is not the form switch.

Acceptance on 2026-10-28 (real Chrome via opencli): the native submit created `lh_chat` 14 with `status` 0, `status_sub` 7, `nick` Staging B1 and `pnd_time` 1793124175; the connector recorded `{"14":1793124176}` and 企业微信 showed `Live chat #14 — Staging B1`. Chat 14 and its notification entry were then removed; current staging keeps chats 1/2/9 only, and `md_wecom_notify_chats` is `{"9":1793121891}`. The mdmobile transcript initially showed literal `[b]...[/b]`; `erLhcoreClassMdMobile::format()` now strips BBCode/HTML before rendering. Evidence: `.scratch/verify-161b1/`.

## Chat page

`mdvisitor-chat.css` only restyles the server-rendered widget chat page (`body.widget-chat`): header, status/operator row, `#messages`, `.msgBlock`, message rows, `#CSChatMessage`, and `#lhc-send-icon`. The native status row is hidden because the header chip carries the same state; the native Typing bubble and operator profile block are hidden as well. The header title starts as `Metal Divider AI`; once a human operator message exists (a `.message-row.message-admin` row with a positive `data-op-id` — bot rows render `-2`) or the operator profile appears, the title switches to the operator's support name (`lh_msg.name_support`, so it follows the LHC user's chat nickname) and the chip reads `Metal Divider team` with the online tone; a closed chat becomes `Metal Divider Support` plus `Chat closed`. The avatar dot follows the chip tone (AI/online green, pending gold, closed grey). Human operator messages also get a gold bubble outline and a small name label above the bubble (the native `.op-nick-title`), so they no longer look identical to bot messages. It does not change send/poll JavaScript or message markup. The chat page does not receive `mdvtheme`; after submit, LHC navigates to `chatwidgetchat` without that query. The chat skin is charcoal. A forest chat skin would need a core or route change, which this extension does not add.

## Preview

`preview/mdvisitor-preview.html` is a same-origin staging page, not the production embed. Open it on the staging host that serves `/chat/`. The launcher is a black pill, "Ask Metal Divider", with a gold MD mark and an online dot. The card is 400×680 and contains an iframe to `/chat/index.php/chat/chatwidget/(department)/1`; the preview appends a cache-buster so a reload does not serve an older widget document. Under 560px the card is full screen. The iframe close control posts the core string `lhc_chat_closed_explicit` to the parent origin resolved from `window.location.ancestorOrigins` (falling back to the referrer origin). It never posts to `*`. When no parent origin resolves, it calls `window.close()` instead of doing nothing. The preview page accepts that string, hides the card and restores focus to the launcher. The preview page has no second close button of its own; Escape also hides the card.

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
- Offline mode includes the native `lhchat/offline_form.tpl.php` inside a Knocket card. The include keeps the native field names, hidden inputs, captcha `onsubmit`, and submit. See Offline above for the settings that actually show that card.
- An LHC upgrade that changes `chatwidget.tpl.php`, `chat_status.tpl.php`, or the chat message class names (`.message-row.response`, `.message-row.message-admin`, `#CSChatMessage`, `#lhc-send-icon`) will need this override re-diffed. Extension template priority means a stale override keeps winning after an upgrade.
- The preview iframe assumes department id `1` exists on staging. If it does not, the start page will show LHC's department error inside the card.
- The staging acceptance used real Chrome at 390, 400×680 and 1440 widths. The screenshots, DOM/DB readbacks, and the B0 run log live under `.scratch/verify-162/`, outside this tracked directory. The tracked extension is not a substitute for that evidence; re-run the acceptance after changing any template, CSS or JS.
