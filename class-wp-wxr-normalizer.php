<?php

class WP_WXR_Normalizer
{

    private $input_stream;
    private $output_stream;
    private $rewrite_url_callback;

    private $found_urls = array();

    public function __construct(
        $input_stream,
        $output_stream,
        $rewrite_url_callback
    ) {
        $this->input_stream = $input_stream;
        $this->output_stream = $output_stream;
        $this->rewrite_url_callback = $rewrite_url_callback;
    }

    public function get_found_urls()
    {
        return array_keys($this->found_urls);        
    }

    public function process()
    {
        $tokens = WP_XML_Processor::stream_tokens($this->input_stream, $this->output_stream, 1000000);
        foreach ($tokens as $processor) {
            if (
                in_array('item', $processor->get_breadcrumbs())
                // $processor->matches_breadcrumbs(array('item', 'content:encoded')) ||
                // $processor->matches_breadcrumbs(array('item', 'excerpt:encoded')) ||
                // $processor->matches_breadcrumbs(array('wp:comment_content'))
            ) {
                switch ($processor->get_token_type()) {
                    case '#text':
                    case '#cdata-section':
                        $text = $processor->get_modifiable_text();
                        $updated_text = $this->process_content_node($text);
                        if ($updated_text !== $text) {
                            $processor->set_modifiable_text($updated_text);
                        }
                        break;
                }
            }
        }
    }

    public function process_content_node($text)
    {
        $result = $this->process_as_html($text);
        if(false !== $result) {
            return $result;
        }

        $result = $this->process_as_plaintext($text);
        if(false !== $result) {
            return $result;
        }

        return false;
    }

    private function process_as_html($text) {
        $html = new WP_HTML_Tag_Processor($text);
        if(false === $html->next_token()) {
            return false;
        }

        do {
            switch($html->get_token_type()) {
                case '#comment':
                    $text = $html->get_modifiable_text();
                    // Try to parse as a block. The block parser won't cut it because
                    // while it can parse blocks, it has no semantics for rewriting the
                    // block markup. Let's do our best here:
                    $at = strspn($text, ' \t\f\r\n'); // Whitespace
                    if(!(
                        $at + 3 < strlen($text) &&
                        $text[$at] === 'w' &&
                        $text[$at+1] === 'p' &&
                        $text[$at+2] === ':'
                    )) {
                        break;
                    }
                    $at += 3;
                    $at += strspn($text, 'abcdefghijklmnopqrstuwxvyzABCDEFGHIJKLMNOPRQSTUWXVYZ0123456789_-', $at); // Block name
                    $at += strspn($text, ' \t\f\r\n', $at); // Whitespace again
                    if($at >= strlen($text)) {
                        // Oh, there were no attributes or this wasn't a block
                        // Either way, we have nothing more to do here.
                        break;
                    }

                    // It seems we may have block attributes here. Let's try to
                    // parse them as JSON.
                    $json_maybe = substr($text, $at);
                    $attributes = json_decode($json_maybe, true);
                    if(null === $attributes) {
                        // This wasn't a block after all, let's move on
                        break;
                    }

                    // This is a block! Let's process all block attributes and rewrite them
                    $new_attributes = $this->process_block_attributes($attributes);
                    $this->set_modifiable_html_text(
                        $html,
                        substr($text, 0, $at) . json_encode($new_attributes, JSON_HEX_TAG | JSON_HEX_AMP)
                    );
                    break;

                case '#tag':
                    $attributes = $html->get_attribute_names_with_prefix('');
                    if(!$attributes) {
                        break;
                    }
                    foreach($attributes as $attribute_name) {
                        $value = $html->get_attribute($attribute_name);
                        $updated = $this->process_as_plaintext($value);
                        if($updated !== $value) {
                            $html->set_attribute($attribute_name, $updated);
                        }
                    }
                    break;
                case '#text':
                    $text = $html->get_modifiable_text();
                    $updated_text = $this->process_as_plaintext($text);
                    if($updated_text !== $text) {
                        $this->set_modifiable_html_text($html, $updated_text);
                    }
                    break;
            }
        } while($html->next_token());

        return $html->get_updated_html();
    }

    private function process_block_attributes($attributes) {
        if(is_string($attributes)) {
            return $this->process_as_plaintext($attributes);
        } else if(is_array($attributes)) {
            $new_attributes = array();
            foreach($attributes as $key => $value) {
                $new_attributes[$key] = $this->process_block_attributes($value);
            }
            return $new_attributes;
        } else {
            return $attributes;
        }
    }

    /**
     * @TODO: Investigate how bad this is – would it stand the test of time, or do we need
     *        a proper URL-matching state machine?
     */
    const URL_REGEXP = '\b((?:(https?):\/\/|www\.)[-a-zA-Z0-9@:%._\+\~#=]+(?:\.[a-zA-Z0-9]{2,})+[-a-zA-Z0-9@:%_\+.\~#?&//=]*)\b';
    private function process_as_plaintext($text) {
        return preg_replace_callback(
            '~'.self::URL_REGEXP.'~',
            function ($matches) {
                $this->found_urls[$matches[0]] = true;
                $replacer = $this->rewrite_url_callback;
                return $replacer($matches[0]);
            },
            $text
        );
    }

    private function set_modifiable_html_text(WP_HTML_Tag_Processor $p, $new_value) {
        $reflection = new ReflectionClass('WP_HTML_Tag_Processor');
        $accessible_text_starts_at = $reflection->getProperty('text_starts_at');
        $accessible_text_starts_at->setAccessible(true);
    
        $accessible_text_length = $reflection->getProperty('text_length');
        $accessible_text_length->setAccessible(true);
    
        $lexical_updates = $reflection->getProperty('lexical_updates');
        $lexical_updates->setAccessible(true);
    
        switch ( $p->get_token_type() ) {
            case '#text':
                $lexical_updates_now = $lexical_updates->getValue($p);
                $lexical_updates_now[] = new WP_HTML_Text_Replacement(
                    $accessible_text_starts_at->getValue($p),
                    $accessible_text_length->getValue($p),
                    htmlspecialchars( $new_value, ENT_XML1, 'UTF-8' )
                );
                $lexical_updates->setValue($p, $lexical_updates_now);
                return true;
    
            case '#comment':
            case '#cdata-section':
                if(
                    $p->get_token_type() === '#comment' && (
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
                    $p->get_token_type() === '#cdata-section' && 
                    strpos($new_value, '>') !== false 
                ) {
                    _doing_it_wrong(
                        __METHOD__,
                        __( 'Cannot set a CDATA closer as text of an HTML CDATA-lookalike section.' ),
                        'WP_VERSION'
                    );
                    return false;
                }
                $lexical_updates_now = $lexical_updates->getValue($p);
                $lexical_updates_now[] = new WP_HTML_Text_Replacement(
                    $accessible_text_starts_at->getValue($p),
                    $accessible_text_length->getValue($p),
                    $new_value
                );
                $lexical_updates->setValue($p, $lexical_updates_now);
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
