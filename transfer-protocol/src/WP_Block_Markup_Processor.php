<?php

/**
 * Detects and rewrites URLs in block markup.
 *
 * ## Design choices
 *
 * ### No streaming
 *
 * This class loads the entire block markup into memory without streaming.
 * If the post cannot fit into memory, WordPress won't be able to render it
 * anyway.
 */
class WP_Block_Markup_Processor extends WP_HTML_Tag_Processor
{

    private $block_name;
    protected $block_attributes;
    private $block_closer;

	public function get_token_type() {
		switch ( $this->parser_state ) {
            case self::STATE_COMMENT:
                if(null !== $this->block_name) {
                    return '#block-comment';
                }
                return '#comment';

			default:
				return parent::get_token_type();
		}
	}

    public function next_token()
    {
        $this->block_name = null;
        $this->block_attributes = null;
        $this->block_closer = false;

        if(parent::next_token() === false) {
            return false;
        }

        if (parent::get_token_type() !== '#comment') {
            return true;
        }

        $text = parent::get_modifiable_text();
        // Try to parse as a block. The block parser won't cut it because
        // while it can parse blocks, it has no semantics for rewriting the
        // block markup. Let's do our best here:
        $at = strspn($text, ' \t\f\r\n'); // Whitespace

        if($at >= strlen($text)) {
            // This is an empty comment. Not a block.
            return true;
        }

        // Blocks closers start with the solidus character (`/`)
        if ('/' === $text[$at]) {
            $this->block_closer = true;
            ++$at;
        }

        // Blocks start with wp:
        if (!(
            $at + 3 < strlen($text) &&
            $text[$at] === 'w' &&
            $text[$at + 1] === 'p' &&
            $text[$at + 2] === ':'
        )) {
            return true;
        }

        $name_starts_at = $at;

        // Skip wp:
        $at += 3;

        // Parse the actual block name after wp:
        $name_length = strspn($text, 'abcdefghijklmnopqrstuwxvyzABCDEFGHIJKLMNOPRQSTUWXVYZ0123456789_-', $at);
        if ($name_length === 0) {
            // This wasn't a block after all, just a regular comment.
            return true;
        }
        $name = substr($text, $name_starts_at, $name_length + 3);
        $at += $name_length;

        // Skip the whitespace that follows the block name
        $at += strspn($text, ' \t\f\r\n', $at);
        if ($at >= strlen($text)) {
            // It's a block without attributes.
            $this->block_name = $name;
            return true;
        }

        // It seems we may have block attributes here.

        // Block closers cannot have attributes.
        if($this->block_closer) {
            return true;
        }

        // Let's try to parse them as JSON.
        $json_maybe = substr($text, $at);
        $attributes = json_decode($json_maybe, true);
        if (null === $attributes || !is_array($attributes)) {
            // This comment looked like a block comment, but the attributes didn't
            // parse as a JSON array. This means it wasn't a block after all.
            return true;
        }

        // We have a block name and a valid attributes array. We may not find a block
        // closer, but let's assume is a block and process it as such.
        // @TODO: Confirm that WordPress block parser would have parsed this as a block.
        $this->block_name = $name;
        $this->block_attributes = $attributes;

        return true;
    }

    /**
     * Returns the name of the block if the current token is a block comment.
     *
     * @return string|false
     */
    public function get_block_name()
    {
        if(null === $this->block_name) {
            return false;
        }
        return $this->block_name;
    }

    public function get_block_attributes()
    {
        if(null === $this->block_attributes) {
            return false;
        }
        return $this->block_attributes;
    }

    public function is_block_closer()
    {
        return $this->block_name !== null && $this->block_closer === true;
    }

    public function set_block_attributes(array $new_attributes)
    {
        if(null === $this->block_name) {
            _doing_it_wrong(
                __METHOD__,
                __( 'Cannot set block attributes when not in `block_attributes` state' ),
                'WP_VERSION'
            );
            return false;
        }

        $this->block_attributes = $new_attributes;
        $this->set_modifiable_text(
            $this->block_name . ' ' .
            json_encode(
                $new_attributes,
                JSON_HEX_TAG | // Convert < and > to \u003C and \u003E
                JSON_HEX_AMP   // Convert & to \u0026
            )
        );
    }

    /**
     * Don't do this at home :-) Changes access to private properties of the
     * WP_HTML_Tag_Processor class to enable changing the text content of a
     * node.
     *
     * @param mixed $new_content
     * @return bool
     */
    private function set_modifiable_text($new_value) {
        $reflection = new ReflectionClass('WP_HTML_Tag_Processor');
        $accessible_text_starts_at = $reflection->getProperty('text_starts_at');
        $accessible_text_starts_at->setAccessible(true);

        $accessible_text_length = $reflection->getProperty('text_length');
        $accessible_text_length->setAccessible(true);

        $lexical_updates = $reflection->getProperty('lexical_updates');
        $lexical_updates->setAccessible(true);

        switch ( parent::get_token_type() ) {
            case '#text':
                $lexical_updates_now = $lexical_updates->getValue($this);
                $lexical_updates_now[] = new WP_HTML_Text_Replacement(
                    $accessible_text_starts_at->getValue($this),
                    $accessible_text_length->getValue($this),
                    htmlspecialchars( $new_value, ENT_XML1, 'UTF-8' )
                );
                $lexical_updates->setValue($this, $lexical_updates_now);
                return true;

            case '#comment':
            case '#cdata-section':
                if(
                    parent::get_token_type() === '#comment' && (
                        strpos($new_value, '-->') !== false ||
                        strpos($new_value, '--!>') !== false
                    )
                ) {
                    _doing_it_wrong(
                        __METHOD__,
                        __( 'Cannot set a comment closer as a text of an HTML comment.' ),
                        'WP_VERSION'
                    );
                    return false;
                }
                if(
                    $this->get_token_type() === '#cdata-section' &&
                    strpos($new_value, '>') !== false
                ) {
                    _doing_it_wrong(
                        __METHOD__,
                        __( 'Cannot set a CDATA closer as text of an HTML CDATA-lookalike section.' ),
                        'WP_VERSION'
                    );
                    return false;
                }
                $lexical_updates_now = $lexical_updates->getValue($this);
                $lexical_updates_now[] = new WP_HTML_Text_Replacement(
                    $accessible_text_starts_at->getValue($this),
                    $accessible_text_length->getValue($this),
                    $new_value
                );
                $lexical_updates->setValue($this, $lexical_updates_now);
                return true;
            default:
                _doing_it_wrong(
                    __METHOD__,
                    __( 'Cannot set text content on a non-text node.' ),
                    'WP_VERSION'
                );
                return false;
        }
    }
}
