<?php

use Rowbot\URL\URL;

require_once __DIR__ . "/../bootstrap.php";

if ( $argc < 2 ) {
	echo "Usage: php script.php <command> --file <input-file> --from-site-url <current site url> --to-url <target url>\n";
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

$inputFile    = $options['file'];
$targetDomain = @$options['target-domain'];

if ( ! file_exists( $inputFile ) ) {
	echo "The file $inputFile does not exist.\n";
	exit( 1 );
}

$block_markup = file_get_contents( $inputFile );

// @TODO: Should a base URL be always required?
$previous_url = $options['from-site-url'] ?? 'https://w.org';
$p            = new WP_Block_Markup_Url_Processor( $block_markup, $previous_url );

switch ( $command ) {
	case 'list_urls':
		echo "URLs found in the markup:\n\n";
		while ( $p->next_url() ) {
			// Skip empty relative URLs.
			if ( ! trim( $p->get_url() ) ) {
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
			echo $p->get_url() . "\n";
		}
		echo "\n";
		break;
	case 'migrate_urls':
		if ( ! isset( $options['from-site-url'] ) ) {
			echo "The --from-site-url option is required for the migrate_urls command.\n";
			exit( 1 );
		}
		if ( ! isset( $options['to-url'] ) ) {
			echo "The --to-url option is required for the migrate_urls command.\n";
			exit( 1 );
		}
		$parsed_prev_url = URL::parse( $options['from-site-url'] );
		$next_url        = $options['to-url'];
		$parsed_new_url  = URL::parse( $next_url );
		echo "Replacing $previous_url with $next_url in the input.\n";
		echo "Note this is not yet enough to migrate the site as both the previous and the new";
		echo "site might be hosted on specific paths.\n\n";
		while ( $p->next_url() ) {
			$updated    = false;
			$url        = $p->get_url();
			$parsed_url = URL::parse( $url, $parsed_prev_url );
			if ( $parsed_url->hostname === $parsed_prev_url->hostname ) {
				$parsed_url->hostname = $parsed_new_url->hostname;
				if ( str_starts_with( $parsed_url->pathname, $parsed_prev_url->pathname ) ) {
					$parsed_url->pathname = $parsed_new_url->pathname . substr( $parsed_url->pathname, strlen( $parsed_prev_url->pathname ) );
				}
				$p->set_url( $parsed_url->toString() );
			}
		}
		echo $p->get_updated_html();
		break;
}
