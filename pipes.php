<?php

use \WordPress\AsyncHttp\Client;
use \WordPress\AsyncHttp\Request;

interface ReadableStream {
	public function read(): bool;
	public function is_finished(): bool;
	public function consume_output(): ?string;
	public function get_error(): ?string;
	public function get_metadata(): ?StreamMetadata;
}

trait BaseReadableStream {
	protected $finished = false;
	protected $error = null;
	protected $buffer = '';
	protected $metadata = null;

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

	public function get_metadata(): ?StreamMetadata
	{
		return $this->metadata;
	}

	public function consume_output(): ?string {
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
		$this->metadata = null;
	}

	public function get_error(): ?string {
		return $this->error;
	}
}

interface WritableStream {
	public function write( string $data, ?StreamMetadata $metadata=null ): bool;

	public function get_error(): ?string;
}

interface TransformStream extends ReadableStream, WritableStream {
}

trait BaseTransformStream {
	use BaseReadableStream, BaseWritableStream {
		BaseReadableStream::get_error insteadof BaseWritableStream;
		BaseReadableStream::set_error insteadof BaseWritableStream;
	}
}

trait BaseWritableStream {
	protected $error = null;
	protected $metadata = null;

	public function write( string $data, ?StreamMetadata $metadata=null ): bool {
		if ( $this->error ) {
			return false;
		}

		$this->metadata = $metadata;
		return $this->doWrite( $data, $metadata );
	}

	abstract protected function doWrite( string $data, ?StreamMetadata $metadata ): bool;

	protected function set_error( string $error ) {
		$this->metadata = null;
		$this->error    = $error ?: 'unknown error';
		$this->finished = true;
	}

	public function get_error(): ?string {
		return $this->error;
	}
}

class BufferStream implements TransformStream {

	use BaseTransformStream;

	protected function doWrite( string $data, ?StreamMetadata $metadata=null ): bool {
		$this->buffer .= $data;

		return true;
	}

	protected function doRead(): bool {
		return strlen( $this->buffer ) > 0;
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

	protected function doWrite( string $data, ?StreamMetadata $metadata=null ): bool {
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

class DemultiplexerStream implements TransformStream {
	use BaseTransformStream;

	private $pipe_factory;
	private $pipes = [];
	private $next_read = [];
	public function __construct( $pipe_factory ) {
		$this->pipe_factory = $pipe_factory;
	}

	protected function doWrite( string $data, ?StreamMetadata $metadata=null ): bool {
		// -1 is the default stream ID used whenever we don't have any metadata
		$stream_id = $metadata ? $metadata->get_resource_id() : -1;
		if ( ! isset( $this->pipes[ $stream_id ] ) ) {
			$pipe_factory = $this->pipe_factory;
			$this->pipes[ $stream_id ] = $pipe_factory();
		}

		return $this->pipes[ $stream_id ]->write( $data, $metadata );
	}

	protected function doRead(): bool {
		if(empty($this->next_read)) {
			$this->next_read = array_keys($this->pipes);
		}

		while (count($this->next_read)) {
			$stream_id = array_shift($this->next_read);
			if (!isset($this->pipes[$stream_id])) {
				continue;
			}

			$pipe = $this->pipes[$stream_id];
			if(!($pipe instanceof ReadableStream)) {
				// @TODO: What if the last pipe in the demultiplexer is not readable?
				//        Then the entire multiplexer is not readable.
				//        We need to conider this somehow in the Pipe class
				//        around this line:
				//            if ( $last_stage instanceof ReadableStream && $last_stage->read() ) {
				return false;
			}
			if (!$pipe->read()) {
				if ($pipe->is_finished()) {
					unset($this->pipes[$stream_id]);
				}
				continue;
			}

			$this->buffer .= $pipe->consume_output();
			$this->metadata = $pipe->get_metadata();
			return true;
		}

		return false;
	}

}

class RequestStream implements ReadableStream {
	use BaseReadableStream;

	private $client;
	private $requests = [];
	private $requests_metadata = [];

	public function __construct( $requests ) {
		$this->client = new Client();
		$this->client->enqueue( $requests );

		$this->requests = $requests;
		foreach($requests as $request) {
			$this->requests_metadata[$request->id] = new BasicStreamMetadata(
				$request->id,
				$request->url
			);
		}
	}

	protected function doRead(): bool {
		if ( ! $this->client->await_next_event() ) {
			$this->finished = true;

			return false;
		}

		$request = $this->client->get_request();
		$this->metadata = $this->requests_metadata[$request->id];
		switch ( $this->client->get_event() ) {
			case Client::EVENT_BODY_CHUNK_AVAILABLE:
				$this->buffer .= $this->client->get_response_body_chunk();
				return true;
			case Client::EVENT_FAILED:
				// @TODO: Handling errors.
				//        We don't want to stop everything if one request fails.
				$this->set_error( $request->error ?: 'unknown error' );
				break;
			case Client::EVENT_FINISHED:
				if(count($this->client->get_active_requests()) === 0) {
					$this->finished = true;
				}
				break;
		}

		return false;
	}

}

abstract class StringTransformerStream implements TransformStream {
	use BaseTransformStream;

	protected function doRead(): bool {
		return ! empty( $this->buffer );
	}

	protected function doWrite( string $data, ?StreamMetadata $metadata=null ): bool {
		$this->buffer .= $this->transform( $data );

		return true;
	}

	abstract protected function transform(string $data): ?string;
}

class UppercaseTransformer extends StringTransformerStream {
	protected function transform( string $data ): ?string {
		return strtoupper( $data );
	}
}

class Rot13Transformer extends StringTransformerStream {
	protected function transform( string $data ): ?string {
		return str_rot13( $data );
	}
}

class EchoTransformer extends StringTransformerStream {
	protected function transform( string $data ): ?string {
		echo $data;
		return $data;
	}
}

class FilterStream implements TransformStream {
	use BaseTransformStream;

	private $filter_callback;

	public function __construct( $filter_callback ) {
		$this->filter_callback = $filter_callback;
	}

	protected function doRead(): bool {
		return ! empty( $this->buffer );
	}

	protected function doWrite( string $data, ?StreamMetadata $metadata=null ): bool {
		$filter_callback = $this->filter_callback;
		if ( $filter_callback( $metadata ) ) {
			$this->buffer .= $data;
		} else {
			$this->buffer = '';
			$this->metadata = null;
		}
		return true;
	}
}

class LocalFileStream implements WritableStream {
	private $error = null;
	private $filename_factory;
	private $fp;

	public function __construct($filename_factory)
	{
		$this->filename_factory = $filename_factory;
	}

	public function write( string $data, ?StreamMetadata $metadata=null ): bool {
		if ( ! $this->fp ) {
			$filename_factory = $this->filename_factory;
			$filename = $filename_factory($metadata);
			// @TODO: we'll need to close this. We could use a close() or cleanup() method here.
			$this->fp = fopen($filename, 'wb');
		}

		fwrite($this->fp, $data);
		return true;
	}

	public function get_error(): ?string {
		return $this->error;
	}
}

/**
 * Extend this class when more metadata is needed.
 */
interface StreamMetadata {
	public function get_resource_id();
	public function get_filename();
}

class BasicStreamMetadata implements StreamMetadata {
	private $resource_id;
	private $filename;

	public function __construct($resource_id, $filename=null)
	{
		$this->resource_id = $resource_id;
		$this->filename = $filename;		
	}

	public function get_resource_id()
	{
		return $this->resource_id;
	}

	public function get_filename()
	{
		return $this->filename;
	}
}

class Pipe implements ReadableStream, WritableStream {
	private $stages = [];
	private $error = null;
	private $finished = false;
	private $dataBuffer = '';

	static public function run($stages)
	{
		$pipe = Pipe::from( $stages );

		while (!$pipe->is_finished()) {
			if ( ! $pipe->read() ) {
				// If no new data was produced, wait a bit before trying again
				usleep( 10000 ); // Sleep for 10ms
			}
		}

		return $pipe->consume_output();
	}

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

			$data = $stage->consume_output();
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
				$data = $stage->consume_output();
			}

			if ( null === $data ) {
				break;
			}

			$anyDataPiped = true;
			$nextStage    = $stages[ $i + 1 ];
			if ( ! $nextStage->write( $data, $stage->get_metadata() ) ) {
				$this->error       = $nextStage->get_error();
				$this->is_finished = true;
				break;
			}
		}

		$last_stage = $stages[ count( $stages ) - 1 ];
		if ( $last_stage instanceof ReadableStream && $last_stage->read() ) {
			$this->dataBuffer .= $last_stage->consume_output();
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

	public function write( string $data, ?StreamMetadata $metadata = null ): bool {
		return $this->stages[0]->write( $data, $metadata );
	}

	public function consume_output(): ?string {
		$data             = $this->dataBuffer;
		$this->dataBuffer = '';

		return $data;
	}

	public function get_metadata(): ?StreamMetadata
	{
		return $this->stages[ count( $this->stages ) - 1 ]->get_metadata();		
	}

	public function is_finished(): bool {
		return $this->finished;
	}

	public function get_error(): ?string {
		return $this->error;
	}
}

class BlockMarkupURLRewriteStream extends BlockMarkupURLVisitorStream
{
	private $from_url;
	private $parsed_from_url;
	private $parsed_from_url_pathname;

	private $to_url;
	private $parsed_to_url;

	private $base_url = 'https://playground.internal';

	public function __construct($text, $options)
	{
		$this->from_url = $options['from_url'];
		$this->parsed_from_url = WP_URL::parse($this->from_url);
		$this->parsed_from_url_pathname = urldecode($this->parsed_from_url->pathname);
		$this->to_url = $options['to_url'];
		$this->parsed_to_url = WP_URL::parse($this->to_url);

		parent::__construct(
			new WP_Block_Markup_Url_Processor($text, $this->from_url),
			[$this, 'url_node_visitor']
		);
	}

	protected function url_node_visitor(WP_Block_Markup_Url_Processor $p)
	{
		$parsed_matched_url = $p->get_parsed_url();
		if ($parsed_matched_url->hostname === $this->parsed_from_url->hostname) {
			$decoded_matched_pathname = urldecode($parsed_matched_url->pathname);
			$pathname_matches = str_starts_with($decoded_matched_pathname, $this->parsed_from_url_pathname);
			if (!$pathname_matches) {
				return;
			}
			// It's a match!
			$p->set_raw_url(
				$this->rewrite_url(
					$p->get_raw_url(),
					$parsed_matched_url,
				)
			);
		}
	}

	public function rewrite_url( string $raw_matched_url, $parsed_matched_url ) {
		// Let's rewrite the URL
		$parsed_matched_url->hostname = $this->parsed_to_url->hostname;
		$decoded_matched_pathname = urldecode($parsed_matched_url->pathname);

		// Short-circuit for empty pathnames
		if ('/' !== $this->parsed_from_url->pathname) {
			$parsed_matched_url->pathname =
				$this->parsed_to_url->pathname .
				substr(
					$decoded_matched_pathname,
					strlen(urldecode($this->parsed_from_url->pathname))
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
