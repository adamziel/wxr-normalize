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
require __DIR__ . '/pipes.php';

use \WordPress\AsyncHttp\Request;

// Pipe::run( [
// 	new RequestStream( [ new Request( 'https://raw.githubusercontent.com/WordPress/blueprints/normalize-wxr-assets/blueprints/stylish-press-clone/woo-products.wxr' ) ] ),
// 	new XMLProcessorStream(function (WP_XML_Processor $processor) {
// 		if(is_wxr_content_node($processor)) {
// 			$text         = $processor->get_modifiable_text();
// 			$updated_text = Pipe::run([
// 				new BlockMarkupURLRewriteStream( 
// 					$text,
// 					[
// 						'from_url' => 'https://raw.githubusercontent.com/wordpress/blueprints/normalize-wxr-assets/blueprints/stylish-press-clone/wxr-assets/',
// 						'to_url'   => 'https://mynew.site/',
// 					]
// 				),
// 			]);
// 			if ( $updated_text !== $text ) {
// 				$processor->set_modifiable_text( $updated_text );
// 			}
// 		}
// 	}),
// 	new EchoStream(),
// ] );

$rewrite_links_in_wxr_node = function (WP_XML_Processor $processor) {
	if (is_wxr_content_node($processor)) {
		$text = $processor->get_modifiable_text();
		$updated_text = Pipe::run([
			new BlockMarkupURLRewriteStream(
				$text,
				[
					'from_url' => 'https://raw.githubusercontent.com/wordpress/blueprints/normalize-wxr-assets/blueprints/stylish-press-clone/wxr-assets/',
					'to_url' => 'https://mynew.site/',
				]
			),
		]);
		if ($updated_text !== $text) {
			$processor->set_modifiable_text($updated_text);
		}
	}
};


// $client = new WordPress\AsyncHttp\Client();
// $client->enqueue( [
// 	new Request( 'https://raw.githubusercontent.com/WordPress/blueprints-library/trunk/php.ini' ),
// 	new Request( 'https://raw.githubusercontent.com/WordPress/blueprints-library/trunk/php.ini' ),
// 	new Request( 'https://raw.githubusercontent.com/WordPress/blueprints-library/trunk/phpcs.xml' ),
// 	new Request( 'https://raw.githubusercontent.com/WordPress/blueprints-library/trunk/phpcs.xml?a' ),
// 	new Request( 'https://raw.githubusercontent.com/WordPress/blueprints/trunk/blueprints/stylish-press/site-content.wxr' ),
// ] );

// while ( $client->await_next_event() ) {
// 	var_dump($client->get_event());
// }

// die();

Pipe::run( [
	HttpClient::stream( [
		new Request( 'https://raw.githubusercontent.com/WordPress/blueprints-library/trunk/php.ini' ),
		new Request( 'https://raw.githubusercontent.com/WordPress/blueprints-library/trunk/php.ini' ),
		new Request( 'https://raw.githubusercontent.com/WordPress/blueprints-library/trunk/phpcs.xml' ),
		new Request( 'https://raw.githubusercontent.com/WordPress/blueprints-library/trunk/phpcs.xml?a' ),
		new Request( 'https://raw.githubusercontent.com/WordPress/blueprints/trunk/blueprints/stylish-press/site-content.wxr' ),
	] ),
	XML_Processor::stream( $rewrite_links_in_wxr_node ),
	LocalFileWriter::stream( fn ($context) => __DIR__ . '/output/' . $context->get_resource_id() . '.chunk' ),
] );




// while ( $pipe->next() ) {
// 	list( 'http' => $http, 'zip' => $zip ) = $pipe->get_context();

// 	if ( ! str_ends_with( $zip->get_filename(), '.wxr' ) ) {
// 		$zip->skip_file();
// 		continue;
// 	}

// 	switch( $zip->get_filename() ) {
// 		case 'site-content.wxr':
// 			$pipe->write( $xml->get_contents() );
// 			break;
// 	}
// }

// Pipe::run( [
// 	'http' => new RequestStream( [ /* ... */ ] ),
// 	'zip'  => new ZipReaderStream( fn ($context) => {
// 		if(!str_ends_with($context['http']->url, '.zip')) {
// 			return $context['zip']->skip();
// 		}
// 	} ),
// 	'xml'  => new XMLProcessorStream(fn ($context) => {
// 		if(!str_ends_with($context['zip']->filename, '.wxr')) {
// 			return $context['zip']->skip();
// 		}

// 		$xml_processor = $context['xml']->get_or_create_processor( $context['zip']->filename );
// 		if(!WXR_Processor::is_content_node($xml_processor)) {
// 			continue;
// 		}

// 		// Migrate URLs and downlaod assets
// 	}),
// ] );

