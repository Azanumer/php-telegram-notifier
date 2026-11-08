# PHP Telegram Notifier

Dependency-free Telegram Bot API notifier for PHP — send server alerts, order notifications and cron job reports to a Telegram chat with no SDK and no Composer dependency.

## Features

- **Zero dependencies** — cURL when available, `file_get_contents` fallback otherwise; PHP 7.4+.
- **Plain, HTML and MarkdownV2 messages** — `sendText()`, `sendHtml()`, `sendSilent()` helpers, plus `escapeHtml()` / `escapeMarkdownV2()` so user data can't break your markup.
- **Validated early** — bot-token format and chat-id format are checked in the constructor, so a typo surfaces immediately instead of as a confusing 401.
- **Flood-control aware** — a 429 from Telegram is retried once after the `retry_after` delay it returns.
- **Typed errors** — every failure throws `TelegramNotifierException` (transport errors, Telegram error codes, 4096-char limit, bad parse mode).
- **`getMe()`** — verify the token and print the bot's username as a setup check.

## Installation

Copy `src/TelegramNotifier.php` into your project and require it:

```php
require 'src/TelegramNotifier.php';
```

## Usage

```php
$bot = new TelegramNotifier( getenv( 'TELEGRAM_BOT_TOKEN' ), getenv( 'TELEGRAM_CHAT_ID' ) );

// Plain text
$bot->sendText( null, 'Nightly backup completed in 42 seconds.' );

// HTML formatting (escape user data first!)
$host = TelegramNotifier::escapeHtml( gethostname() );
$bot->sendHtml( null, "<b>Server alert</b>\nDisk usage on <code>{$host}</code> is at <b>91%</b>." );

// Silent message (no notification sound) with a forum thread id
$bot->sendMessage( null, 'Routine check passed.', [
    'disable_notification' => true,
    'message_thread_id'    => 123,
] );

// Verify the token
$me = $bot->getMe(); // ['id' => ..., 'username' => 'my_bot', ...]
```

Every send returns Telegram's result object (`message_id`, `date`, `chat`, …).

## Setup

1. Talk to **@BotFather** on Telegram, `/newbot`, copy the token.
2. Message your bot once, then open `https://api.telegram.org/bot<TOKEN>/getUpdates` and read `message.chat.id`. For a channel, use `@channelusername` and add the bot as an admin.
3. Run the example: `TELEGRAM_BOT_TOKEN=… TELEGRAM_CHAT_ID=… php examples/telegram-example.php`

## Notes

- Telegram caps messages at 4096 characters — longer text throws before the request is sent.
- Chat ids are integers, or `@channelusername` for channels the bot administers.
- MarkdownV2 needs special characters escaped — use `escapeMarkdownV2()`; unescaped `_ * [ ] ( )` will make Telegram reject the message.

## License

MIT — see [LICENSE](LICENSE).
