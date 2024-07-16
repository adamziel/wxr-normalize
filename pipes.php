<?php

require __DIR__ . '/bootstrap.php';

use \WordPress\AsyncHttp\Client;
use \WordPress\AsyncHttp\Request;

interface ReadableStream {
	public function read(): bool;

	public function is_finished(): bool;

	public function get_output(): ?string;

	public function get_error(): ?string;
}

trait BaseReadableStream {
	protected $finished = false;
	protected $error = null;
	protected $buffer = '';

	public function read(): bool {
		if ( $this->finished || $this->error ) {
			return false;
		}

		return $this->doRead();
	}

	abstract protected function doRead(): bool;

	public function is_finished(): bool {
		return $this->finished;
	}

	public function get_output(): ?string {
		if ( $this->buffer !== '' ) {
			$data         = $this->buffer;
			$this->buffer = '';

			return $data;
		}

		return null;
	}

	protected function set_error( string $error ) {
		$this->error    = $error ?: 'unknown error';
		$this->finished = true;
	}

	public function get_error(): ?string {
		return $this->error;
	}
}

interface WritableStream {
	public function write( string $data ): bool;

	public function needs_more(): bool;

	public function get_error(): ?string;
}

interface TransformStream extends ReadableStream, WritableStream {
}

trait BaseWritableStream {
	protected $error = null;

	public function write( string $data ): bool {
		if ( $this->error ) {
			return false;
		}

		return $this->doWrite( $data );
	}

	abstract protected function doWrite( string $data ): bool;

	public function needs_more(): bool {
		return true;
	}

	protected function set_error( string $error ) {
		$this->error    = $error ?: 'unknown error';
		$this->finished = true;
	}

	public function get_error(): ?string {
		return $this->error;
	}
}

class BufferStream implements WritableStream {

	use BaseWritableStream;

	private $buffer = '';

	protected function doWrite( string $data ): bool {
		$this->buffer .= $data;

		return true;
	}

	public function get_output(): ?string {
		return $this->buffer;
	}

}

trait BaseTransformStream {
	use BaseReadableStream, BaseWritableStream {
		BaseReadableStream::get_error insteadof BaseWritableStream;
		BaseReadableStream::set_error insteadof BaseWritableStream;
	}
}

class BlockMarkupURLVisitorStream implements ReadableStream {

	use BaseReadableStream;

	private $url_processor;
	private $url_visitor_callback;

	public function __construct( $url_processor, $url_visitor_callback ) {
		$this->url_processor        = $url_processor;
		$this->url_visitor_callback = $url_visitor_callback;
	}

	protected function doRead(): bool {
		$processor = $this->url_processor;
		while ( $processor->next_url() ) {
			$url_visitor_callback = $this->url_visitor_callback;
			$url_visitor_callback( $processor );
		}

		// The processor was initialized with a complete HTML string
		// so we can be sure the processing is finished at this point.
		// This class could also support streaming processing of HTML,
		// but for WXR processing that's not needed.
		$this->finished = true;
		$this->buffer .= $processor->get_updated_html();

		return strlen( $this->buffer ) > 0;
	}

}

class XMLProcessorStream implements TransformStream {
	use BaseTransformStream;

	private $xml_processor;
	private $node_visitor_callback;

	public function __construct( $node_visitor_callback ) {
		$this->xml_processor         = new WP_XML_Processor( '', [], WP_XML_Processor::IN_PROLOG_CONTEXT );
		$this->node_visitor_callback = $node_visitor_callback;
	}

	protected function doWrite( string $data ): bool {
		$this->xml_processor->stream_append_xml( $data );

		return true;
	}

	protected function doRead(): bool {
		$processor = $this->xml_processor;
		if ( $processor->paused_at_incomplete_token() ) {
			return false;
		}

		if ( $processor->get_last_error() ) {
			$this->set_error( $processor->get_last_error() );

			return false;
		}

		$tokens_found = 0;
		while ( $processor->next_token() ) {
			++ $tokens_found;
			$node_visitor_callback = $this->node_visitor_callback;
			$node_visitor_callback( $processor );
		}

		if ( $tokens_found > 0 ) {
			$this->buffer .= $processor->get_updated_xml();
		} else {
			$this->buffer   .= $processor->get_unprocessed_xml();
			$this->finished = true;
		}

		return strlen( $this->buffer ) > 0;
	}

}

class RequestStream implements ReadableStream {
	use BaseReadableStream;

	private $client;

	public function __construct( Request $request ) {
		$this->client = new Client();
		$this->client->enqueue( [ $request ] );
	}

	protected function doRead(): bool {
		if ( ! $this->client->await_next_event() ) {
			$this->finished = true;

			return false;
		}

		switch ( $this->client->get_event() ) {
			case Client::EVENT_BODY_CHUNK_AVAILABLE:
				$this->buffer .= $this->client->get_response_body_chunk();

				return true;
			case Client::EVENT_FAILED:
				$this->set_error( $this->client->get_request()->error ?: 'unknown error' );
				break;
			case Client::EVENT_FINISHED:
				$this->finished = true;
				break;
		}

		return false;
	}

}

class UppercaseTransformer implements ReadableStream, WritableStream {
	private $data = '';
	private $finished = false;
	private $error = null;

	public function read(): bool {
		return ! empty( $this->data );
	}

	public function write( string $data ): bool {
		$this->data .= strtoupper( $data );

		return true;
	}

	public function needs_more(): bool {
		return ! $this->finished;
	}

	public function is_finished(): bool {
		return $this->finished;
	}

	public function get_output(): ?string {
		if ( $this->data !== '' ) {
			$data       = $this->data;
			$this->data = '';

			return $data;
		}

		return null;
	}

	public function get_error(): ?string {
		return $this->error;
	}

	public function set_finished() {
		$this->finished = true;
	}
}

class Rot13Transformer implements ReadableStream, WritableStream {
	private $data = '';
	private $finished = false;
	private $error = null;

	public function read(): bool {
		return ! empty( $this->data );
	}

	public function write( string $data ): bool {
		$this->data .= str_rot13( $data );

		return true;
	}

	public function needs_more(): bool {
		return ! $this->finished;
	}

	public function is_finished(): bool {
		return $this->finished;
	}

	public function get_output(): ?string {
		if ( $this->data !== '' ) {
			$data       = $this->data;
			$this->data = '';

			return $data;
		}

		return null;
	}

	public function get_error(): ?string {
		return $this->error;
	}

	public function set_finished() {
		$this->finished = true;
	}
}

class EchoStream implements WritableStream {
	private $error = null;

	public function read(): bool {
		return false; // EchoConsumer does not produce data
	}

	public function write( string $data ): bool {
		echo $data;

		return true;
	}

	public function needs_more(): bool {
		return true;
	}

	public function is_finished(): bool {
		return false; // EchoConsumer is never finished
	}

	public function get_output(): ?string {
		return null; // EchoConsumer does not have data to produce
	}

	public function get_error(): ?string {
		return $this->error;
	}
}


class Pipe implements ReadableStream, WritableStream {
	private $stages = [];
	private $error = null;
	private $finished = false;
	private $dataBuffer = '';

	static public function from( $stages ) {
		if ( count( $stages ) === 0 ) {
			throw new \InvalidArgumentException( 'Pipe must have at least one stage' );
		}

		for ( $i = 0; $i < count( $stages ) - 1; $i ++ ) {
			if ( ! $stages[ $i ] instanceof ReadableStream ) {
				throw new \InvalidArgumentException( 'All stages except the last one must be ReadableStreams, but ' . get_class( $stages[ $i ] ) . ' is not' );
			}
		}

		for ( $i = 1; $i < count( $stages ); $i ++ ) {
			if ( ! $stages[ $i ] instanceof WritableStream ) {
				throw new \InvalidArgumentException( 'All stages except the first one must be WritableStream, but ' . get_class( $stages[ $i ] ) . ' is not' );
			}
		}

		return new self( $stages );
	}

	private function __construct( $stages ) {
		$this->stages = $stages;
	}

	public function read(): bool {
		$anyDataPiped = false;

		$stages = $this->stages;
		for ( $i = 0; $i < count( $stages ) - 1; $i ++ ) {
			$stage = $stages[ $i ];

			$data = $stage->get_output();
			if ( null === $data ) {
				if ( ! $stage->read() ) {
					if ( $stage->get_error() ) {
						$this->error       = $stage->get_error();
						$this->is_finished = true;

						return false;
					}

					if ( $stage->is_finished() ) {
						continue;
					}
					break;
				}
				$data = $stage->get_output();
			}

			if ( null === $data ) {
				break;
			}

			$anyDataPiped = true;
			$nextStage    = $stages[ $i + 1 ];
			if ( ! $nextStage->write( $data ) ) {
				$this->error       = $nextStage->get_error();
				$this->is_finished = true;
				break;
			}
		}

		$last_stage = $stages[ count( $stages ) - 1 ];
		if ( $last_stage instanceof ReadableStream && $last_stage->read() ) {
			$this->dataBuffer .= $last_stage->get_output();
			if ( $last_stage->is_finished() ) {
				$this->finished = true;
			}

			return true;
		}

		$first_stage = $stages[0];
		if ( ! $anyDataPiped && $first_stage->is_finished() ) {
			$this->finished = true;
		}

		return false;
	}

	public function write( string $data ): bool {
		if ( isset( $this->stages[0] ) && $this->stages[0] instanceof WritableStream ) {
			return $this->stages[0]->write( $data );
		}

		return false;
	}

	public function needs_more(): bool {
		return ! $this->finished;
	}

	public function get_output(): ?string {
		$data             = $this->dataBuffer;
		$this->dataBuffer = '';

		return $data;
	}

	public function is_finished(): bool {
		return $this->finished;
	}

	public function get_error(): ?string {
		return $this->error;
	}
}

function rewrite_url(
    string $raw_matched_url,
    $parsed_matched_url,
    $parsed_current_site_url,
    $parsed_new_site_url,
) {
    // Let's rewrite the URL
    $parsed_matched_url->hostname = $parsed_new_site_url->hostname;
    $decoded_matched_pathname = urldecode( $parsed_matched_url->pathname );

    // Short-circuit for empty pathnames
    if ('/' !== $parsed_current_site_url->pathname) {
        $parsed_matched_url->pathname =
            $parsed_new_site_url->pathname .
            substr(
                $decoded_matched_pathname,
                strlen(urldecode($parsed_current_site_url->pathname))
            );
    }

    /*
     * Stylistic choice – if the matched URL has no trailing slash,
     * do not add it to the new URL. The WHATWG URL parser will
     * add one automatically if the path is empty, so we have to
     * explicitly remove it.
     */
    $new_raw_url = $parsed_matched_url->toString();
    if (
        $raw_matched_url[strlen($raw_matched_url) - 1] !== '/' &&
        $parsed_matched_url->pathname === '/' &&
        $parsed_matched_url->search === '' &&
        $parsed_matched_url->hash === ''
    ) {
        $new_raw_url = rtrim($new_raw_url, '/');
    }

    return $new_raw_url;
}

function create_url_rewrite_stream( 
	$text,
	$options
) {
	$string_new_site_url = $options['to_url'];
	$parsed_new_site_url = WP_URL::parse( $string_new_site_url );

	$current_site_url        = $options['from_url'];
	$parsed_current_site_url = WP_URL::parse( $current_site_url );
	$decoded_current_site_pathname = urldecode( $parsed_current_site_url->pathname );

	$base_url      = 'https://playground.internal';
	return new BlockMarkupURLVisitorStream(
		new WP_Block_Markup_Url_Processor( $text, $base_url ),
		function(WP_Block_Markup_Url_Processor $p) use($parsed_current_site_url, $decoded_current_site_pathname, $parsed_new_site_url) {
			$parsed_matched_url = $p->get_parsed_url();
			if ( $parsed_matched_url->hostname === $parsed_current_site_url->hostname ) {
				$decoded_matched_pathname = urldecode( $parsed_matched_url->pathname );
				$pathname_matches         = str_starts_with( $decoded_matched_pathname, $decoded_current_site_pathname );
				if ( ! $pathname_matches ) {
					return;
				}
				// It's a match!
				$p->set_raw_url( rewrite_url(
					$p->get_raw_url(),
					$parsed_matched_url,
					$parsed_current_site_url,
					$parsed_new_site_url
				) );
			}
		}
    );
}

function is_wxr_content_node( WP_XML_Processor $processor ) {
	if ( ! in_array( 'item', $processor->get_breadcrumbs() ) ) {
		return false;
	}
	if (
		! in_array( 'excerpt:encoded', $processor->get_breadcrumbs() )
		&& ! in_array( 'content:encoded', $processor->get_breadcrumbs() )
		&& ! in_array( 'wp:attachment_url', $processor->get_breadcrumbs() )
		&& ! in_array( 'guid', $processor->get_breadcrumbs() )
		&& ! in_array( 'link', $processor->get_breadcrumbs() )
		&& ! in_array( 'wp:comment_content', $processor->get_breadcrumbs() )
		// Meta values are not suppoerted yet. We'll need to support
		// WordPress core options that may be saved as JSON, PHP Deserialization, and XML,
		// and then provide extension points for plugins authors support
		// their own options.
		// !in_array('wp:postmeta', $processor->get_breadcrumbs())
	) {
		return false;
	}

	switch ( $processor->get_token_type() ) {
		case '#text':
		case '#cdata-section':
			return true;
	}

	return false;
};

// Create the pipe and chain the stages
$pipe = Pipe::from( [
	new RequestStream( new Request( 'https://raw.githubusercontent.com/WordPress/blueprints/normalize-wxr-assets/blueprints/stylish-press-clone/woo-products.wxr' ) ),
	new XMLProcessorStream(function (WP_XML_Processor $processor) {
		if(is_wxr_content_node($processor)) {
			$text         = $processor->get_modifiable_text();
			$pipe = Pipe::from([
				create_url_rewrite_stream( 
					$text,
					[
						'from_url' => 'https://raw.githubusercontent.com/wordpress/blueprints/normalize-wxr-assets/blueprints/stylish-press-clone/wxr-assets/',
						'to_url'   => 'https://mynew.site/',
					]
				)
			]);
			while (!$pipe->is_finished()) {
				$pipe->read();
			}

			$updated_text = $pipe->get_output();
			if ( $updated_text !== $text ) {
				$processor->set_modifiable_text( $updated_text );
			}
		}
	}),
	new EchoStream(),
] );

$i = 0;
// Process data incrementally as it becomes available
while ( ! $pipe->is_finished() ) {
	if ( ++ $i > 22 ) {
		// break;
	}
	if ( ! $pipe->read() ) {
		// If no new data was produced, wait a bit before trying again
		usleep( 100000 ); // Sleep for 100ms
	}
}

var_dump( $pipe );
