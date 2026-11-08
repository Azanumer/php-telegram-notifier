<?php
/**
 * TelegramNotifier usage examples.
 *
 * Setup:
 *   1. Talk to @BotFather on Telegram, /newbot, copy the token.
 *   2. Message your bot once, then get your chat id:
 *        https://api.telegram.org/bot<TOKEN>/getUpdates
 *      (look for message.chat.id — for a channel, use @channelusername
 *       and add the bot as an admin).
 *   3. Export the values below and run: php telegram-example.php
 *
 *   export TELEGRAM_BOT_TOKEN="123456789:AAE..."
 *   export TELEGRAM_CHAT_ID="123456789"
 */

declare(strict_types=1);

require __DIR__ . '/../src/TelegramNotifier.php';

$token  = getenv( 'TELEGRAM_BOT_TOKEN' );
$chatId = getenv( 'TELEGRAM_CHAT_ID' );

if ( false === $token || '' === $token || false === $chatId || '' === $chatId ) {
	fwrite( STDERR, "Set TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID first.\n" );
	exit( 1 );
}

try {
	$bot = new TelegramNotifier( $token, $chatId );

	// 1. Verify the token — prints the bot's username.
	$me = $bot->getMe();
	echo 'Bot: @' . $me['username'] . ' (' . $me['first_name'] . ")\n";

	// 2. Plain text message.
	$result = $bot->sendText( $chatId, 'Hello from TelegramNotifier — backup finished OK.' );
	echo 'Sent message id ' . $result['message_id'] . "\n";

	// 3. HTML message — user data must be escaped, markup stays intact.
	$hostname = TelegramNotifier::escapeHtml( gethostname() ?: 'unknown host' );
	$result   = $bot->sendHtml(
		$chatId,
		"<b>Server alert</b>\nDisk usage on <code>{$hostname}</code> is at <b>91%</b>."
	);
	echo 'Sent HTML message id ' . $result['message_id'] . "\n";

	// 4. Silent message (no notification sound) — good for routine cron reports.
	$result = $bot->sendSilent( $chatId, 'Nightly backup completed in 42 seconds.' );
	echo 'Sent silent message id ' . $result['message_id'] . "\n";

	// 5. MarkdownV2 with escaping.
	$safe   = TelegramNotifier::escapeMarkdownV2( 'Deploy v2.3.1 [prod] finished!' );
	$result = $bot->sendMessage( $chatId, "*{$safe}*", array( 'parse_mode' => 'MarkdownV2' ) );
	echo 'Sent MarkdownV2 message id ' . $result['message_id'] . "\n";
} catch ( TelegramNotifierException $e ) {
	fwrite( STDERR, 'Telegram error: ' . $e->getMessage() . "\n" );
	exit( 1 );
}
