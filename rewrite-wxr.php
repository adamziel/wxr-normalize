<?php
/**
 * Rewrites URLs in a WXR file while keeping track of the URLs found.
 * 
 * This is a huge deal! It unlocks fast, streamed, resumable, fault-tolerant 
 * WordPress data migrations through WXR files AND directly between sites.
 * 
 * In particular, this script:
 * 
 * * Lists all the URLs found in the XML document
 * * Rewrites the domain found in each URL while considering the context
 *   in which it was found (text nodes, cdata, block attributes, HTML attributes, HTML text)
 *
 * With these, we can:
 * 
 * * Stream-process the WXR export 
 * * Pipe from ZipStreamReader [1] to stream-read directly from a zip
 * * Pipe from AsyncHttp\Client to stream-read directly from a remote data source
 * * Start downloading the assets upfront with a configurable degree of parallelization
 * * Pipe write the rewritten output to another WXR file
 * * Pipe to ZipStreamWriter [2] to stream-write directly to a zip
 * * Pipe to AsyncHttp\Client to stream-write directly to a remote data source
 *
 * [1] ZipStreamReader https://github.com/WordPress/blueprints-library/blob/f9fcb5816ab6def0920b25787341342bc88803e3/src/WordPress/Zip/ZipStreamReader.php
 * [2] ZipStreamWriter: https://github.com/WordPress/blueprints-library/blob/f9fcb5816ab6def0920b25787341342bc88803e3/src/WordPress/Zip/ZipStreamWriter.php
 * [3] AsyncHttpClient: https://github.com/WordPress/blueprints-library/blob/trunk/src/WordPress/AsyncHttp/Client.php
 */

// Where to find the streaming WP_XML_Processor 
// Use a version from this PR: https://github.com/adamziel/wordpress-develop/pull/43
define('WP_XML_API_PATH', __DIR__ );
if(!file_exists(WP_XML_API_PATH . '/class-wp-token-map.php')) {
    copy(WP_XML_API_PATH.'/../class-wp-token-map.php', WP_XML_API_PATH . '/class-wp-token-map.php');
}

$requires[] = WP_XML_API_PATH . "/class-wp-html-token.php";
$requires[] = WP_XML_API_PATH . "/class-wp-html-span.php";
$requires[] = WP_XML_API_PATH . "/class-wp-html-text-replacement.php";
$requires[] = WP_XML_API_PATH . "/class-wp-html-decoder.php";
$requires[] = WP_XML_API_PATH . "/class-wp-html-attribute-token.php";

$requires[] = WP_XML_API_PATH . "/class-wp-html-decoder.php";
$requires[] = WP_XML_API_PATH . "/class-wp-html-tag-processor.php";
$requires[] = WP_XML_API_PATH . "/class-wp-html-open-elements.php";
$requires[] = WP_XML_API_PATH . "/class-wp-token-map.php";
$requires[] = WP_XML_API_PATH . "/html5-named-character-references.php";
$requires[] = WP_XML_API_PATH . "/class-wp-html-active-formatting-elements.php";
$requires[] = WP_XML_API_PATH . "/class-wp-html-processor-state.php";
$requires[] = WP_XML_API_PATH . "/class-wp-html-unsupported-exception.php";
$requires[] = WP_XML_API_PATH . "/class-wp-html-processor.php";

$requires[] = WP_XML_API_PATH . "/class-wp-xml-decoder.php";
$requires[] = WP_XML_API_PATH . "/class-wp-xml-tag-processor.php";
$requires[] = WP_XML_API_PATH . "/class-wp-xml-processor.php";

foreach ($requires as $require) {
    require_once $require;
}

if (!Phar::running() && in_array('--bundle', $argv)) {
    bundlePhar('preprocess-wxr.phar', array_merge(
        [__FILE__],
        $requires
    ));
    echo "Bundled as preprocess-wxr.phar\n";
    exit(0);
}

function bundlePhar($pharFile, $fileList) {
    // Check if the PHAR file already exists and delete it
    if (file_exists($pharFile)) {
        unlink($pharFile);
    }

    try {
        // Create the PHAR archive
        $phar = new Phar($pharFile);

        // Start buffering
        $phar->startBuffering();

        // Add each file in the file list to the PHAR archive
        foreach ($fileList as $file) {
            if (file_exists($file)) {
                $phar->addFile($file, basename($file));
            } else {
                throw new Exception("File $file does not exist.");
            }
        }

        // Set the stub (entry point of the PHAR file)
        $defaultStub = $phar->createDefaultStub(basename(__FILE__));
        $stub = "#!/usr/bin/env php \n" . $defaultStub;
        $phar->setStub($stub);

        // Stop buffering
        $phar->stopBuffering();

        echo "PHAR file created successfully.\n";
    } catch (Exception $e) {
        echo "Could not create PHAR: ", $e->getMessage(), "\n";
    }
}

// Don't change anything below

// These aren't supported yet but will be:
$args = parseArguments($argv);

define('WXR_PATH', $args['wxr']);
define('NEW_ORIGIN', $args['new-origin'] ?? 'https://playground.internal');
define('NEW_ASSETS_PREFIX', $args['new-assets-prefix']);
define('WRITE_IMAGES_TO_DIRECTORY', $args['downloads-path']);

function parseArguments($argv) {
    $options = [
        'wxr' => null,
        'new-origin' => null,
        'new-assets-prefix' => null,
        'downloads-path' => null
    ];

    foreach ($argv as $arg) {
        if (strpos($arg, '--wxr=') === 0) {
            $options['wxr'] = substr($arg, 6);
        } elseif (strpos($arg, '--new-origin=') === 0) {
            $options['new-origin'] = rtrim(substr($arg, 13), '/');
        } elseif (strpos($arg, '--new-assets-prefix=') === 0) {
            $options['new-assets-prefix'] = rtrim(substr($arg, 19), '/').'/';
        } elseif (strpos($arg, '--downloads-path=') === 0) {
            $options['downloads-path'] = rtrim(substr($arg, 17), '/').'/';
        }
    }

    if(!$options['wxr'] || !$options['new-assets-prefix'] || !$options['downloads-path']) {
        fwrite(STDERR, "Usage: php preprocess-wxr.php --wxr=<path-to-wxr-file> --new-assets-prefix=<new-assets-prefix> --downloads-path=<downloads-path>\n");
        exit(1);
    }

    return $options;
}

// Gather all the URLs from a WXR file
$input_stream = fopen(WXR_PATH, 'rb+');
$output_stream = fopen('/dev/null', 'wb+');
$normalizer = new WP_WXR_Normalizer(
    $input_stream,
    $output_stream,
    function ($url) { return $url; }
);
$normalizer->process();
fclose($input_stream);
fclose($output_stream);


fwrite(STDERR, "Downloading assets...\n\n");
$urls = $normalizer->get_found_urls();
fwrite(STDERR, print_r($urls, true));

// Download the ones looking like assets
$assets_details = [];
foreach($urls as $url) {
    $parsed = parse_url($url);
    if(!isset($parsed['path'])) {
        continue;
    }
    $filename = basename($parsed['path']) ?: md5($url);
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    // Only download paths that seem like images
    if(!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'svg', 'css', 'js', 'webp'])) {
        continue;
    }
    $assets_details[$url] = [
        'url' => $url,
        'extension' => $extension,
        'filename' => $filename,
        'download_path' => WRITE_IMAGES_TO_DIRECTORY . '/' . $filename,
    ];
}

$url_to_path = [];
foreach($assets_details as $url => $details) {
    if (!file_exists($details['download_path'])) {
        $url_to_path[$url] = $details['download_path'];
    }
}
download_assets($url_to_path);

// Rewrite the URLs in the WXR file
$input_stream = fopen(WXR_PATH, 'rb+');
$output_stream = fopen('php://stdout', 'wb+');
$normalizer = new WP_WXR_Normalizer(
    $input_stream,
    $output_stream,
    function ($url) use($assets_details) { 
        if(isset($assets_details[$url])) {
            return NEW_ASSETS_PREFIX . $assets_details[$url]['filename'];
        }
        $parsed = parse_url($url);
        if(!isset($parsed['host']) || !isset($parsed['scheme'])) {
            return $url;
        }

        $parsed_origin = parse_url(NEW_ORIGIN);
        $parsed['scheme'] = $parsed_origin['scheme'];
        $parsed['host'] = $parsed_origin['host'];
        return serialize_url($parsed);
    }
);
$normalizer->process();
fclose($input_stream);
fclose($output_stream);

function download_assets($url_to_path) {
    $mh = curl_multi_init();
    $handles = [];
    $window_size = 10;
    $active_handles = 0;
    
    foreach ($url_to_path as $url => $local_path) {
        // Initialize curl handle
        $ch = curl_init($url);
        $fp = fopen($local_path, 'w');
    
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
    
        // Add handle to multi-handle
        curl_multi_add_handle($mh, $ch);
        $handles[(int) $ch] = ['handle' => $ch, 'fp' => $fp];
        $active_handles++;
    
        // When window_size is reached, execute handles
        if ($active_handles == $window_size) {
            do {
                $execrun = curl_multi_exec($mh, $running);
            } while ($execrun == CURLM_CALL_MULTI_PERFORM);
    
            while ($running && $execrun == CURLM_OK) {
                if (curl_multi_select($mh) == -1) {
                    usleep(100);
                }
    
                do {
                    $execrun = curl_multi_exec($mh, $running);
                } while ($execrun == CURLM_CALL_MULTI_PERFORM);
            }
    
            while ($done = curl_multi_info_read($mh)) {
                $handle = $done['handle'];
                $fp = $handles[(int) $handle]['fp'];
    
                curl_multi_remove_handle($mh, $handle);
                curl_close($handle);
                fclose($fp);
    
                unset($handles[(int) $handle]);
                $active_handles--;
            }
        }
    }
    
    // Process any remaining handles
    do {
        $execrun = curl_multi_exec($mh, $running);
    } while ($execrun == CURLM_CALL_MULTI_PERFORM);
    
    while ($running && $execrun == CURLM_OK) {
        if (curl_multi_select($mh) == -1) {
            usleep(100);
        }
    
        do {
            $execrun = curl_multi_exec($mh, $running);
        } while ($execrun == CURLM_CALL_MULTI_PERFORM);
    }
    
    while ($done = curl_multi_info_read($mh)) {
        $handle = $done['handle'];
        $fp = $handles[(int) $handle]['fp'];
    
        curl_multi_remove_handle($mh, $handle);
        curl_close($handle);
        fclose($fp);
    
        unset($handles[(int) $handle]);
    }
    
    curl_multi_close($mh);
}


/**
 * WordPress compat
 */
function esc_attr($text) {
    return htmlspecialchars($text, ENT_XML1, 'UTF-8');
}

function serialize_url($parsedUrl) {
    return (isset($parsedUrl['scheme']) ? $parsedUrl['scheme'] . '://' : '')
            . (isset($parsedUrl['user']) ? $parsedUrl['user'] . (isset($parsedUrl['pass']) ? ':' . $parsedUrl['pass'] : '') .'@' : '')
            . $parsedUrl['host']
            . (isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '')
            . (isset($parsedUrl['path']) ? $parsedUrl['path'] : '')
            . (isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '')
            . (isset($parsedUrl['fragment']) ? '#' . $parsedUrl['fragment'] : '');
}

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

    private function process_content_node($text)
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
