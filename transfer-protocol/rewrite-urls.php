<?php

require_once __DIR__ . "/../class-wp-html-token.php";
require_once __DIR__ . "/../class-wp-html-span.php";
require_once __DIR__ . "/../class-wp-html-text-replacement.php";
require_once __DIR__ . "/../class-wp-html-decoder.php";
require_once __DIR__ . "/../class-wp-html-attribute-token.php";

require_once __DIR__ . "/../class-wp-html-decoder.php";
require_once __DIR__ . "/../class-wp-html-tag-processor.php";
require_once __DIR__ . "/../class-wp-html-open-elements.php";
require_once __DIR__ . "/../class-wp-token-map.php";
require_once __DIR__ . "/../html5-named-character-references.php";
require_once __DIR__ . "/../class-wp-html-active-formatting-elements.php";
require_once __DIR__ . "/../class-wp-html-processor-state.php";
require_once __DIR__ . "/../class-wp-html-unsupported-exception.php";
require_once __DIR__ . "/../class-wp-html-processor.php";

require_once __DIR__ . "/../class-wp-xml-decoder.php";
require_once __DIR__ . "/../class-wp-xml-tag-processor.php";
require_once __DIR__ . "/../class-wp-xml-processor.php";


$block_markup = file_get_contents(__DIR__ . '/married.html');

$p = new WP_Block_Markup_Processor($block_markup);
for ($i = 0; $i < 15; $i++) {
    $p->next_token();
    if($p->get_token_type() === '#block-comment') {
        $updated_attrs = map_block_attributes($p->get_block_attributes(), function($key, $value) {
            if($key === 'url') {
                return 'https://example.com';
            }
            return $value;
        });
        $p->set_block_attributes($updated_attrs);
        echo substr($p->get_updated_html(), 0, 100) . "\n";
        die();
    }
}

function map_block_attributes(array $attributes, $mapper)
{
    $new_attributes = array();
    foreach ($attributes as $key => $value) {
        if (is_array($value)) {
            $new_attributes[$key] = map_block_attributes($value, $mapper);
        } else {
            $new_attributes[$key] = $mapper($key, $value);
        }
    }
    return $new_attributes;
}

class URL_Processor {

    private $string;

    const STATE_LOOKING_FOR_URL = 'looking-for-url';
    const STATE_URL = 'url';

    private $starts_at;
    private $ends_at;

    public function __construct($string)
    {
        $this->string = $string;
    }

    /**
     * 
     * URL syntax is defined in RFC 3986, section 3:
     * https://datatracker.ietf.org/doc/html/rfc3986#section-3
     * 
     * 3.  Syntax Components
     * 
     * The generic URI syntax consists of a hierarchical sequence of
     * components referred to as the scheme, authority, path, query, and
     * fragment.
     * 
     * URI         = scheme ":" hier-part [ "?" query ] [ "#" fragment ]
     * hier-part   = "//" authority path-abempty
     *             / path-absolute
     *             / path-rootless
     *             / path-empty
     * 
     * @return void
     */
    public function next_url()
    {
        $this->starts_at = null;
        $this->ends_at = null;
        $this->state = self::STATE_LOOKING_FOR_URL;

        /*
         * A relative reference starts with a double slash and takes
         * advantage of the hierarchical syntax to use the same scheme
         * as the currently visited resource.
         * https://datatracker.ietf.org/doc/html/rfc3986#section-4.2
         */


        $url_pattern = '/
            # Match an opening tag
            (?:https?:)?            # A protocol part of the URL is optional.
                                    # Absolute URLs starts with one, but protocol-relative URLs do not.
                                    # This processor is only interested in http and https.
            \/\/                    # Two slashes – protocol-relative URLs start with those

                                    # Important! Anything from now on may use punycode
                                    # and percent encoding

            userinfo@host:port/path?query#fragment

        /xu';
        // preg_match(
        //     '~(?:(https?)://|www\.)[-a-zA-Z0-9@:%._\+\~#=]+(?:\.[a-zA-Z0-9]{2,})+[-a-zA-Z0-9@:%_\+.\~#?&//=]*~',
        //     $this->string,
        //     $matches,
        //     PREG_OFFSET_CAPTURE
        // );

        // An absolute URL starts with a scheme, and the two schemes this
        // processor is interested in are http and https.
        // A scheme cannot contain percent encoding.

        // A protocol-relative URL starts with two slashes.

        // scheme      = ALPHA *( ALPHA / DIGIT / "+" / "-" / "." )
        
    }

    public function get_updated_string()
    {
        return $this->string;
    }

    public function set_url($new_url)
    {
        if($this->state !== self::STATE_URL) {
            _doing_it_wrong(
                __METHOD__,
                __( 'Cannot set a URL when not in `url` state' ),
                'WP_VERSION'
            );
            return false;
        }

        // @TODO: defer these like in WP_HTML_Tag_Processor
        $this->string = substr_replace($this->string, $new_url, $this->starts_at, $this->ends_at - $this->starts_at);
    }

}

    /**
     * @TODO: Investigate how bad this is – would it stand the test of time, or do we need
     *        a proper URL-matching state machine?
     */
    // const URL_REGEXP = '\b((?:(https?):\/\/|www\.)[-a-zA-Z0-9@:%._\+\~#=]+(?:\.[a-zA-Z0-9]{2,})+[-a-zA-Z0-9@:%_\+.\~#?&//=]*)\b';
    // private function process_as_plaintext($text) {
    //     return preg_replace_callback(
    //         '~'.self::URL_REGEXP.'~',
    //         function ($matches) {
    //             $this->found_urls[$matches[0]] = true;
    //             $replacer = $this->rewrite_url_callback;
    //             return $replacer($matches[0]);
    //         },
    //         $text
    //     );
    // }