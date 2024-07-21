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
		$updated_text = Pipe::get_output([
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

// @TODO: Implement the commented out parts

$pipe = Pipe::run([
	'http' => HttpClient::stream([
		new Request('https://raw.githubusercontent.com/WordPress/blueprints-library/trunk/php.ini'),
		new Request('https://raw.githubusercontent.com/WordPress/blueprints-library/trunk/php.ini'),
		new Request('https://raw.githubusercontent.com/WordPress/blueprints-library/trunk/phpcs.xml'),
		new Request('https://raw.githubusercontent.com/WordPress/blueprints-library/trunk/phpcs.xml?a'),
		new Request('https://raw.githubusercontent.com/WordPress/blueprints/trunk/blueprints/stylish-press/site-content.wxr'),
		new Request('https://raw.githubusercontent.com/wpaccessibility/a11y-theme-unit-test/master/a11y-theme-unit-test-data.xml'),
	]),
	XML_Processor::stream($rewrite_links_in_wxr_node),
	function ($chunk, $context) {
		// $context['http'] is guaranteed to be present if there are no
		// asynchronous streams between the HttpClient stream and here.
		//
		// Otherwise, the asynchronous operation may yield new chunks after the
		// 'http' stream is finished.
		if( ! str_ends_with( $context['http']->get_file_name(), '.wxr' ) ) {
			$context->skip_file();
			// Don't emit any data
			return null;
		}

		// Emit unchanged input data
		return $chunk;
	},
	'file' => LocalFileWriter::stream( fn ($context) => __DIR__ . '/output/' . $context->get_file_id() . '.chunk' ),
] );


// foreach($pipe as $context) {
// 	list( 'http' => $http, 'file' => $file ) = $context;
	// print_r($http);
	// print_r($file);

	// if ( $context->is_failure() ) {
	// 	echo 'Failed to download ' . $http->url . ': ' . $context->get_error_message();
	// 	continue;
	// }

	// // if ( ! str_ends_with( $zip->file_name, '.wxr' ) ) {
	// // 	$zip->skip_file();
	// // 	continue;
	// // }

	// echo 'Saved ' . $http->url . ' to ' . $file->file_path;
// }

