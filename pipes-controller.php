<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/zip-stream-reader.php';

use WordPress\AsyncHttp\Client;
use WordPress\AsyncHttp\Request;

class Byte_Stream {

    protected $next_bytes_callback;
    protected $controller;

    static public function map($callback) {
        return new Byte_Stream(function($controller) use ($callback) {
            $bytes = $controller->consume_input_bytes();
            if(!$bytes) {
                return false;
            }
            $output = $callback($bytes, $controller->input_context);
            if(null === $output) {
                return false;
            }
            $controller->output_bytes = $output;
            return true;
        });
    }

    public function __construct($next_bytes_callback) {
        $this->next_bytes_callback = $next_bytes_callback;
        $this->controller = new ByteStreamController();
    }

    public function get_file_id()
    {
        return $this->controller->get_file_id();
    }

    public function skip_file()
    {
        $this->controller->skip_file();        
    }

    public function append_bytes(string $bytes, $context = null) {
        $this->controller->append_bytes($bytes, $context);
    }

    public function get_bytes()
    {
        return $this->controller->output_bytes;        
    }

    public function get_last_error(): string|null
    {
        return $this->controller->get_last_error();        
    }

    public function next_bytes()
    {
        $this->controller->output_bytes = null;
        $this->controller->last_error = null;
        if($this->controller->is_output_eof()) {
            return false;
        }

        // Process any remaining buffered input:
        $calback = $this->next_bytes_callback;
        if($calback($this->controller)) {
            return ! $this->controller->is_skipped_file();
        }

        if (!$this->controller->input_bytes) {
            if ($this->controller->input_eof) {
                $this->finish();
            }
            return false;
        }

        return $calback($this->controller) && ! $this->controller->is_skipped_file();
    }

}

class ProcessorByteStream extends Byte_Stream
{
    public $processor;

    public function __construct($processor, $callback)
    {
        $this->processor = $processor;
        parent::__construct($callback);
    }
}

/**
 * This interface describes standalone streams, but it can also be
 * used to describe a stream Processor like WP_XML_Processor.
 * 
 * In this prototype there are no pipes, streams, and processors. There
 * are only Byte Streams that can be chained together with the StreamChain
 * class.
 */
class ByteStreamController {
    const STATE_STREAMING = '#streaming';
    const STATE_FINISHED = '#finished';

    public bool $input_eof = false;
    public ?string $input_bytes = null;
    public ?string $output_bytes = null;
    public string $state = self::STATE_STREAMING;
    public ?string $last_error = null;
    public $input_context = null;

    public $file_id;
    public $last_skipped_file;

    public function append_bytes(string $bytes, $context = null) {
        $this->input_bytes .= $bytes;
        $this->input_context = $context;
    }

    public function input_eof() {
        $this->input_eof = true;
    }

    public function is_output_eof(): bool {
        return !$this->output_bytes && $this->state === self::STATE_FINISHED;
    }

    public function get_last_error(): ?string {
        return $this->last_error;
    }

    public function set_last_error($error) {
        $this->last_error = $error;
    }
    
    public function consume_input_bytes() {
        $bytes = $this->input_bytes;
        $this->input_bytes = null;
        return $bytes;
    }

    public function get_file_id()
    {
        return $this->file_id ?? 'default';
    }

    public function is_skipped_file()
    {
        return $this->get_file_id() === $this->last_skipped_file;        
    }

    public function finish()
    {
        $this->state = self::STATE_FINISHED;
    }

    public function skip_file(): void {
        $this->last_skipped_file = $this->file_id;
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

class Demultiplexer extends Byte_Stream {
    private $stream_factory = [];
    private $streams = [];
    private $last_stream;
    private $last_input_key;
    private $key;
    
    public function __construct($stream_factory) {
        $this->stream_factory = $stream_factory;
        parent::__construct([$this, 'tick']);
    }

    public function get_substream()
    {
        return $this->last_stream;
    }

    protected function tick(): bool
    {
        $stream = $this->last_stream;
        if (!$stream) {
            return false;
        }

        if($stream->next_bytes()) {
            $this->controller->file_id = $stream->controller->get_file_id();
            $this->controller->output_bytes = $stream->get_bytes();
            return true;
        }

        if($stream->get_last_error()) {
            $this->controller->set_last_error($stream->get_last_error());
        }
        return false;
    }

    public function append_bytes(string $data, $context = null): bool {
        $chunk_key = 'default';
        if($context) {
            $chunk_key = [];
            foreach($context as $k=>$stream) {
                $chunk_key[] = $stream->controller->get_file_id();
            }
            $chunk_key = implode(':', $chunk_key);
        }

        $this->last_input_key = $chunk_key;
        if (!isset($this->streams[$chunk_key])) {
            $create = $this->stream_factory;
            $this->streams[$chunk_key] = $create();
        }
        $stream = $this->streams[$chunk_key];
        $stream->controller->append_bytes($data, $context);
        $this->last_stream = $stream;
        return true;
    }

    protected function finish()
    {
        $this->controller->finish();
        foreach($this->streams as $stream) {
            $stream->controller->finish();
        }        
    }
}

class StreamChain extends Byte_Stream implements ArrayAccess, Iterator {
    private $first_stream;
    private $last_stream;
    /**
     * @var Byte_Stream[]
     */
    private $streams = [];
    private $streams_names = [];
    private $execution_stack = [];
    private $tick_context = [];

    public function __construct($streams) {
        $named_streams = [];
        foreach($streams as $name => $stream) {
            $string_name = is_numeric($name) ? 'stream_' . $name : $name;
            $named_streams[$string_name] = $streams[$name];
        }

        $this->streams = $named_streams;
        $this->streams_names = array_keys($this->streams);
        $this->first_stream = $this->streams[$this->streams_names[0]];
        $this->last_stream = $this->streams[$this->streams_names[count($streams) - 1]];
        parent::__construct([$this, 'tick']);
    }

    /**
     * ## Process chain tick
     * 
     * Pushes data through a chain of streams. Every downstream data chunk
     * is fully processed before asking for more chunks upstream.
     * 
     * For example, suppose we:
     * 
     * * Send 3 HTTP requests, and each of them produces a ZIP file
     * * Each ZIP file has 3 XML files inside
     * * Each XML file is rewritten using the XML_Processor
     * 
     * Once the HTTP client has produced the first ZIP file, we start processing it.
     * The ZIP decoder may already have enough data to unzip three files, but we only
     * produce the first chunk of the first file and pass it to the XML processor.
     * Then we handle the second chunk of the first file, and so on, until the first
     * file is fully processed. Only then we move to the second file.
     * 
     * Then, once the ZIP decoder exhausted the data for the first ZIP file, we move
     * to the second ZIP file, and so on.
     * 
     * This way we can maintain a predictable $context variable that carries upstream
     * metadata and exposes methods like skip_file().
     */
    protected function tick(): bool {
        if($this->last_stream->controller->is_output_eof()) {
            $this->controller->finish();
            return false;
        }

        while(true) {
            $bytes = $this->controller->consume_input_bytes();
            if(null === $bytes || false === $bytes) {
                break;
            }
            $this->first_stream->append_bytes(
                $bytes
            );
        }

        if($this->controller->is_output_eof()) {
            $this->first_stream->controller->input_eof();
        }

        if(empty($this->execution_stack)) {
            array_push($this->execution_stack, $this->first_stream);
        }

        while (count($this->execution_stack)) {
            // Unpeel the context stack until we find a stream that
            // produces output.
            $stream = $this->pop_stream();
            if ($stream->controller->is_output_eof()) {
                continue;
            }

            if(true !== $this->stream_next($stream)) {
                continue;
            }

            // We've got output from the stream, yay! Let's
            // propagate it downstream.
            $this->push_stream($stream);

            $prev_stream = $stream;
            for ($i = count($this->execution_stack); $i < count($this->streams_names); $i++) {
                $next_stream = $this->streams[$this->streams_names[$i]];
                if($prev_stream->controller->is_output_eof()) {
                    $next_stream->controller->input_eof();
                }

                $next_stream->append_bytes(
                    $prev_stream->controller->output_bytes,
                    $this->tick_context
                );
                if (true !== $this->stream_next($next_stream)) {
                    return false;
                }
                $this->push_stream($next_stream);
                $prev_stream = $next_stream;
            }

            // When the last process in the chain produces output,
            // we write it to the output pipe and bale.
            if($this->last_stream->controller->is_output_eof()) {
                $this->controller->finish();
                break;
            }
            $this->controller->file_id = $this->last_stream->controller->get_file_id();
            $this->controller->output_bytes = $this->last_stream->get_bytes();

            ++$this->chunk_nb;
            return true;
        }

        // We produced no output and the upstream pipe is EOF.
        // We're done.
        if($this->first_stream->controller->is_output_eof()) {
            $this->finish();
        }

        return false;
    }

    protected function finish()
    {
        $this->controller->finish();
        foreach($this->streams as $stream) {
            $stream->controller->finish();
        }        
    }

    private function pop_stream(): Byte_Stream
    {
        $name = $this->streams_names[count($this->execution_stack) - 1];
        unset($this->tick_context[$name]);
        return array_pop($this->execution_stack);        
    }

    private function push_stream(Byte_Stream $stream)
    {
        array_push($this->execution_stack, $stream);
        $name = $this->streams_names[count($this->execution_stack) - 1];
        if($stream instanceof Demultiplexer) {
            $stream = $stream->get_substream();
        }
        $this->tick_context[$name] = $stream;
    }

    private function stream_next(Byte_Stream $stream)
    {
        $produced_output = $stream->next_bytes();
        $this->handle_errors($stream);
        return $produced_output;
    }

    private function handle_errors(Byte_Stream $stream)
    {
        if($stream->controller->get_last_error()) {
            $name = array_search($stream, $this->streams);
            $this->controller->set_last_error("Process $name has crashed");
        }
    }

    // Iterator methods. These don't make much sense on a regular
    // process class because they cannot pull more input chunks from
    // the top of the stream like ProcessChain can.

	public function current(): mixed {
        if($this->should_iterate_errors && $this->get_last_error()) {
            return $this->get_last_error();
        }
        return $this->get_bytes();
	}

    private $chunk_nb = -1;
	public function key(): mixed {
		return $this->chunk_nb;
	}

	public function rewind(): void {
		$this->next();
	}

    private $should_iterate_errors = false;
    public function iterate_errors($should_iterate_errors)
    {
        $this->should_iterate_errors = $should_iterate_errors;
    }

	public function next(): void {
        ++$this->chunk_nb;
		while(!$this->next_bytes()) {
            if($this->should_iterate_errors && $this->controller->get_last_error()) {
                break;
            }
            if($this->controller->is_output_eof()) {
                break;
            }
			usleep(10000);
		}
	}

	public function valid(): bool {
		return !$this->controller->is_output_eof() || ($this->should_iterate_errors && $this->controller->get_last_error());
	}


    // ArrayAccess on ProcessChain exposes specific
    // sub-processes by their names.
    public function offsetExists($offset): bool {
        return isset($this->tick_context[$offset]);
    }

    public function offsetGet($offset): mixed {
        return $this->tick_context[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void {
        // No op
    }

    public function offsetUnset($offset): void {
        // No op
    }

}


// Imagine this method is implemented in the WP_XML_Processor
class XML_Processor
{
    static public function stream()
    {
        return new Demultiplexer(function () {
            $xml_processor = new WP_XML_Processor('', [], WP_XML_Processor::IN_PROLOG_CONTEXT);
            $node_visitor_callback = function () {};
            return new ProcessorByteStream($xml_processor, function (ByteStreamController $controller) use ($xml_processor, $node_visitor_callback) {
                $new_bytes = $controller->consume_input_bytes();
                if (null !== $new_bytes) {
                    $xml_processor->append_bytes($new_bytes);
                }

                $tokens_found = 0;
                while ($xml_processor->next_token()) {
                    ++$tokens_found;
                    $node_visitor_callback($xml_processor);
                }

                $buffer = '';
                if ($tokens_found > 0) {
                    $buffer .= $xml_processor->get_updated_xml();
                } else if (
                    $tokens_found === 0 &&
                    !$xml_processor->is_paused_at_incomplete_input() &&
                    $xml_processor->get_current_depth() === 0
                ) {
                    // We've reached the end of the document, let's finish up.
                    // @TODO: Fix this so it doesn't return the entire XML
                    $buffer .= $xml_processor->get_unprocessed_xml();
                    $controller->finish();
                }

                if (!strlen($buffer)) {
                    return false;
                }

                $controller->output_bytes = $buffer;
                return true;
            });
        });
    }
}

// Imagine this method is implemented in the Client class
class HTTP_Client
{
    static public function stream($requests)
    {
        $client = new Client();
        $client->enqueue($requests);
        return new Byte_Stream(function (ByteStreamController $controller) use ($client) {
            $request = null;
            while ($client->await_next_event()) {
                $request = $client->get_request();
                switch ($client->get_event()) {
                    case Client::EVENT_BODY_CHUNK_AVAILABLE:
                        $controller->file_id = $request->id;
                        $controller->output_bytes = $client->get_response_body_chunk();
                        return true;
                    case Client::EVENT_FAILED:
                        $controller->set_last_error('Request failed: ' . $request->error);
                        break;
                }
            }

            $controller->finish();
            return false;
        });
    }
}

// Imagine this method is implemented in the ZipStreamReader class
class ZIP_Processor
{
    static public function stream()
    {
        return new Demultiplexer(function () {
            $zip_reader = new ZipStreamReader('');
            return new ProcessorByteStream($zip_reader, function (ByteStreamController $controller) use ($zip_reader) {
                $new_bytes = $controller->consume_input_bytes();
                if (null !== $new_bytes) {
                    $zip_reader->append_bytes($new_bytes);
                }

                while ($zip_reader->next()) {
                    switch ($zip_reader->get_state()) {
                        case ZipStreamReader::STATE_FILE_ENTRY:
                            $controller->file_id = $zip_reader->get_file_path();
                            $controller->output_bytes = $zip_reader->get_file_body_chunk();
                            return true;
                    }
                }

                return false;
            });
        });
    }
}

$chain = new StreamChain(
    [
        'http' => HTTP_Client::stream([
            new Request('http://127.0.0.1:9864/export.wxr.zip'),
            // new Request('http://127.0.0.1:9864/export.wxr.zip'),
            // Bad request, will fail:
            new Request('http://127.0.0.1:9865')
        ]),
        'zip' => ZIP_Processor::stream(),
        Byte_Stream::map(function($bytes, $context) {
            if($context['zip']->get_file_id() === 'export.wxr') {
                $context['zip']->skip_file();
                return null;
            }
            return $bytes;
        }),
        'xml' => XML_Processor::stream(),
        Byte_Stream::map(function($bytes) { return strtoupper($bytes); }),
    ]
);

// Consume the data like this:
// var_dump([$chain->next_chunk(), strlen($chain->get_bytes()), $chain->get_last_error()]);

// Or like this:
// $chain->iterate_errors(true);
foreach($chain as $k => $chunk_or_error) {
    var_dump([
        $k => $chunk_or_error,
        'is_error' => !!$chain->get_last_error(),
        'zip file_id' => isset($chain['zip']) ? $chain['zip']->get_file_id() : null
    ]);
}

