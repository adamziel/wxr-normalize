<?php

use Rowbot\URL\URL;

/**
 * Reports all the URLs in the imported post and enables rewriting them.
 */
class WP_Block_Markup_Url_Processor extends WP_Block_Markup_Processor {

	private $url;
	private $base_url;
	private $url_in_text_processor;
	private $url_in_text_node_updated;
	private $inspected_url_attribute_idx = - 1;

	public function __construct( $html, $base_url = null ) {
		parent::__construct( $html );
		$this->base_url = $base_url;
	}

	public function get_updated_html() {
		if ( $this->url_in_text_node_updated ) {
			$this->set_modifiable_text( $this->url_in_text_processor->get_updated_text() );
			$this->url_in_text_node_updated = false;
			$this->url_in_text_processor    = null;
		}

		return parent::get_updated_html();
	}

	public function get_url() {
		return $this->url;
	}

	public function next_token() {
		$this->url                      = null;
		$this->inspected_url_attribute_idx = - 1;
		// Do not reset url_in_text_node_updated – it's reset in get_updated_html() which
		// is called in parent::next_token().

		return parent::next_token();
	}

	public function next_url() {
		do {
			if ( true === $this->next_url_in_current_token() ) {
				return true;
			}
		} while ( $this->next_token() !== false );

		return false;
	}

	public function next_url_in_current_token() {
		switch ( parent::get_token_type() ) {
			case '#tag':
				return $this->next_url_attribute();
			case '#block-comment':
				return $this->next_url_block_attribute();
			case '#text':
				return $this->next_url_in_text_node();
		}
	}

	public function next_url_in_text_node() {
		if ( $this->get_token_type() !== '#text' ) {
			return false;
		}

		if ( null === $this->url_in_text_processor ) {
			$this->url_in_text_processor = new WP_Migration_URL_In_Text_Processor( $this->get_modifiable_text() );
		}

		if ( ! $this->url_in_text_processor->next_url() ) {
			return false;
		}

		$this->url = $this->url_in_text_processor->get_url();

		return true;
	}

	private function next_url_attribute() {
		$tag = $this->get_tag();
		if (
			! array_key_exists( $tag, self::URL_ATTRIBUTES ) &&
			$tag !== 'INPUT' // type=image => src,
		) {
			return false;
		}

		while ( true ) {
			++ $this->inspected_url_attribute_idx;
			if ( $this->inspected_url_attribute_idx >= count( self::URL_ATTRIBUTES[ $tag ] ) ) {
				return false;
			}
			$this->url = $this->get_attribute(
				self::URL_ATTRIBUTES[ $tag ][ $this->inspected_url_attribute_idx ]
			);
			if ( $this->url !== null ) {
				break;
			}
		}

		if ( null === $this->url ) {
			return false;
		}

		return true;
	}

	private function next_url_block_attribute() {
		while($this->next_block_attribute()){
			$url_maybe = $this->get_block_attribute_value();
			if ( URL::canParse( $url_maybe, $this->base_url ) ) {
				$this->url                           = $url_maybe;
				return true;
			}
		}
		return false;
	}

	public function set_url( $new_url ) {
		if ( null === $this->url ) {
			return false;
		}
		switch ( parent::get_token_type() ) {
			case '#tag':
				$tag = $this->get_tag();
				if ( ! array_key_exists( $tag, self::URL_ATTRIBUTES ) ) {
					return false;
				}
				if ( ! array_key_exists( $this->inspected_url_attribute_idx, self::URL_ATTRIBUTES[ $tag ] ) ) {
					return false;
				}
				$this->set_attribute(
					self::URL_ATTRIBUTES[ $tag ][ $this->inspected_url_attribute_idx ],
					$new_url
				);

				return true;

			case '#block-comment':
				return $this->set_block_attribute_value( $new_url );

			case '#text':
				if ( null === $this->url_in_text_processor ) {
					return false;
				}
				$this->url_in_text_node_updated = true;
				return $this->url_in_text_processor->set_url( $new_url );
		}
	}


	/**
	 * A list of HTML attributes meant to contain URLs, as defined in the HTML specification.
	 * It includes some deprecated attributes like `lowsrc` and `highsrc` for the `IMG` element.
	 *
	 * See https://html.spec.whatwg.org/multipage/indices.html#attributes-1.
	 * See https://stackoverflow.com/questions/2725156/complete-list-of-html-tag-attributes-which-have-a-url-value.
	 *
	 */
	public const URL_ATTRIBUTES = [
		'A'          => [ 'href' ],
		'APPLET'     => [ 'codebase', 'archive' ],
		'AREA'       => [ 'href' ],
		'AUDIO'      => [ 'src' ],
		'BASE'       => [ 'href' ],
		'BLOCKQUOTE' => [ 'cite' ],
		'BODY'       => [ 'background' ],
		'BUTTON'     => [ 'formaction' ],
		'COMMAND'    => [ 'icon' ],
		'DEL'        => [ 'cite' ],
		'EMBED'      => [ 'src' ],
		'FORM'       => [ 'action' ],
		'FRAME'      => [ 'longdesc', 'src' ],
		'HEAD'       => [ 'profile' ],
		'HTML'       => [ 'manifest' ],
		'IFRAME'     => [ 'longdesc', 'src' ],
		// SVG <image> element
		'IMAGE'      => [ 'href' ],
		'IMG'        => [ 'longdesc', 'src', 'usemap', 'lowsrc', 'highsrc' ],
		'INPUT'      => [ 'src', 'usemap', 'formaction' ],
		'INS'        => [ 'cite' ],
		'LINK'       => [ 'href' ],
		'OBJECT'     => [ 'classid', 'codebase', 'data', 'usemap' ],
		'Q'          => [ 'cite' ],
		'SCRIPT'     => [ 'src' ],
		'SOURCE'     => [ 'src' ],
		'TRACK'      => [ 'src' ],
		'VIDEO'      => [ 'poster', 'src' ],
	];

	/**
	 * @TODO: Either explicitly support these attributes, or explicitly drop support for
	 *        handling their subsyntax. A generic URL matcher might be good enough.
	 */
	public const URL_ATTRIBUTES_WITH_SUBSYNTAX = [
		'*'      => [ 'style' ], // background(), background-image()
		'APPLET' => [ 'archive' ],
		'IMG'    => [ 'srcset' ],
		'META'   => [ 'content' ],
		'SOURCE' => [ 'srcset' ],
		'OBJECT' => [ 'archive' ],
	];

	/**
	 * Also <style> and <script> tag content can contain URLs.
	 * <style> has specific syntax rules we can use for matching, but perhaps a generic matcher would be good enough?
	 *
	 * <style>
	 * #domID { background:url(https://mysite.com/wp-content/uploads/image.png) }
	 * </style>
	 *
	 * @TODO: Either explicitly support these tags, or explicitly drop support for
	 *         handling their subsyntax. A generic URL matcher might be good enough.
	 */
	public const URL_CONTAINING_TAGS_WITH_SUBSYNTAX = [
		'STYLE',
		'SCRIPT',
	];

}
