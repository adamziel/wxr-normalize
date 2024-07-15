<?php
/**
 * Rewrites site URLs in a remote WXR file.
 * 
 * It pipes and stream-processes data as follows:
 * 
 * AsyncHttp\Client -> WP_XML_Processor -> WP_Block_Markup_Url_Processor -> WP_Migration_URL_In_Text_Processor -> WP_URL
 * 
 * The layers of data we're handling here are:
 * 
 * * AsyncHttp\Client: HTTPS encrypted data -> Chunked encoding -> Gzip compression
 * * WP_XML_Processor: XML (entities, attributes, text, comments, CDATA nodes)
 * * WP_Block_Markup_Url_Processor: HTML (entities, attributes, text, comments, block comments), JSON (in block comments)
 * * WP_Migration_URL_In_Text_Processor: URLs in text nodes
 * * WP_URL: URL parsing and serialization
 * 
 * It wouldn't be difficult to pipe through additioanl layers such as:
 * 
 * * Reading from a remote ZIP file
 * * Writing to a local ZIP-ped XML file
 * * Writing to a database
 * 
 * ...etc.
 */
 
require __DIR__ . '/bootstrap.php';

use \WordPress\AsyncHttp\Client;
use \WordPress\AsyncHttp\Request;

$wxr_url = "https://raw.githubusercontent.com/WordPress/blueprints/normalize-wxr-assets/blueprints/stylish-press-clone/woo-products.wxr";
$xml_processor = new WP_XML_Processor('', [], WP_XML_Processor::IN_PROLOG_CONTEXT);
foreach( stream_remote_file( $wxr_url ) as $chunk ) {
    $xml_processor->stream_append_xml($chunk);
    foreach ( xml_next_content_node_for_rewriting( $xml_processor ) as $text ) {
        $string_new_site_url           = 'https://mynew.site/';
        $parsed_new_site_url           = WP_URL::parse( $string_new_site_url );

        $current_site_url              = 'https://raw.githubusercontent.com/wordpress/blueprints/normalize-wxr-assets/blueprints/stylish-press-clone/wxr-assets/';
        $parsed_current_site_url       = WP_URL::parse( $current_site_url );

        $base_url = 'https://playground.internal';
        $url_processor = new WP_Block_Markup_Url_Processor( $text, $base_url );

        foreach ( html_next_url( $url_processor, $current_site_url ) as $parsed_matched_url ) {
            $updated_raw_url = rewrite_url(
                $url_processor->get_raw_url(),
                $parsed_matched_url,
                $parsed_current_site_url,
                $parsed_new_site_url
            );
            $url_processor->set_raw_url( $updated_raw_url );
        }
        
        $updated_text = $url_processor->get_updated_html();
        if ($updated_text !== $text) {
            $xml_processor->set_modifiable_text($updated_text);
        }
    }
    echo $xml_processor->get_processed_xml();
}
echo $xml_processor->get_unprocessed_xml();

// The rest of this file are functions used in the above code

function stream_remote_file($url)
{
    $requests = [
        new Request($url)
    ];
    $client = new Client();
    $client->enqueue($requests);

    while ($client->await_next_event()) {
        switch ($client->get_event()) {
            case Client::EVENT_BODY_CHUNK_AVAILABLE:
                yield $client->get_response_body_chunk();
                break;
        }
    }
}

function xml_next_content_node_for_rewriting(WP_XML_Processor $processor) {
    while($processor->next_token()) {
        if (!in_array('item', $processor->get_breadcrumbs())) {
            continue;
        }
        if (
            !in_array('excerpt:encoded', $processor->get_breadcrumbs())
            && !in_array('content:encoded', $processor->get_breadcrumbs())
            && !in_array('wp:attachment_url', $processor->get_breadcrumbs())
            && !in_array('guid', $processor->get_breadcrumbs())
            && !in_array('link', $processor->get_breadcrumbs())
            && !in_array('wp:comment_content', $processor->get_breadcrumbs())
            // Meta values are not suppoerted yet. We'll need to support
            // WordPress core options that may be saved as JSON, PHP Deserialization, and XML,
            // and then provide extension points for plugins authors support
            // their own options.
            // !in_array('wp:postmeta', $processor->get_breadcrumbs())
        ) {
            continue;
        }
                
        switch ($processor->get_token_type()) {
            case '#text':
            case '#cdata-section':
                $text = $processor->get_modifiable_text();
                yield $text;
                break;
        }
    }
}

/**
 * 
 * @param mixed $options
 * @return Generator
 */
function html_next_url(WP_Block_Markup_Url_Processor $p, $current_site_url) {
	$parsed_current_site_url       = WP_URL::parse( $current_site_url );
	$decoded_current_site_pathname = urldecode( $parsed_current_site_url->pathname );

	while ( $p->next_url() ) {
		$parsed_matched_url = $p->get_parsed_url();
		if ( $parsed_matched_url->hostname === $parsed_current_site_url->hostname ) {
			$decoded_matched_pathname = urldecode( $parsed_matched_url->pathname );
			$pathname_matches         = str_starts_with( $decoded_matched_pathname, $decoded_current_site_pathname );
			if ( ! $pathname_matches ) {
				continue;
			}

			// It's a match!
			yield $parsed_matched_url;
		}
	}
}

function rewrite_url(
    string $raw_matched_url,
    $parsed_matched_url,
    $parsed_current_site_url,
    $parsed_new_site_url,
) {
    // Let's rewrite the URL
    $parsed_matched_url->hostname = $parsed_new_site_url->hostname;
    $decoded_matched_pathname = urldecode( $parsed_matched_url->pathname );

    // Short-circuit for empty pathnames
    if ('/' !== $parsed_current_site_url->pathname) {
        $parsed_matched_url->pathname =
            $parsed_new_site_url->pathname .
            substr(
                $decoded_matched_pathname,
                strlen(urldecode($parsed_current_site_url->pathname))
            );
    }

    /*
     * Stylistic choice – if the matched URL has no trailing slash,
     * do not add it to the new URL. The WHATWG URL parser will
     * add one automatically if the path is empty, so we have to
     * explicitly remove it.
     */
    $new_raw_url = $parsed_matched_url->toString();
    if (
        $raw_matched_url[strlen($raw_matched_url) - 1] !== '/' &&
        $parsed_matched_url->pathname === '/' &&
        $parsed_matched_url->search === '' &&
        $parsed_matched_url->hash === ''
    ) {
        $new_raw_url = rtrim($new_raw_url, '/');
    }

    return $new_raw_url;
}
