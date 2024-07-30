<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/zip-stream-reader.php';

use WordPress\AsyncHttp\Client;
use WordPress\AsyncHttp\Request;

/**
 * This interface describes standalone streams, but it can also be
 * used to describe a stream Processor like WP_XML_Processor.
 * 
 * In this prototype there are no pipes, streams, and processors. There
 * are only Byte Streams that can be chained together with the StreamChain
 * class.
 */
interface IByteStream {
    const STATE_STREAMING = '#streaming';
    const STATE_FINISHED = '#finished';

    public function next_bytes(): bool;
    public function input_eof();
    public function append_bytes(string $bytes, $context = null);
    public function is_output_eof(): bool;
    public function get_bytes(): ?string;
    public function get_last_error(): ?string;
}

interface IFilesStream extends IByteStream {
    public function get_file_id(): ?string;
    public function skip_file(): void;
}

abstract class ByteStream implements IByteStream {
    private bool $input_eof = false;
    private ?string $input_bytes = null;
    private ?string $output_bytes = null;
    protected string $state = IByteStream::STATE_STREAMING;
    private ?string $last_error = null;
    private $last_skipped_file = null;
    protected $input_context = null;

    public function append_bytes(string $bytes, $context = null) {
        $this->input_bytes .= $bytes;
        $this->input_context = $context;
    }

    public function input_eof() {
        $this->input_eof = true;
    }

    public function is_output_eof(): bool {
        return !$this->get_bytes() && $this->state === IByteStream::STATE_FINISHED;
    }

    public function get_last_error(): ?string {
        return $this->last_error;
    }

    protected function set_last_error($error) {
        $this->last_error = $error;
    }

    public function skip_file()
    {
        $this->last_skipped_file = $this->get_file_id();
    }
    
    protected function consume_input_bytes() {
        $bytes = $this->input_bytes;
        $this->input_bytes = null;
        return $bytes;
    }

    public function next_bytes(): bool
    {
        $this->output_bytes = null;
        $this->last_error = null;
        if(IByteStream::STATE_FINISHED === $this->state) {
            return false;
        }

        if($this->is_output_eof()) {
            return false;
        }

        // Process any remaining buffered input:
        if($this->tick()) {
            return $this->get_file_id() !== $this->last_skipped_file;
        }

        if (!$this->input_bytes) {
            if ($this->input_eof) {
                $this->finish();
            }
            return false;
        }

        return $this->tick() && $this->get_file_id() !== $this->last_skipped_file;
    }

    abstract protected function tick(): bool;

    protected function finish()
    {
        $this->state = IByteStream::STATE_FINISHED;
    }

    public function get_bytes(): ?string
    {
        return $this->output_bytes;
    }

    protected function set_output_bytes($bytes)
    {
        $this->output_bytes = $bytes;        
    }

    public function get_file_id(): ?string
    {
        return 'default';
    }

}

class ZipReaderStream extends ByteStream {

    /**
     * @var ZipStreamReader
     */
	protected IStreamProcessor $processor;
    private $file_id;

    static public function create() {
        return new Demultiplexer(fn() => new ZipReaderStream());
    }

    public function __construct()
    {
        $this->processor = new ZipStreamReader('');
    }

    public function get_file_id(): string|null
    {
        return $this->file_id;
    }

    public function get_processor()
    {
        return $this->processor;        
    }

    public function append_bytes(string $bytes, $context = null)
    {
        $this->processor->append_bytes($bytes);
    }

    protected function tick(): bool
    {
        $this->file_id = null;
        while ($this->processor->next()) {
            switch ($this->processor->get_state()) {
                case ZipStreamReader::STATE_FILE_ENTRY:
                    $file_path = $this->processor->get_file_path();
                    $this->file_id = $file_path;
                    $this->set_output_bytes($this->processor->get_file_body_chunk());
                    return true;
            }
        }

        return false;        
    }
}

class XMLTransformStream extends ByteStream {
	private $node_visitor_callback;
    /**
     * @var WP_XML_Processor
     */
	protected IStreamProcessor $processor;

    static public function create($node_visitor_callback) {
        return new Demultiplexer(fn () =>
            new XMLTransformStream($node_visitor_callback)
        );
    }

	public function __construct( $node_visitor_callback ) {
		$this->node_visitor_callback = $node_visitor_callback;
        $this->processor = new WP_XML_Processor( '', [], WP_XML_Processor::IN_PROLOG_CONTEXT );
	}

    public function append_bytes(string $bytes, $context = null)
    {
        $this->processor->append_bytes($bytes);
    }

    protected function tick(): bool
    {
        $tokens_found = 0;
		while ( $this->processor->next_token() ) {
			++ $tokens_found;
			$node_visitor_callback = $this->node_visitor_callback;
			$node_visitor_callback( $this->processor );
		}

        $buffer = '';
		if ( $tokens_found > 0 ) {
			$buffer .= $this->processor->get_updated_xml();
		} else if ( 
            $tokens_found === 0 && 
            ! $this->processor->is_paused_at_incomplete_input() &&
            $this->processor->get_current_depth() === 0
        ) {
            // We've reached the end of the document, let's finish up.
            // @TODO: Fix this so it doesn't return the entire XML
			$buffer .= $this->processor->get_unprocessed_xml();
            $this->finish();
		}

        if(!strlen($buffer)) {
            return false;
        }

        $this->set_output_bytes($buffer);

        return true;
    }
}

class CallbackStream extends ByteStream {
    private $callback;
    
    static public function create($callback) {
        return new static($callback);
    }

    public function __construct($callback) {
        $this->callback = $callback;
    }

    protected function tick(): bool {
        $bytes = $this->consume_input_bytes();
        if(!$bytes) {
            return false;
        }
        $output = ($this->callback)($bytes, $this->input_context);
        if(null === $output) {
            return false;
        }
        $this->set_output_bytes($output);
        return true;
    }
}

class Demultiplexer extends ByteStream {
    private $stream_factory = [];
    private $streams = [];
    private $last_stream;
    private $last_input_key;
    private $key;
    
    public function __construct($stream_factory) {
        $this->stream_factory = $stream_factory;
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
            $this->set_output_bytes($stream->get_bytes());
            return true;
        }

        if($stream->get_last_error()) {
            $this->set_last_error($stream->get_last_error());
        }

        return false;
    }

    public function append_bytes(string $data, $context = null): bool {
        $chunk_key = 'default';
        if($context) {
            $chunk_key = [];
            foreach($context as $stream) {
                $chunk_key[] = $stream->get_file_id();
            }
            $chunk_key = implode(':', $chunk_key);
        }

        $this->last_input_key = $chunk_key;
        if (!isset($this->streams[$chunk_key])) {
            $create = $this->stream_factory;
            $this->streams[$chunk_key] = $create();
        }
        $stream = $this->streams[$chunk_key];
        $stream->append_bytes($data, $context);
        $this->last_stream = $stream;
        return true;
    }

    protected function finish()
    {
        parent::finish();
        foreach($this->streams as $stream) {
            $stream->finish();
        }        
    }
}

class StreamChain extends ByteStream implements ArrayAccess, Iterator {
    private $first_stream;
    private $last_stream;
    /**
     * @var IByteStream[]
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
        if($this->last_stream->is_output_eof()) {
            $this->finish();
            return false;
        }

        while(true) {
            $bytes = $this->consume_input_bytes();
            if(null === $bytes || false === $bytes) {
                break;
            }
            $this->first_stream->append_bytes(
                $bytes
            );
        }

        if($this->is_output_eof()) {
            $this->first_stream->input_eof();
        }

        if(empty($this->execution_stack)) {
            array_push($this->execution_stack, $this->first_stream);
        }

        while (count($this->execution_stack)) {
            // Unpeel the context stack until we find a stream that
            // produces output.
            $stream = $this->pop_stream();
            if ($stream->is_output_eof()) {
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
                if($prev_stream->is_output_eof()) {
                    $next_stream->input_eof();
                }

                $next_stream->append_bytes(
                    $prev_stream->get_bytes(),
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
            if($this->last_stream->is_output_eof()) {
                $this->finish();
                break;
            }
            $this->set_output_bytes(
                $this->last_stream->get_bytes()
            );
            // ++$this->chunk_nb;
            return true;
        }

        // We produced no output and the upstream pipe is EOF.
        // We're done.
        if($this->first_stream->is_output_eof()) {
            $this->finish();
        }

        return false;
    }

    protected function finish()
    {
        parent::finish();
        foreach($this->streams as $stream) {
            $stream->finish();
        }        
    }

    private function pop_stream(): IByteStream
    {
        $name = $this->streams_names[count($this->execution_stack) - 1];
        unset($this->tick_context[$name]);
        return array_pop($this->execution_stack);        
    }

    private function push_stream(IByteStream $stream)
    {
        array_push($this->execution_stack, $stream);
        $name = $this->streams_names[count($this->execution_stack) - 1];
        if($stream instanceof Demultiplexer) {
            $stream = $stream->get_substream();
        }
        $this->tick_context[$name] = $stream;
    }

    private function stream_next(IByteStream $stream)
    {
        $produced_output = $stream->next_bytes();
        $this->handle_errors($stream);
        return $produced_output;
    }

    private function handle_errors(IByteStream $stream)
    {
        if($stream->get_last_error()) {
            $name = array_search($stream, $this->streams);
            $this->set_last_error("Process $name has crashed");
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
            if($this->should_iterate_errors && $this->get_last_error()) {
                break;
            }
            if($this->is_output_eof()) {
                break;
            }
			usleep(10000);
		}
	}

	public function valid(): bool {
		return !$this->is_output_eof() || ($this->should_iterate_errors && $this->get_last_error());
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


class HttpStream extends ByteStream {
	private $client;
	private $requests = [];
	private $child_contexts = [];
	private $skipped_requests = [];
    private $file_id;
    private $request;

    static public function create($requests) {
        return new HttpStream($requests);
    }

	private function __construct( $requests ) {
		$this->client = new Client();
		$this->client->enqueue( $requests );
	}

    public function get_file_id(): string|null
    {
        return $this->request ? 'request_' . $this->request->id : null;
    }

    protected function tick(): bool
    {
        $this->request = null;
        while($this->client->await_next_event()) {
            $this->request = $this->client->get_request();
            switch ($this->client->get_event()) {
                case Client::EVENT_BODY_CHUNK_AVAILABLE:
                    $this->set_output_bytes($this->client->get_response_body_chunk());
                    return true;

                case Client::EVENT_FAILED:
                    $this->set_last_error('Request failed: ' . $this->request->error);
                    break;
            }
        }

        $this->finish();
        return false;
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


$chain = new StreamChain(
    [
        'http' => HttpStream::create([
            new Request('http://127.0.0.1:9864/export.wxr.zip'),
            // Bad request, will fail:
            new Request('http://127.0.0.1:9865'),
        ]),
        'zip' => ZipReaderStream::create(),
        CallbackStream::create(function ($data, $context) {
            if ($context['zip']->get_file_id() !== 'export.wxr') {
                $context['zip']->skip_file();
                return null;
            }
            // Print detailed information from the ZIP processor
            // print_r($context['zip']->get_processor()->get_header());
            return $data;
        }),
        XMLTransformStream::create(function (WP_XML_Processor $processor) {
            if (is_wxr_content_node($processor)) {
                $text = $processor->get_modifiable_text();
                $updated_text = 'Hey there, what\'s up?';
                if ($updated_text !== $text) {
                    // @TODO: Fix stream updating XML
                    // $processor->set_modifiable_text($updated_text);
                }
            }
        }),
        CallbackStream::create(function ($data, $context) {
            return strtoupper($data);
        })
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

