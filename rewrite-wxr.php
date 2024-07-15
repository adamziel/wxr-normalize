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
 
require __DIR__ . '/bootstrap.php';

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
wxr_download_files([
    'concurrency' => 10,
    'assets' => $url_to_path
]);

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
