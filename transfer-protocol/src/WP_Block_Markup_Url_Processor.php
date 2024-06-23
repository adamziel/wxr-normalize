<?php

use Rowbot\URL\URL;

/**
 * Reports all the URLs in the imported post and enables rewriting them.
 */
class WP_Block_Markup_Url_Processor extends WP_Block_Markup_Processor {

	private $url;
	private $base_url;
	private $inspected_attribute_idx = - 1;

	public function __construct( $html, $base_url = null ) {
		parent::__construct( $html );
		$this->base_url = $base_url;
	}

	public function next_url() {
		do {
			if ( true === $this->next_url_in_current_token() ) {
				return true;
			}
		} while ( $this->next_token() !== false );

		return false;
	}

	public function get_url() {
		return $this->url;
	}

	private function next_url_in_current_token() {
		switch ( parent::get_token_type() ) {
			case '#tag':
				return $this->next_url_attribute();
			case '#block-comment':
				return $this->next_url_block_attribute();
				break;
			case '#text':
				return $this->next_url_in_text_node();
				break;
		}
	}

	public function next_token() {
		$this->url                       = null;
		$this->inspected_attribute_idx   = - 1;
		$this->block_attributes_iterator = null;

		return parent::next_token();
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

	private function next_url_attribute() {
		$tag = $this->get_tag();
		if (
			! array_key_exists( $tag, self::URL_ATTRIBUTES ) &&
			$tag !== 'INPUT' // type=image => src,
		) {
			return false;
		}

		while ( true ) {
			++ $this->inspected_attribute_idx;
			if ( $this->inspected_attribute_idx >= count( self::URL_ATTRIBUTES[ $tag ] ) ) {
				return false;
			}
			$this->url = $this->get_attribute(
				self::URL_ATTRIBUTES[ $tag ][ $this->inspected_attribute_idx ]
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

	/**
	 * @var \RecursiveIteratorIterator
	 */
	private $block_attributes_iterator;
	private $current_block_attribute_key = null;
	private $current_block_attribute_value = null;

	private function next_url_block_attribute() {
		if ( null === $this->block_attributes || 0 === count( $this->block_attributes ) ) {
			return false;
		}

		if ( null === $this->block_attributes_iterator ) {
			// Re-entrant iteration over the block attributes.
			$this->block_attributes_iterator = new \RecursiveIteratorIterator(
				new \RecursiveArrayIterator( $this->block_attributes ),
				\RecursiveIteratorIterator::SELF_FIRST,
				\RecursiveIteratorIterator::LEAVES_ONLY
			);
		} else {
			$this->block_attributes_iterator->next();
		}

		do {
			$url_maybe = $this->block_attributes_iterator->current();
			// @TODO: Investigate why LEAVES_ONLY isn't enough
			if ( is_array( $url_maybe ) ) {
				$this->block_attributes_iterator->next();
				continue;
			}
			if ( URL::canParse( $url_maybe, $this->base_url ) ) {
				$this->current_block_attribute_key   = $this->block_attributes_iterator->key();
				$this->current_block_attribute_value = $url_maybe;
				$this->url                           = $url_maybe;

				return true;
			}
			$this->block_attributes_iterator->next();
		} while ( $this->block_attributes_iterator->valid() );

		return false;
	}

	public function get_current_block_attribute_key() {
		if ( null === $this->block_attributes_iterator || null === $this->current_block_attribute_key ) {
			return false;
		}

		return $this->current_block_attribute_key;
	}

	public function get_current_block_attribute_value() {
		if ( null === $this->block_attributes_iterator || null === $this->current_block_attribute_key ) {
			return false;
		}
		if ( null === $this->current_block_attribute_value ) {
			$this->current_block_attribute_value = $this->block_attributes_iterator->current();
		}

		return $this->current_block_attribute_value;
	}

}
