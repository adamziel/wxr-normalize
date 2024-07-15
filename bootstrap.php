<?php

// Where to find the streaming WP_XML_Processor 
// Use a version from this PR: https://github.com/adamziel/wordpress-develop/pull/43
define('WP_XML_API_PATH', __DIR__ );
define('SITE_TRANSFER_PROTOCOL_PATH', __DIR__ . '/site-transfer-protocol' );
define('BLUEPRINTS_LIB_PATH', __DIR__ . '/blueprints-library/src/WordPress' );
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
$requires[] = WP_XML_API_PATH . "/class-wp-wxr-normalizer.php";
$requires[] = WP_XML_API_PATH . "/functions.php";
$requires[] = BLUEPRINTS_LIB_PATH . "/Streams/StreamWrapperInterface.php";
$requires[] = BLUEPRINTS_LIB_PATH . "/Streams/StreamWrapper.php";
$requires[] = BLUEPRINTS_LIB_PATH . "/Streams/StreamPeekerWrapper.php";
$requires[] = BLUEPRINTS_LIB_PATH . "/AsyncHttp/Request.php";
$requires[] = BLUEPRINTS_LIB_PATH . "/AsyncHttp/Response.php";
$requires[] = BLUEPRINTS_LIB_PATH . "/AsyncHttp/HttpError.php";
$requires[] = BLUEPRINTS_LIB_PATH . "/AsyncHttp/Connection.php";
$requires[] = BLUEPRINTS_LIB_PATH . "/AsyncHttp/Client.php";
$requires[] = BLUEPRINTS_LIB_PATH . "/AsyncHttp/StreamWrapper/ChunkedEncodingWrapper.php";
$requires[] = BLUEPRINTS_LIB_PATH . "/AsyncHttp/StreamWrapper/InflateStreamWrapper.php";

$requires[] = SITE_TRANSFER_PROTOCOL_PATH . '/src/WP_Block_Markup_Processor.php';
$requires[] = SITE_TRANSFER_PROTOCOL_PATH . '/src/WP_Block_Markup_Url_Processor.php';
$requires[] = SITE_TRANSFER_PROTOCOL_PATH . '/src/WP_Migration_URL_In_Text_Processor.php';
$requires[] = SITE_TRANSFER_PROTOCOL_PATH . '/src/WP_URL.php';
$requires[] = SITE_TRANSFER_PROTOCOL_PATH . '/src/functions.php';
$requires[] = SITE_TRANSFER_PROTOCOL_PATH . '/vendor/autoload.php';

foreach ($requires as $require) {
    require_once $require;
}
