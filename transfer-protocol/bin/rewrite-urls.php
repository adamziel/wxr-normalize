<?php

require_once __DIR__ . "/../bootstrap.php";

if ( $argc < 2 ) {
	echo "Usage: php script.php <command> --file <input-file> --current-site-url <current site url> --new-site-url <target url>\n";
	echo "Commands:\n";
	echo "  list_urls: List all the URLs found in the input file.\n";
	echo "  migrate_urls: Migrate all the URLs found in the input file from the current site to the target site.\n";
	exit( 1 );
}

$command = $argv[1];
$options = [];

for ( $i = 2; $i < $argc; $i ++ ) {
	if ( str_starts_with( $argv[ $i ], '--' ) && isset( $argv[ $i + 1 ] ) ) {
		$options[ substr( $argv[ $i ], 2 ) ] = $argv[ $i + 1 ];
		$i ++;
	}
}

if ( ! isset( $options['file'] ) ) {
	echo "The file option is required.\n";
	exit( 1 );
}

$inputFile = $options['file'];
if ( ! file_exists( $inputFile ) ) {
	echo "The file $inputFile does not exist.\n";
	exit( 1 );
}
$block_markup = file_get_contents( $inputFile );

// @TODO: Decide – should the current site URL be always required to
//        populate $base_url?
$base_url = $options['current-site-url'] ?? 'https://playground.internal';
$p        = new WP_Block_Markup_Url_Processor( $block_markup, $base_url );

switch ( $command ) {
	case 'list_urls':
		echo "URLs found in the markup:\n\n";
		while ( $p->next_url() ) {
			// Skip empty relative URLs.
			if ( ! trim( $p->get_raw_url() ) ) {
				continue;
			}
			echo '* ';
			switch ( $p->get_token_type() ) {
				case '#tag':
					echo 'In <' . $p->get_tag() . '> tag attribute "' . $p->get_inspected_attribute_name() . '": ';
					break;
				case '#block-comment':
					echo 'In a ' . $p->get_block_name() . ' block attribute "' . $p->get_block_attribute_key() . '": ';
					break;
				case '#text':
					echo 'In #text: ';
					break;
			}
			echo $p->get_raw_url() . "\n";
		}
		echo "\n";
		break;
	case 'migrate_urls':
		if ( ! isset( $options['current-site-url'] ) ) {
			echo "The --current-site-url option is required for the migrate_urls command.\n";
			exit( 1 );
		}
		if ( ! isset( $options['new-site-url'] ) ) {
			echo "The --new-site-url option is required for the migrate_urls command.\n";
			exit( 1 );
		}
		$parsed_current_site_url       = WP_URL::parse( $options['current-site-url'] );
		$decoded_current_site_pathname = urldecode( $parsed_current_site_url->pathname );
		$string_new_site_url           = $options['new-site-url'];
		$parsed_new_site_url     = WP_URL::parse( $string_new_site_url );

		echo "Replacing $base_url with $string_new_site_url in the input.\n";
		echo "Note this is not yet enough to migrate the site as both the previous and the new";
		echo "site might be hosted on specific paths.\n\n";
		while ( $p->next_url() ) {
			$updated            = false;
			$matched_url        = $p->get_raw_url();
			$parsed_matched_url = $p->get_parsed_url();
			if ( $parsed_matched_url->hostname === $parsed_current_site_url->hostname ) {
				$decoded_matched_pathname = urldecode( $parsed_matched_url->pathname );
				$pathname_matches = str_starts_with( $decoded_matched_pathname, $decoded_current_site_pathname );
				if ( ! $pathname_matches ) {
					continue;
				}

				// It's a match! Let's rewrite the URL

				$parsed_matched_url->hostname = $parsed_new_site_url->hostname;
				// short-circuit for empty pathnames
				if ( '/' !== $parsed_current_site_url->pathname ) {
					$parsed_matched_url->pathname =
						$parsed_new_site_url->pathname .
						substr(
							$decoded_matched_pathname,
							// @TODO: Why is + 1 needed to avoid a double slash in the pathname?
							strlen( urldecode( $parsed_current_site_url->pathname ) ) + 1
						)
					;
				}

				/*
				 * Stylistic choice – if the matched URL has no trailing slash,
				 * do not add it to the new URL. The WHATWG URL parser will
				 * add one automatically if the path is empty, so we have to
				 * explicitly remove it.
				 */
				$new_raw_url = $parsed_matched_url->toString();
				if (
					$matched_url[ strlen( $matched_url ) - 1 ] !== '/' &&
					$parsed_matched_url->pathname === '/' &&
					$parsed_matched_url->search === '' &&
					$parsed_matched_url->hash === ''
				) {
					$new_raw_url = rtrim( $new_raw_url, '/' );
				}
				$p->set_raw_url( $new_raw_url );
			}
		}
		echo $p->get_updated_html();
		break;
}
