<?php

require __DIR__ . '/pipes-controller-classes.php';

use WordPress\AsyncHttp\Client;
use WordPress\AsyncHttp\Request;

// ----------------------------------------------------------------------------
// Here's a stream-based pipeline that fetches a ZIP file from a remote server,
// unzips it, skips the first file, processes the XML files, and uppercases the
// output.
// ----------------------------------------------------------------------------
$chain = new StreamChain(
    [
        'http' => HTTP_Client::stream([
            new Request('http://127.0.0.1:9864/export.wxr.zip'),
            // new Request('http://127.0.0.1:9864/export.wxr.zip'),
            // Bad request, will fail:
            new Request('http://127.0.0.1:9865')
        ]),
        'zip' => ZIP_Reader::stream(),
        Byte_Stream::map(function($bytes, $context) {
            if($context['zip']->get_file_id() === 'export.wxr') {
                $context['zip']->skip_file();
                return null;
            }
            return $bytes;
        }),
        'xml' => XML_Processor::stream(function () { }),
        Byte_Stream::map(function($bytes) { return strtoupper($bytes); }),
    ]
);

// Consume the data like this:
// var_dump([$chain->next_chunk(), strlen($chain->get_bytes()), $chain->get_last_error()]);

// Or like this:
$chain->stop_on_errors(true);
foreach($chain as $chunk) {
    switch($chunk->get_chunk_type()) {
        case '#error':
            echo "Error: " . $chunk->get_last_error() . "\n";
            break;
        case '#bytes':
            var_dump([
                $chunk->get_bytes(),
                'zip file_id' => isset($chain['zip']) ? $chain['zip']->get_file_id() : null
            ]);
            break;
    }
}


// ----------------------------------------------------------
// And here's a loop-based pipeline that does the same thing:
// ----------------------------------------------------------

$client = new Client();
$client->enqueue([
    new Request('http://127.0.0.1:9864/export.wxr.zip'),
    new Request('http://127.0.0.1:9865')
]);

$zip_readers = [];
$xml_processors = [];
$xml_tokens_found = [];

$chunks = [];
while(true) {
    if(empty($chunks)) {
        $event = $client->await_next_event();
        if(false === $event) {
            break;
        }
        $chunks[] = ['http', null];
    }

    list($stage, $chunk) = array_pop($chunks);

    switch ($stage) {
        case 'http':
            $request = $client->get_request();
            switch ($client->get_event()) {
                case Client::EVENT_BODY_CHUNK_AVAILABLE:
                    $chunks[] = ['zip', $client->get_response_body_chunk()];
                    break;
                case Client::EVENT_FAILED:
                    error_log('Request failed: ' . $request->error);
                    break;
            }
            break;
        case 'zip':
            $zip_reader = $zip_readers[$request->id] ?? new ZipStreamReader();
            $zip_reader->append_bytes($chunk);
            while ($zip_reader->next()) {
                switch ($zip_reader->get_state()) {
                    case ZipStreamReader::STATE_FILE_ENTRY:
                        if ($zip_reader->get_file_path() === 'export.wxr') {
                            continue 2;
                        }
                        $chunks[] = ['xml', $zip_reader->get_file_body_chunk()];
                        break;
                }
            }
            break;
        case 'xml':
            $xml_processor = $xml_processors[$request->id] ?? new WP_XML_Processor('', [], WP_XML_Processor::IN_PROLOG_CONTEXT);
            $xml_processor->append_bytes($chunk);

            $xml_tokens_found[$request->id] ??= 0;
            while ($xml_processor->next_token()) {
                ++$xml_tokens_found[$request->id];
                // Process the XML
            }

            $buffer = '';
            if ($xml_tokens_found[$request->id] > 0) {
                $buffer .= $xml_processor->get_updated_xml();
            } else if (
                $xml_tokens_found[$request->id] === 0 &&
                !$xml_processor->is_paused_at_incomplete_input() &&
                $xml_processor->get_current_depth() === 0
            ) {
                // We've reached the end of the document, let's finish up.
                $buffer .= $xml_processor->get_unprocessed_xml();
            }

            if (!strlen($buffer)) {
                continue 2;
            }

            // Uppercase the output
            echo strtoupper($buffer);
            break;
    }
}
