<?php

use \WordPress\AsyncHttp\Client;
use \WordPress\AsyncHttp\Request;

function wxr_download_files($options) {
	$requests = [];
	$local_paths = [];
	foreach ($options['assets'] as $asset_url => $local_file) {
		$request = new Request($asset_url);
		$requests[] = $request;
		$local_paths[$request->id] = $local_file;
	}

	$client = new Client( [
		'concurrency' => 10,
	] );
	$client->enqueue( $requests );

	$results = [];
	while ( $client->await_next_event() ) {
		$request = $client->get_request();
		
		switch ( $client->get_event() ) {
			case Client::EVENT_BODY_CHUNK_AVAILABLE:
				file_put_contents(
					$local_paths[$request->original_request()->id],
					$client->get_response_body_chunk(),
					FILE_APPEND
				);
				break;
			case Client::EVENT_FAILED:
				$results[$request->original_request()->url] = [
					'success' => false,
					'error' => $request->error,
				];
				break;
			case Client::EVENT_FINISHED:
				$results[$request->original_request()->url] = [
					'success' => true
				];
				break;
		}
	}
	return $results;
}

/**
 * WordPress compat
 */
if(!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_XML1, 'UTF-8');
    }
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
