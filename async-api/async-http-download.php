<?php

var_dump($output_info);

$client = new WordPress\AsyncHttp\Client();
$fps = $client->enqueue($requests);
do {
    $active_requests = count($fps);
    foreach ($requests as $request) {
        $bytes = $client->read_bytes($request, 8192);
        if (false === $bytes) {
            --$active_requests;
            continue;
        }
        fwrite($output_info[$request->url]['fp'], $bytes);
    }
} while($active_requests > 0);

foreach($output_info as $info) {
    fclose($info['fp']);
}


