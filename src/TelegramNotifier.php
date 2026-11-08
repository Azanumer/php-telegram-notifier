<?php
/**
 * TelegramNotifier — dependency-free Telegram Bot API notifier.
 *
 * Send messages (plain text or HTML/MarkdownV2) to a chat from any PHP
 * project — server alerts, order notifications, cron job results — with
 * no SDK and no Composer dependency.
 *
 *   $bot = new TelegramNotifier( getenv( 'TELEGRAM_BOT_TOKEN' ) );
 *   $bot->sendMessage( getenv( 'TELEGRAM_CHAT_ID' ), '<b>Backup done</b> in 42s', [
 *       'parse_mode' => 'HTML',
 *   ] );
 *
 * @license MIT
 */

declare(strict_types=1);

class TelegramNotifierException extends RuntimeException {}

class TelegramNotifier {
	/** @var string Telegram Bot API base URL (no trailing slash). */
	const API_BASE = 'https://api.telegram.org';

	/** @var string Bot token as given by @BotFather (digits:letters). */
	private $token;

	/** @var string|int|null Default chat id used when a call omits one. */
	private $defaultChatId;

	/** @var int HTTP timeout in seconds. */
	private $timeout;

	/**
	 * @param string          $botToken      Bot token from @BotFather.
	 * @param string|int|null $defaultChatId Default chat id (optional).
	 * @param int             $timeout       HTTP timeout in seconds (default 10).
	 *
	 * @throws TelegramNotifierException When the token does not look like a bot token.
	 */
	public function __construct( string $botToken, $defaultChatId = null, int $timeout = 10 ) {
		$botToken = trim( $botToken );
		// Bot tokens are "<bot id>:<35-char secret>" — reject anything else early,
		// so a typo surfaces here instead of as a confusing 401 from Telegram.
		if ( ! preg_match( '/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $botToken ) ) {
			throw new TelegramNotifierException( 'Invalid Telegram bot token format (expected "<bot id>:<secret>").' );
		}
		if ( null !== $defaultChatId && ! $this->isValidChatId( $defaultChatId ) ) {
			throw new TelegramNotifierException( 'Invalid default chat id.' );
		}
		$this->token         = $botToken;
		$this->defaultChatId = $defaultChatId;
		$this->timeout       = max( 1, $timeout );
	}

	/**
	 * Send a message to a chat.
	 *
	 * @param string|int|null $chatId  Chat id, or null to use the default.
	 * @param string          $text    Message text (1–4096 chars; longer text is rejected by Telegram).
	 * @param array           $options Optional keys:
	 *                                 - parse_mode: 'HTML' | 'MarkdownV2' | null
	 *                                 - disable_web_page_preview: bool
	 *                                 - disable_notification: bool (silent message)
	 *                                 - message_thread_id: int (forum topic id)
	 *                                 - reply_to_message_id: int
	 *
	 * @return array Telegram result object (message_id, date, chat, ...).
	 *
	 * @throws TelegramNotifierException On transport failure or Telegram error.
	 */
	public function sendMessage( $chatId, string $text, array $options = [] ): array {
		$chatId = $chatId ?? $this->defaultChatId;
		if ( ! $this->isValidChatId( $chatId ) ) {
			throw new TelegramNotifierException( 'A valid chat id is required (pass one or set a default).' );
		}
		if ( '' === trim( $text ) ) {
			throw new TelegramNotifierException( 'Message text must not be empty.' );
		}
		if ( mb_strlen( $text, 'UTF-8' ) > 4096 ) {
			throw new TelegramNotifierException( 'Message text exceeds Telegram\'s 4096-character limit.' );
		}
		if ( isset( $options['parse_mode'] ) && ! in_array( $options['parse_mode'], array( 'HTML', 'MarkdownV2' ), true ) ) {
			throw new TelegramNotifierException( 'parse_mode must be "HTML" or "MarkdownV2".' );
		}

		$payload = array(
			'chat_id' => $chatId,
			'text'    => $text,
		);
		foreach ( array( 'parse_mode', 'disable_web_page_preview', 'disable_notification', 'message_thread_id', 'reply_to_message_id' ) as $key ) {
			if ( array_key_exists( $key, $options ) ) {
				$payload[ $key ] = $options[ $key ];
			}
		}

		return $this->callApi( 'sendMessage', $payload );
	}

	/** Send a plain-text message (no parse mode). */
	public function sendText( $chatId, string $text, array $options = [] ): array {
		unset( $options['parse_mode'] );
		return $this->sendMessage( $chatId, $text, $options );
	}

	/** Send an HTML-formatted message (<b>, <i>, <code>, <a href="…">, …). */
	public function sendHtml( $chatId, string $text, array $options = [] ): array {
		$options['parse_mode'] = 'HTML';
		return $this->sendMessage( $chatId, $text, $options );
	}

	/** Send a silent message (no sound/vibration on the recipient's phone). */
	public function sendSilent( $chatId, string $text, array $options = [] ): array {
		$options['disable_notification'] = true;
		return $this->sendMessage( $chatId, $text, $options );
	}

	/**
	 * Verify the token and return the bot's info (id, username, name).
	 * Useful as a setup check — call it once after creating the bot.
	 *
	 * @return array Bot info from Telegram.
	 *
	 * @throws TelegramNotifierException On transport failure or Telegram error.
	 */
	public function getMe(): array {
		return $this->callApi( 'getMe', array() );
	}

	/**
	 * Escape text for use inside an HTML parse-mode message.
	 * Telegram's HTML mode only allows <b>, <i>, <u>, <s>, <a>, <code>,
	 * <pre>, <tg-spoiler> and <blockquote> — everything else must be escaped.
	 */
	public static function escapeHtml( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Escape text for MarkdownV2 parse mode (Telegram requires escaping
	 * _ * [ ] ( ) ~ ` > # + - = | { } . ! outside of entities).
	 */
	public static function escapeMarkdownV2( string $text ): string {
		return (string) preg_replace( '/([_\*\[\]\(\)~`>#+\-=|{}.!])/', '\\\\$1', $text );
	}

	/** Chat ids are integers (user/group ids) or "@channelusername" strings. */
	private function isValidChatId( $chatId ): bool {
		if ( is_int( $chatId ) ) {
			return true;
		}
		if ( is_string( $chatId ) ) {
			return (bool) preg_match( '/^-?\d+$/', $chatId ) || (bool) preg_match( '/^@[A-Za-z0-9_]{5,}$/', $chatId );
		}
		return false;
	}

	/**
	 * POST to a Bot API method and return the decoded result.
	 * A 429 (flood control) is retried once after the Retry-After delay
	 * Telegram returns in the response body.
	 *
	 * @throws TelegramNotifierException
	 */
	private function callApi( string $method, array $payload ): array {
		$url  = self::API_BASE . '/bot' . $this->token . '/' . $method;
		$body = json_encode( $payload );
		if ( false === $body ) {
			throw new TelegramNotifierException( 'Could not JSON-encode the request payload.' );
		}

		$attempts = 0;
		do {
			$attempts++;
			$response = $this->postJson( $url, $body );
			$decoded  = json_decode( $response, true );
			if ( ! is_array( $decoded ) || ! array_key_exists( 'ok', $decoded ) ) {
				throw new TelegramNotifierException( 'Unexpected response from Telegram.' );
			}
			if ( true === $decoded['ok'] ) {
				return (array) $decoded['result'];
			}
			$code        = (int) ( $decoded['error_code'] ?? 0 );
			$description = (string) ( $decoded['description'] ?? 'Unknown Telegram error' );
			// Flood control: wait the requested delay, then try once more.
			if ( 429 === $code && 1 === $attempts && isset( $decoded['parameters']['retry_after'] ) ) {
				sleep( min( 60, max( 1, (int) $decoded['parameters']['retry_after'] ) ) );
				continue;
			}
			throw new TelegramNotifierException( "Telegram API error {$code}: {$description}", $code );
		} while ( $attempts < 2 );

		throw new TelegramNotifierException( 'Telegram request failed after retry.' );
	}

	/** POST a JSON body; cURL when available, file_get_contents fallback otherwise. */
	private function postJson( string $url, string $body ): string {
		if ( function_exists( 'curl_init' ) ) {
			$ch = curl_init( $url );
			curl_setopt_array( $ch, array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_HTTPHEADER     => array( 'Content-Type: application/json' ),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => $this->timeout,
			) );
			$result = curl_exec( $ch );
			$error  = curl_error( $ch );
			curl_close( $ch );
			if ( false === $result ) {
				throw new TelegramNotifierException( 'cURL error: ' . $error );
			}
			return (string) $result;
		}

		$context = stream_context_create( array(
			'http' => array(
				'method'  => 'POST',
				'header'  => "Content-Type: application/json\r\n",
				'content' => $body,
				'timeout' => $this->timeout,
			),
			'ssl'  => array(
				'verify_peer'      => true,
				'verify_peer_name' => true,
			),
		) );
		$result = file_get_contents( $url, false, $context );
		if ( false === $result ) {
			throw new TelegramNotifierException( 'HTTP request to Telegram failed (no cURL, file_get_contents fallback failed).' );
		}
		return (string) $result;
	}
}
