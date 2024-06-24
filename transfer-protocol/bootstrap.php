<?php

require_once __DIR__ . "/../class-wp-html-token.php";
require_once __DIR__ . "/../class-wp-html-span.php";
require_once __DIR__ . "/../class-wp-html-text-replacement.php";
require_once __DIR__ . "/../class-wp-html-decoder.php";
require_once __DIR__ . "/../class-wp-html-attribute-token.php";

require_once __DIR__ . "/../class-wp-html-decoder.php";
require_once __DIR__ . "/../class-wp-html-tag-processor.php";
require_once __DIR__ . "/../class-wp-html-open-elements.php";
require_once __DIR__ . "/../class-wp-token-map.php";
require_once __DIR__ . "/../html5-named-character-references.php";
require_once __DIR__ . "/../class-wp-html-active-formatting-elements.php";
require_once __DIR__ . "/../class-wp-html-processor-state.php";
require_once __DIR__ . "/../class-wp-html-unsupported-exception.php";
require_once __DIR__ . "/../class-wp-html-processor.php";

require_once __DIR__ . "/../class-wp-xml-decoder.php";
require_once __DIR__ . "/../class-wp-xml-tag-processor.php";
require_once __DIR__ . "/../class-wp-xml-processor.php";

require_once __DIR__ . '/src/WP_Block_Markup_Processor.php';
require_once __DIR__ . '/src/WP_Block_Markup_Url_Processor.php';
require_once __DIR__ . '/src/WP_Migration_URL_In_Text_Processor.php';
require_once __DIR__ . '/src/WP_URL.php';
require_once __DIR__ . '/vendor/autoload.php';

function _doing_it_wrong() {

}

function __($input) {
	return $input;
}

function esc_attr($input) {
	return htmlspecialchars($input);
}

function esc_html($input) {
	return htmlspecialchars($input);
}
