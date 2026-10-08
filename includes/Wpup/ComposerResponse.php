<?php
/**
 * A response from Wpup_ComposerRepository. Kept separate from sending so the
 * router can be tested without output or exit().
 */
class Wpup_ComposerResponse {
	/** @var int */
	public $status;
	/** @var array<string,string> */
	public $headers;
	/** @var string */
	public $body;

	public function __construct($status, array $headers = array(), $body = '') {
		$this->status = (int)$status;
		$this->headers = $headers;
		$this->body = (string)$body;
	}

	public static function json($status, array $data) {
		return new self(
			$status,
			array('Content-Type' => 'application/json', 'Cache-Control' => 'no-cache'),
			json_encode($data, JSON_UNESCAPED_SLASHES)
		);
	}

	public static function error($status, $message) {
		return self::json($status, array('error' => $message));
	}

	public function send() {
		http_response_code($this->status);
		foreach ( $this->headers as $name => $value ) {
			header($name . ': ' . $value);
		}
		echo $this->body;
	}
}
