<?php

require __DIR__ . '/pipes-controller-classes.php';

use WordPress\AsyncHttp\Client;
use WordPress\AsyncHttp\Request;

function create_chain($paused_state=null) {
    $chain = new StreamChain(
        [
            // 'file' => new File_Byte_Stream('./test.zip', 100),
            // 'zip' => ZIP_Reader::stream(),
            'file' => new File_Byte_Stream('./export.wxr', 100),
            'xml' => XML_Processor::stream(function () { }),
        ]
    );
    if($paused_state) {
        $chain->resume($paused_state);
    }
    return $chain;
}

function display_next_chunks($chain, $nb=5) {
    $i = 0;
    foreach($chain as $chunk) {
        ++$i;
        if($i > 5) {
            break;
        }
        switch($chunk->get_chunk_type()) {
            case '#error':
                echo "Error: " . $chunk->get_last_error() . "\n";
                break;
            case '#bytes':
                echo($chunk->get_bytes());
                break;
        }
    }
}

$chain = create_chain();
$chain->stop_on_errors(true);

display_next_chunks($chain);
echo "\n\n\n\n-----------------------------------\n\n\n\n";

$paused_state = $chain->pause();
$paused_state = json_decode(json_encode($paused_state), true);
$chain2 = create_chain($paused_state);
display_next_chunks($chain2);

/*
$file_stream = new File_Byte_Stream('./test.zip', 100);
$zip_reader = new ZipStreamReader('');
$processor = new WP_XML_Processor('');

$file_stream->next_bytes();
$zip_reader->append_bytes($file_stream->get_bytes());
$zip_reader->next();
$processor->append_bytes($zip_reader->get_file_body_chunk());
$processor->next_tag();
var_dump($processor->get_tag());

$file_stream_2 = File_Byte_Stream::resume($file_stream->pause());
$zip_reader_2 = ZipStreamReader::resume($zip_reader->pause());
$processor_2 = WP_XML_Processor::resume($processor->pause());

var_dump($processor_2->get_tag());
var_dump($processor_2->next_tag());
var_dump($processor_2->get_tag());

$file_stream_2->next_bytes();
$zip_reader_2->append_bytes($file_stream_2->get_bytes());

var_dump($processor_2->get_tag());
var_dump($processor_2->next_tag());
var_dump($processor_2->get_tag());
die();

// $processor = new WP_XML_Processor('<?xml version="1.0" encoding="UTF-8"?><root><child>Hello, World!</child></root>');
// var_dump($processor->next_tag());
// var_dump($processor->get_tag());

// var_dump($processor->pause());
// $processor2 = WP_XML_Processor::resume($processor->pause());
// var_dump($processor2->get_tag());
// var_dump($processor2->next_tag());
// var_dump($processor2->get_tag());
*/