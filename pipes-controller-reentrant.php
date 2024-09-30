<?php

require __DIR__ . '/pipes-controller-classes.php';

use WordPress\AsyncHttp\Client;
use WordPress\AsyncHttp\Request;

function create_chain($paused_state=null) {
    $chain = new StreamChain(
        [
            // 'file' => new File_Byte_Stream('./test.zip', 100),
            'zip' => ZIP_Reader_Local::stream('./export.wxr.zip'),
            'xml' => XML_Processor::stream(function ($processor) {
                $breadcrumbs = $processor->get_breadcrumbs();
				if (
                     '#cdata-section' === $processor->get_token_type() &&
                     end($breadcrumbs) === 'content:encoded'
                ) {
                    echo '<content:encoded>'.substr(str_replace("\n", "", $processor->get_modifiable_text()), 0, 100)."...</content:encoded>\n\n";
                }
             }),
        ]
    );
    if($paused_state) {
        $chain->resume($paused_state);
    }
    return $chain;
}

$chain = create_chain();
$chain->stop_on_errors(true);

$i = 0;
foreach($chain as $chunk) {
    if(++$i % 3 === 0) {
        // @TODO: Use json_encode, never rely on PHP serialize/unserialize.
        // for that, we need to make sure that every pause() method returns valid UTF8 characters. If 
        // it needs to export binary data, it should base64 encode it first.
        $paused_state = unserialize(serialize($chain->pause()));
        $chain = create_chain($paused_state);
    }
    switch($chunk->get_chunk_type()) {
        case '#error':
            echo "Error: " . $chunk->get_last_error() . "\n";
            die();
            break;
        case '#bytes':
            // echo($chunk->get_bytes());
            break;
    }
    if(15 === $i) {
        // @TODO: make sure the local ZIP Reader correctly communicates when the file ends.
        break;
    }
}
