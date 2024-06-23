<?php

use Rowbot\URL\URL;

/**
 * Finds URLs in text nodes.
 *
 * Looks for URLs:
 * * Starting with http:// or https://
 * * Starting with //
 * * Domain-only, e.g. www.example.com
 * * Domain + path, e.g. www.example.com/path
 *
 * ### Domain names
 *
 * UTF-8 characters in the domain names are supported even if they're
 * not encoded as punycode. For example, scanning the text:
 *
 * > Więcej na łąka.pl
 *
 * Would yield `łąka.pl`
 *
 * ### Paths
 *
 * The path is limited to ASCII characters, as per the URL specification.
 * For example, scanning the text:
 *
 * > Visit the WordPress plugins directory https://w.org/plugins?łąka=1
 *
 * Would yield `https://w.org/plugins?`, not `https://w.org/plugins?łąka=1`.
 * However, scanning this text:
 *
 * > Visit the WordPress plugins directory https://w.org/plugins?%C5%82%C4%85ka=1
 *
 * Would yield `https://w.org/plugins?%C5%82%C4%85ka=1`.
 *
 * ### Parenthesis treatment
 *
 * This scanner captures parentheses as a part of the path, query, or fragment, except
 * when they're seen as the last character in the URL. For example, scanning the text:
 *
 * > Visit the WordPress plugins directory (https://w.org/plugins)
 *
 * Would yield `https://w.org/plugins`, but scanning the text:
 *
 * > Visit the WordPress plugins directory (https://w.org/plug(in)s
 *
 * Would yield `https://w.org/plug(in)s`.
 *
 */
class WP_URL_In_Text_Processor {

	private $text;
	private $bytes_already_parsed;
	private $url;
	private $base_url = 'https://w.org';
	private $regex;

	private $strict = false;

	static private $public_suffix_list;

	/**
	 * Characters that are forbidden in the host part of a URL.
	 * See https://url.spec.whatwg.org/#host-miscellaneous.
	 */
	private const FORBIDDEN_HOST_BYTES = "\x00\x09\x0a\x0d\x20\x23\x2f\x3a\x3c\x3e\x3f\x40\x5b\x5c\x5d\x5e\x7c";
	private const FORBIDDEN_DOMAIN_BYTES = "\x00\x09\x0a\x0d\x20\x23\x25\x2f\x3a\x3c\x3e\x3f\x40\x5b\x5c\x5d\x5e\x7c\x7f";
	/**
	 * Unlike RFC 3986, the WHATWG URL specification does not the domain part of
	 * a URL to any length. That being said, we apply an arbitrary limit here as
	 * an optimization to avoid scanning the entire text for a domain name.
	 *
	 * Rationale: Domains larger than 1KB are extremely rare. The WHATWG URL
	 */
	private const CONSIDER_DOMAINS_UP_TO_BYTES = 1024;

	public function __construct( $text ) {
		if ( ! self::$public_suffix_list ) {
			self::$public_suffix_list = require_once __DIR__ . '/public_suffix_list.php';
		}
		$this->bytes_already_parsed = 0;
		$this->text                 = $text;
		// A reverse string is useful for lookups. It does not form a valid
		// text since strrev doesn't support UTF-8, but that's okay. We're
		// only interested in the byte positions.
		// $this->text_rev = strrev($text);

		$prefix = $this->strict ? '^' : '';
		$suffix = $this->strict ? '$' : '';

		// Source: https://github.com/vstelmakh/url-highlight/blob/master/src/Matcher/Matcher.php
		$this->regex = '/' . $prefix . '
            (?|                                                        # scheme
                (?<scheme>[a-z][\w\-]+):\/{2}                              # scheme ending with :\/\/
                |                                                          # or
                (?<scheme>mailto):                                         # mailto
            )?
            (?:                                                        # userinfo
                (?:
                    (?<=\/{2})                                             # prefixed with \/\/
                    |                                                      # or
                    (?=[^\p{Sm}\p{Sc}\p{Sk}\p{P}])                         # start with not: mathematical, currency, modifier symbol, punctuation
                )
                (?<userinfo>[^\s<>@\/]+)                                   # not: whitespace, < > @ \/
                @                                                          # at
            )?
            (?=[^\p{Z}\p{Sm}\p{Sc}\p{Sk}\p{C}\p{P}])                   # followed by valid host char
            (?|                                                        # host
                (?<host>                                                   # host prefixed by scheme or userinfo (less strict)
                    (?<=\/{2}|@)                                               # prefixed with \/\/ or @
                    (?=[^\-])                                                  # label start, not: -
                    (?:[^\p{Z}\p{Sm}\p{Sc}\p{Sk}\p{C}\p{P}]|-){1,63}           # label not: whitespace, mathematical, currency, modifier symbol, control point, punctuation | except -
                    (?<=[^\-])                                                 # label end, not: -
                    (?:                                                        # more label parts
                        \.
                        (?=[^\-])                                                  # label start, not: -
                        (?<tld>(?:[^\p{Z}\p{Sm}\p{Sc}\p{Sk}\p{C}\p{P}]|-){1,63})   # label not: whitespace, mathematical, currency, modifier symbol, control point, punctuation | except -
                        (?<=[^\-])                                                 # label end, not: -
                    )*
                )
                |                                                          # or
                (?<host>                                                   # host with tld (no scheme or userinfo)
                    (?=[^\-])                                                  # label start, not: -
                    (?:[^\p{Z}\p{Sm}\p{Sc}\p{Sk}\p{C}\p{P}]|-){1,63}           # label not: whitespace, mathematical, currency, modifier symbol, control point, punctuation | except -
                    (?<=[^\-])                                                 # label end, not: -
                    (?:                                                        # more label parts
                        \.
                        (?=[^\-])                                                  # label start, not: -
                        (?:[^\p{Z}\p{Sm}\p{Sc}\p{Sk}\p{C}\p{P}]|-){1,63}           # label not: whitespace, mathematical, currency, modifier symbol, control point, punctuation | except -
                        (?<=[^\-])                                                 # label end, not: -
                    )*                                                             
                    \.(?<tld>\w{2,63})                                         # tld
                )
            )
            (?:\:(?<port>\d+))?                                        # port
            (?<path>                                                   # path, query, fragment
                [\/?]                                                  # prefixed with \/ or ?
                [^\s<>]*                                               # any chars except whitespace and <>
                (?<=[^\s<>({\[`!;:\'".,?«»“”‘’])                       # end with not a space or some punctuation chars
            )?
        ' . $suffix . '/ixuJ';
	}

	/**
	 * @return string
	 */
	public function next_url() {
		$this->url = null;
		while ( true ) {
			$matches = [];
			$found   = preg_match( $this->regex, $this->text, $matches, PREG_OFFSET_CAPTURE, $this->bytes_already_parsed );
			if ( 1 !== $found ) {
				return false;
			}

			$url = $matches[0][0];
			if (
				$url[ strlen( $url ) - 1 ] === ')' ||
				$url[ strlen( $url ) - 1 ] === '.'
			) {
				$url = substr( $url, 0, - 1 );
			}
			$this->bytes_already_parsed = $matches[0][1] + strlen( $url );

			if ( ! URL::canParse( $url, $this->base_url ) ) {
				continue;
			}

			$this->url = $url;
			return true;
		}
	}

	public function get_url() {
		if ( null === $this->url ) {
			return false;
		}

		return $this->url;
	}

	public function set_url( $new_url ) {


	}

}


//public function next_url_2() {
//	$at = $this->bytes_already_parsed;
//
//	// Find the next dot in the text
//	$dot_at = strpos($this->text, '.', $at);
//
//	// If there's no dot, assume there's no URL
//	if(false === $dot_at) {
//		return false;
//	}
//
//	// The shortest tld is 2 characters long
//	if($dot_at + 2 >= strlen($this->text)) {
//		return false;
//	}
//
//	$host_bytes_after_dot = strcspn(
//		$this->text,
//		self::FORBIDDEN_DOMAIN_BYTES,
//		$dot_at + 1,
//		self::CONSIDER_DOMAINS_UP_TO_BYTES
//	);
//
//	if(0 === $host_bytes_after_dot) {
//		return false;
//	}
//
//	// Lookbehind to capture the rest of the domain name up to a forbidden character.
//	$host_bytes_before_dot = strcspn(
//		$this->text_rev,
//		self::FORBIDDEN_DOMAIN_BYTES,
//		strlen($this->text) - $dot_at - 1,
//		self::CONSIDER_DOMAINS_UP_TO_BYTES
//	);
//
//	$host_starts_at = $dot_at - $host_bytes_before_dot;
//
//	// Capture the protocol, if any
//	$has_double_slash = false;
//	if($host_starts_at > 2) {
//		if ( '/' === $this->text[ $host_starts_at - 1 ] && '/' === $this->text[ $host_starts_at - 2 ] ) {
//			$has_double_slash = true;
//		}
//	}
//
//	/**
//	 * Look for http or https at the beginning of the URL.
//	 * @TODO: Ensure the character before http or https is a word boundary.
//	 */
//	$has_protocol = false;
//	if($has_double_slash && (
//			(
//				$host_starts_at >= 6 &&
//				'h' === $this->text[$host_starts_at - 6] &&
//				't' === $this->text[$host_starts_at - 5] &&
//				't' === $this->text[$host_starts_at - 4] &&
//				'p' === $this->text[$host_starts_at - 3]
//			) ||
//			(
//				$host_starts_at >= 7 &&
//				'h' === $this->text[$host_starts_at - 7] &&
//				't' === $this->text[$host_starts_at - 6] &&
//				't' === $this->text[$host_starts_at - 5] &&
//				'p' === $this->text[$host_starts_at - 4] &&
//				's' === $this->text[$host_starts_at - 3]
//			)
//		)) {
//		$has_protocol = true;
//	}
//
//	// Move the pointer to the end of the host
//	$at = $dot_at + $host_bytes_after_dot;
//
//
//
//}
