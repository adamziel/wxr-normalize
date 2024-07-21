<?php

use \WordPress\AsyncHttp\Client;
use \WordPress\AsyncHttp\Request;

interface ReadableStream extends Iterator {
	public function read(): bool;
	public function is_finished(): bool;
	public function consume_output(): ?string;
	public function get_error(): ?string;
	public function get_context(): ?StreamedFileContext;
	public function on_last_read_file_skipped();
}

trait ReadableStreamIterator
{
	private $position = 0;
	private $iterator_output_cache = null;

	public function current(): mixed {
		if(null === $this->iterator_output_cache) {
			$this->iterator_output_cache = $this->consume_output();
		}
		return (object) [
			'bytes' => $this->iterator_output_cache,
			'metadata' => $this->get_context(),
		];
	}

	public function key(): mixed {
		return $this->position;
	}

	public function next(): void {
		$this->iterator_output_cache = null;
		while(false === $this->read()) {
			if ($this->is_finished()) {
				return;
			}
			if($this->get_error()) {
				return;
			}
			usleep(10000);
		}
	}

	public function rewind(): void {
		$this->position = 0;
		$this->next();
	}

	public function valid(): bool {
		return !$this->is_finished();
	}
}

trait BaseReadableStream {
	use ReadableStreamIterator;

	protected $finished = false;
	protected $error = null;
	protected $buffer = '';
	protected $context = null;
	protected $skipped_file_id;

	public function read(): bool {
		if ( $this->finished || $this->error ) {
			return false;
		}

		$result = $this->doRead();
		if(
			$result &&
			$this->context && 
			$this->context->get_file_id() === $this->skipped_file_id
		) {
			$this->consume_output();
			return false;
		}
		return $result;
	}

	abstract protected function doRead(): bool;

	public function is_finished(): bool {
		return $this->finished;
	}

	public function on_last_read_file_skipped() {
		if ($this->context && $this->context->get_file_id()) {
			$this->skipped_file_id = $this->context->get_file_id();
		}
	}

	public function get_context(): ?StreamedFileContext
	{
		return $this->context;
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
		$this->context = null;
	}

	public function get_error(): ?string {
		return $this->error;
	}
}

interface WritableStream {
	public function write( string $data, ?StreamedFileContext $context=null ): bool;

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
	protected $context = null;

	public function write( string $data, ?StreamedFileContext $pipe_context=null ): bool {
		if ( $this->error ) {
			return false;
		}

		return $this->doWrite( $data, $pipe_context );
	}

	abstract protected function doWrite( string $data, ?StreamedFileContext $context ): bool;

	protected function set_error( string $error ) {
		$this->context = null;
		$this->error    = $error ?: 'unknown error';
		$this->finished = true;
	}

	public function get_error(): ?string {
		return $this->error;
	}
}

class BufferStream implements TransformStream {

	use BaseTransformStream;

	protected function doWrite( string $data, ?StreamedFileContext $context=null ): bool {
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

class XML_Processor {
	static public function stream($node_visitor_callback) {
		return new Demultiplexer(
			fn() => new XMLProcessorStream($node_visitor_callback)
		);
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

	protected function doWrite( string $data, ?StreamedFileContext $context=null ): bool {
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
		}
		
		if ( $tokens_found === 0 || ! $processor->paused_at_incomplete_token() ) {
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

	protected function doWrite( string $data, ?StreamedFileContext $pipe_context=null ): bool {
		// -1 is the default stream ID used whenever we don't have any metadata
		$stream_id = $pipe_context ? $pipe_context->get_file_id() : -1;
		if ( ! isset( $this->pipes[ $stream_id ] ) ) {
			$pipe_factory = $this->pipe_factory;
			$this->pipes[ $stream_id ] = $pipe_factory();
		}

		return $this->pipes[ $stream_id ]->write( $data, $pipe_context );
	}

	protected function doRead(): bool {
		if(empty($this->next_read)) {
			$this->next_read = array_keys($this->pipes);
			if(empty($this->pipes)) {
				$this->finished = true;
				return false;
			}
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
			$this->context = $pipe->get_context();
			return true;
		}

		return false;
	}

}

class HttpClient {
	static public function stream($requests) {
		return new RequestStream($requests);
	}
}

class RequestStream implements ReadableStream {
	use BaseReadableStream;

	private $client;
	private $requests = [];
	private $child_contexts = [];
	private $skipped_requests = [];

	public function __construct( $requests ) {
		$this->client = new Client();
		$this->client->enqueue( $requests );

		$this->requests = $requests;
		foreach($requests as $request) {
			$this->child_contexts[$request->id] = new StreamedFileContext(
				$this,
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
		if(array_key_exists($request->id, $this->skipped_requests)) {
			return false;
		}

		$this->context = $this->child_contexts[$request->id];
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
				// @TODO: Mark this particular file as finished without
				//        closing the entire Client stream.
				break;
		}

		return false;
	}
	
	public function on_last_read_file_skipped()
	{
		if ($this->get_context() && $this->get_context()->get_file_id()) {
			$this->skipped_requests[$this->get_context()->get_file_id()] = true;
		}
	}
}

abstract class StringTransformerStream implements TransformStream {
	use BaseTransformStream;

	protected function doRead(): bool {
		return ! empty( $this->buffer );
	}

	protected function doWrite( string $data, ?StreamedFileContext $context=null ): bool {
		$this->buffer .= $this->transform( $data );

		return true;
	}

	abstract protected function transform(string $data): ?string;
}


class CallbackStream implements TransformStream {
	use BaseTransformStream;

	private $callback;
	public function __construct($callback) {
		$this->callback = $callback;
	}

	protected function doRead(): bool {
		return ! empty( $this->buffer );
	}

	protected function doWrite( string $chunk, ?StreamedFileContext $pipe_context=null ): bool {
		$callback = $this->callback;
		$result = $callback( $chunk, $pipe_context );
		if(null === $result) {
			// skip this chunk
		} else if(!is_string($result)) {
			$this->set_error("Invalid chunk emitted by CallbackStream's callback (type: ".gettype($result).")");
			return false;
		} else {
			$this->buffer .= $chunk;
		}
		return true;
	}

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

	protected function doWrite( string $data, ?StreamedFileContext $context=null ): bool {
		$filter_callback = $this->filter_callback;
		if ( $filter_callback( $context ) ) {
			$this->buffer .= $data;
		} else {
			$this->buffer = '';
			$this->context = null;
		}
		return true;
	}
}

class LocalFileWriter implements WritableStream, ReadableStream {
	private $error = null;
	private $file_name_factory;
	private $last_written_chunk;
	private $buffer;
	private $context;
	private $fp;

	use ReadableStreamIterator;

	static public function stream( $file_name_factory ) {
		return new Demultiplexer(
			fn() => new self( $file_name_factory )
		);
	}

	public function __construct($file_name_factory)
	{
		$this->file_name_factory = $file_name_factory;
	}

	public function write( string $data, ?StreamedFileContext $context=null ): bool {
		if ( ! $this->fp ) {
			$file_name_factory = $this->file_name_factory;
			$file_name = $file_name_factory($context);
			$this->context = new StreamedFileContext($this, $file_name, $file_name);
			// @TODO: we'll need to close this. We could use a close() or cleanup() method here.
			$this->fp = fopen($file_name, 'wb');
		}

		$this->last_written_chunk = $data;
		fwrite($this->fp, $data);
		return true;
	}

	public function get_error(): ?string {
		return $this->error;
	}

	// Temporary workaround to keep the Pipe class working
	public function read(): bool {
		if($this->last_written_chunk) {
			$this->buffer = $this->last_written_chunk;
			$this->last_written_chunk = null;
			return true;
		}
		return false;
	}

	public function is_finished(): bool {
		return false;
	}

	public function on_last_read_file_skipped()
	{
		// Nothing to do
	}

	public function consume_output(): ?string {
		if($this->buffer) {
			$chunk = $this->buffer;
			$this->buffer = null;
			return $chunk;
		}
		return null;
	}

	public function get_context(): ?StreamedFileContext
	{
		return $this->context;
	}
}

class Demultiplexer implements ReadableStream, WritableStream
{

	use ReadableStreamIterator;

	public $factory_function;
	private $stream_instances = [];

	public function __construct(
		$factory_function
	) {
		$this->factory_function = $factory_function;
	}

	public function write( string $data, ?StreamedFileContext $pipe_context=null ): bool {
		if ( $this->error ) {
			return false;
		}

		$file_id = $pipe_context ? $pipe_context->get_file_id() : 'default';
		$stream_factory = $this->factory_function;
		if(!isset($this->stream_instances[$file_id])) {
			$this->stream_instances[$file_id] = $stream_factory();
		}
		$stream = $this->stream_instances[$file_id];
		$retval = $stream->write( $data, $pipe_context );
		if ( ! $retval ) {
			$this->error = $stream->get_error();
		}
		return $retval;
	}

	private $read_queue = [];
	private $last_read_stream = null;
	private $finished = false;
	public function read(): bool
	{
		$available_streams = count($this->stream_instances);
		if(0 === $available_streams) {
			$this->stream_instances = [
				'default' => ($this->factory_function)()
			];
			$available_streams = 1;
		}

		$processed_streams = 0;
		do {
			if (empty($this->read_queue)) {
				$this->read_queue = $this->stream_instances;
				if (empty($this->read_queue)) {
					return false;
				}
			}

			$stream = array_shift($this->read_queue);
			if ( $stream->is_finished() ) {
				$index = array_search($stream, $this->stream_instances, true);
				unset($this->stream_instances[$index]);
				continue;
			}

			if ($stream->read()) {
				$this->last_read_stream = $stream;
				return true;
			}

			if ( $stream->get_error() ) {
				$this->error       = $stream->get_error();
				$this->is_finished = true;

				return false;
			}

			++$processed_streams;
		} while ($processed_streams < $available_streams);
		return false;
	}

	public function consume_output(): ?string {
		return $this->last_read_stream ? $this->last_read_stream->consume_output() : null;
	}

	public function get_context(): ?StreamedFileContext
	{
		return $this->last_read_stream ? $this->last_read_stream->get_context() : null;
	}

	public function is_finished(): bool {
		return count($this->stream_instances) === 0;
	}

	protected $error = null;

	protected function set_error( string $error ) {
		$this->context = null;
		$this->error    = $error ?: 'unknown error';
		$this->finished = true;
	}

	public function get_error(): ?string {
		return $this->error;
	}

	public function on_last_read_file_skipped() {
		if($this->get_context()) {
			$this->get_context()->get_stream()->on_last_read_file_skipped();
		}
	}
}


class Pipe implements ReadableStream, WritableStream {
	private $stages_keys = [];
	private $stages = [];
	private $error = null;
	private $context = null;
	private $last_read_from_stage = null;
	private $finished = false;
	private $dataBuffer = '';

	use ReadableStreamIterator;

	static public function get_output($stages)
	{
		return self::run($stages, ['buffer_output' => true]);
	}

	public function current(): mixed {
		if(null === $this->iterator_output_cache) {
			$this->iterator_output_cache = $this->consume_output();
		}
		return $this->context;
	}

	static public function run($stages, $options=array())
	{
		$options = array_merge([
			'buffer_output' => false,
		], $options);
		$pipe = Pipe::from( $stages );

		while ( ! $pipe->is_finished() ) {
			if ( ! $pipe->read() ) {
				// If no new data was produced, wait a bit before trying again
				usleep( 10000 ); // Sleep for 10ms
			}

			if(!$options['buffer_output']) {
				$pipe->consume_output();
			}
		}
		return $pipe->consume_output();
	}

	static public function from( $stages ) {
		if ( count( $stages ) === 0 ) {
			throw new \InvalidArgumentException( 'Pipe must have at least one stage' );
		}

		// Shorthand syntax support, use a callback as one of
		// the pipe components.
		foreach($stages as $k => $v) {
			if(is_callable($v)) {
				$stages[$k] = new CallbackStream($v);
			}
		}

		$stages_values = array_values($stages);
		for ( $i = 0; $i < count( $stages_values ) - 1; $i ++ ) {
			if ( ! $stages_values[ $i ] instanceof ReadableStream ) {
				throw new \InvalidArgumentException( 'All stages except the last one must be ReadableStreams, but ' . get_class( $stages_values[ $i ] ) . ' is not' );
			}
		}

		for ( $i = 1; $i < count( $stages_values ); $i ++ ) {
			if ( ! $stages_values[ $i ] instanceof WritableStream ) {
				throw new \InvalidArgumentException( 'All stages except the first one must be WritableStream, but ' . get_class( $stages_values[ $i ] ) . ' is not' );
			}
		}

		return new self( $stages );
	}

	private function __construct( $stages ) {
		$this->stages_keys = array_keys($stages);
		$this->stages = array_values($stages);
	}

	private $context_history = [];
	public function read(): bool {
		if($this->finished) {
			return false;
		}
		$anyDataPiped = false;

		$stages = $this->stages;
		$this->context = new StreamedFileContext($this);
		$this->last_read_from_stage = null;
		for ( $i = 0; $i < count( $stages ) - 1; $i ++ ) {
			$stage = $stages[ $i ];
			$this->last_read_from_stage = $i;

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

					// No data was produced by the stage, let's try again on the next read() call,
					// and meanwhile let's see if the rest of the pipe will produce any data.
					continue;
				}
				$data = $stage->consume_output();
				if ( null === $data ) {
					break;
				}
			}

			$child_context = $stage->get_context();
			if (null !== $child_context) {
				$this->context[$this->stages_keys[$i]] = $child_context;
			}

			$anyDataPiped = true;
			$nextStage    = $stages[ $i + 1 ];
			if ( ! $nextStage->write( $data, $this->context ) ) {
				$this->error       = $nextStage->get_error();
				$this->finished = true;
				break;
			}
		}

		$last_stage_idx = count( $stages ) - 1;
		$last_stage = $stages[ $last_stage_idx ];
		if ( $last_stage instanceof ReadableStream && $last_stage->read() ) {
			$this->dataBuffer .= $last_stage->consume_output();
			$this->context[$this->stages_keys[$last_stage_idx]] = $last_stage->get_context();
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

	public function get_context(): ?StreamedFileContext
	{
		return $this->context;		
	}

	public function write( string $data, ?StreamedFileContext $pipe_context = null ): bool {
		return $this->stages[0]->write( $data, $pipe_context );
	}

	public function consume_output(): ?string {
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

	public function on_last_read_file_skipped() {
		if (null !== $this->last_read_from_stage) {
			for ($i = $this->last_read_from_stage; $i >= 0; $i--) {
				$this->stages[$i]->on_last_read_file_skipped();
			}
		}
	}
}

class StreamedFileContext implements ArrayAccess {
	private $child_contexts = [];
	private $data = [];
	private $stream;
	private $file_id;
	private $file_name;
	private $is_skipped;

	public function __construct(ReadableStream $stream, $file_id = null, $file_name=null)
	{
		$this->stream = $stream;
		$this->file_id = $file_id;
		$this->file_name = $file_name;
	}

	public function offsetExists($offset): bool {
		return isset($this->child_contexts[$offset]);
	}

	public function offsetGet($offset): mixed {
		return $this->child_contexts[$offset] ?? null;
	}

	public function offsetSet($offset, $value): void {
		$this->child_contexts[$offset] = $value;
	}

	public function offsetUnset($offset): void {
		unset($this->child_contexts[$offset]);
	}

	public function get_stream() {
		return $this->stream;
	}

	public function skip_file() {
		$this->is_skipped = true;
		$this->stream->on_last_read_file_skipped();
	}

	public function get_file_id() {
		if($this->file_id) {
			return $this->file_id;
		}
		foreach($this->child_contexts as $context) {
			$file_id = $context->get_file_id();
			if($file_id) {
				return $file_id;
			}
		}
	}

	public function get_file_name() {
		if($this->file_name) {
			return $this->file_name;
		}
		foreach($this->child_contexts as $context) {
			$file_name = $context->get_file_name();
			if($file_name) {
				return $file_name;
			}
		}
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


function composeIterators(array $iterators): Generator {
    if (empty($iterators)) {
        throw new InvalidArgumentException("Iterator list cannot be empty");
    }

    // Internal recursive function to handle the composition
    function generatorCompose(array $iterators, int $index): Generator {
        if ($index >= count($iterators)) {
            return;
        }

        foreach ($iterators[$index] as $value) {
            if ($index == count($iterators) - 1) {
                yield $value;
            } else {
                foreach (generatorCompose($iterators, $index + 1) as $innerValue) {
                    yield $innerValue;
                }
            }
        }
    }

    return generatorCompose($iterators, 0);
}
