<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/zip-stream-reader.php';

use WordPress\AsyncHttp\Client;
use WordPress\AsyncHttp\Request;

abstract class Byte_Stream {

    protected $state;
    
    public function __construct() {
        $this->state = new ByteStreamState();
    }

    public function is_eof(): bool {
        return !$this->state->output_bytes && $this->state->state === ByteStreamState::STATE_FINISHED;
    }

    public function get_file_id()
    {
        return $this->state->file_id;
    }

    public function skip_file(): void {
        $this->state->last_skipped_file = $this->state->file_id;
    }
    
    public function is_skipped_file()
    {
        return $this->state->file_id === $this->state->last_skipped_file;
    }

    public function get_chunk_type()
    {
        if($this->get_last_error()) {
            return '#error';
        }

        if ($this->is_eof()) {
            return '#eof';
        }

        return '#bytes';        
    }

    public function append_eof() {
        $this->state->input_eof = true;
    }

    public function append_bytes(string $bytes, $context = null) {
        $this->state->input_bytes .= $bytes;
        $this->state->input_context = $context;
    }

    public function get_bytes()
    {
        return $this->state->output_bytes;
    }

    public function next_bytes()
    {
        $this->state->reset_output();
        if($this->is_eof()) {
            return false;
        }

        // Process any remaining buffered input:
        if($this->generate_next_chunk()) {
            return ! $this->is_skipped_file();
        }

        if (!$this->state->input_bytes) {
            if ($this->state->input_eof) {
                $this->finish();
            }
            return false;
        }

        $produced_bytes = $this->generate_next_chunk();

        return $produced_bytes && ! $this->is_skipped_file();
    }

    abstract protected function generate_next_chunk(): bool;

    public function get_last_error(): string|null
    {
        return $this->state->last_error;
    }

    // Utility methods
    static public function map($mapper) {
        return new Callback_Byte_Stream(function($state) use ($mapper) {
            $bytes = $state->consume_input_bytes();
            if(!$bytes) {
                return false;
            }
            $output = $mapper($bytes, $state->input_context);
            if(null === $output) {
                return false;
            }
            $state->output_bytes = $output;
            return true;
        });
    }

}

class Callback_Byte_Stream extends Byte_Stream {

    protected $generate_next_chunk_callback;

    public function __construct($generate_next_chunk_callback) {
        $this->generate_next_chunk_callback = $generate_next_chunk_callback;
        parent::__construct();
    }

    protected function generate_next_chunk(): bool {
        return ($this->generate_next_chunk_callback)($this->state);
    }

}

class Paused_Stream {
    public $class;
    public $data;

    public function __construct($class, $data) {
        $this->class = $class;
        $this->data = $data;
    }
}

class File_Byte_Stream extends Byte_Stream {

    protected $file_path;
    protected $chunk_size;
    protected $file_pointer;
    protected $offset_in_file;

    public function __construct($file_path, $chunk_size = 8096) {
        $this->file_path = $file_path;
        $this->chunk_size = $chunk_size;
        parent::__construct();
    }

    public function pause()
    {
        return new Paused_Stream(get_class($this), [
            'file_path' => $this->file_path,
            'chunk_size' => $this->chunk_size,
            'offset_in_file' => $this->offset_in_file,
            'output_bytes' => $this->state->output_bytes,
        ]);
    }

    public function resume($paused_state)
    {
        $data = $paused_state['data'];
        $this->offset_in_file = $data['offset_in_file'];
        $this->state->output_bytes = $data['output_bytes'];
    }

    protected function generate_next_chunk(): bool {
        if(!$this->file_pointer) {
            $this->file_pointer = fopen($this->file_path, 'r');
            if($this->offset_in_file) {
                fseek($this->file_pointer, $this->offset_in_file);
            }
        }
        $bytes = fread($this->file_pointer, $this->chunk_size);
        if(!$bytes && feof($this->file_pointer)) {
            fclose($this->file_pointer);
            $this->state->finish();
            return false;
        }
        $this->offset_in_file += strlen($bytes);
        $this->state->output_bytes .= $bytes;
        return true;
    }

}

class ProcessorByteStream extends Callback_Byte_Stream
{
    public $processor;

    public function __construct($processor, $generate_next_chunk_callback)
    {
        $this->processor = $processor;
        parent::__construct($generate_next_chunk_callback);
    }

    static public function demuxed($processor_factory, $callback)
    {
        return new Demultiplexer(function () use ($processor_factory, $callback) {
            $processor = $processor_factory();
            return new ProcessorByteStream($processor, function($state) use($processor, $callback) {
                $new_bytes = $state->consume_input_bytes();
                if (null !== $new_bytes) {
                    $processor->append_bytes($new_bytes);
                }
                return $callback($processor, $state);
            });
        });
    }

    public function pause()
    {
        return new Paused_Stream(get_class($this), [
            'processor' => $this->processor->pause(),
            'output_bytes' => $this->state->output_bytes,
        ]);
    }

    public function resume($paused_state)
    {
        $data = $paused_state['data'];
        $this->processor->resume($data['processor']);
        $this->state->output_bytes = $data['output_bytes'];
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
class ByteStreamState {
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

    public function reset_output()
    {
        $this->output_bytes = null;
        $this->file_id = 'default';
        $this->last_error = null;
    }

    public function consume_input_bytes() {
        $bytes = $this->input_bytes;
        $this->input_bytes = null;
        return $bytes;
    }

    public function finish()
    {
        $this->state = self::STATE_FINISHED;
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
        parent::__construct();
    }

    public function pause()
    {
        $paused_streams = [];
        foreach($this->streams as $name => $stream) {
            $paused_streams[$name] = $stream->pause();
        }
        return new Paused_Stream(get_class($this), [
            'streams' => $paused_streams,
            'last_stream' => array_search($this->last_stream, $this->streams),
            'last_input_key' => $this->last_input_key,
        ]);
    }

    public function resume($paused_state)
    {
        foreach($paused_state['data']['streams'] as $name => $stream) {
            if(!isset($this->streams[$name])) {
                $create = $this->stream_factory;
                $this->streams[$name] = $create();
            }
            $this->streams[$name]->resume($stream);
        }
        $this->last_stream = $this->streams[$paused_state['data']['last_stream']];
        $this->last_input_key = $paused_state['data']['last_input_key'];
    }

    public function get_substream()
    {
        return $this->last_stream;
    }

    protected function generate_next_chunk(): bool
    {
        $stream = $this->last_stream;
        if (!$stream) {
            return false;
        }

        if($stream->next_bytes()) {
            $this->state->file_id = $stream->state->file_id;
            $this->state->output_bytes = $stream->state->output_bytes;
            return true;
        }

        if($stream->state->last_error) {
            $this->state->last_error = $stream->state->last_error;
        }
        return false;
    }

    public function append_bytes(string $data, $context = null): bool {
        $chunk_key = 'default';
        if($context) {
            $chunk_key = [];
            foreach($context as $k=>$stream) {
                $chunk_key[] = $stream->state->file_id;
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
        $this->state->finish();
        foreach($this->streams as $stream) {
            $stream->state->finish();
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
    private $chunk_context = [];

    public function __construct($streams) {
        $this->chunk_context['chain'] = $this;

        $named_streams = [];
        foreach($streams as $name => $stream) {
            $string_name = is_numeric($name) ? 'stream_' . $name : $name;
            $named_streams[$string_name] = $streams[$name];
        }

        $this->streams = $named_streams;
        $this->streams_names = array_keys($this->streams);
        $this->first_stream = $this->streams[$this->streams_names[0]];
        $this->last_stream = $this->streams[$this->streams_names[count($streams) - 1]];
        parent::__construct();
    }

    public function pause()
    {
        $paused_streams = [];
        foreach($this->streams as $name => $stream) {
            $paused_streams[$name] = $stream->pause();
        }
        $paused_execution_stack = [];
        foreach($this->execution_stack as $stream) {
            $name = array_search($stream, $this->streams);
            $paused_execution_stack[] = $name;
        }
        return new Paused_Stream(get_class($this), [
            'streams' => $paused_streams,
            'execution_stack' => $paused_execution_stack,
        ]);
    }

    public function resume($paused_state)
    {
        $data = $paused_state['data'];
        foreach($data['streams'] as $name => $stream) {
            $this->streams[$name]->resume($stream);
        }
        foreach($data['execution_stack'] as $name) {
            $this->push_stream($this->streams[$name]);
        }
    }

    /**
     * ## Next chunk generation
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
    protected function generate_next_chunk(): bool {
        if($this->last_stream->is_eof()) {
            $this->state->finish();
            return false;
        }

        while(true) {
            $bytes = $this->state->consume_input_bytes();
            if(null === $bytes || false === $bytes) {
                break;
            }
            $this->first_stream->append_bytes(
                $bytes
            );
        }

        if($this->is_eof()) {
            $this->first_stream->state->append_eof();
        }

        if(empty($this->execution_stack)) {
            array_push($this->execution_stack, $this->first_stream);
        }

        while (count($this->execution_stack)) {
            // Unpeel the context stack until we find a stream that
            // produces output.
            $stream = $this->pop_stream();
            if ($stream->is_eof()) {
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
                if($prev_stream->is_eof()) {
                    $next_stream->append_eof();
                }

                $next_stream->append_bytes(
                    $prev_stream->state->output_bytes,
                    $this->chunk_context
                );
                if (true !== $this->stream_next($next_stream)) {
                    return false;
                }
                $this->push_stream($next_stream);
                $prev_stream = $next_stream;
            }

            // When the last process in the chain produces output,
            // we write it to the output pipe and bale.
            if($this->last_stream->is_eof()) {
                $this->state->finish();
                break;
            }
            $this->state->file_id = $this->last_stream->state->file_id;
            $this->state->output_bytes = $this->last_stream->state->output_bytes;
            return true;
        }

        // We produced no output and the upstream pipe is EOF.
        // We're done.
        if($this->first_stream->is_eof()) {
            $this->finish();
        }

        return false;
    }

    protected function finish()
    {
        $this->state->finish();
        foreach($this->streams as $stream) {
            $stream->state->finish();
        }        
    }

    private function pop_stream(): Byte_Stream
    {
        $name = $this->streams_names[count($this->execution_stack) - 1];
        unset($this->chunk_context[$name]);
        return array_pop($this->execution_stack);
    }

    private function push_stream(Byte_Stream $stream)
    {
        array_push($this->execution_stack, $stream);
        $name = $this->streams_names[count($this->execution_stack) - 1];
        if($stream instanceof Demultiplexer) {
            $stream = $stream->get_substream();
        }
        $this->chunk_context[$name] = $stream;
    }

    private function stream_next(Byte_Stream $stream)
    {
        $produced_output = $stream->next_bytes();
        if($stream->state->last_error) {
            $name = array_search($stream, $this->streams);
            $this->state->last_error = "Process $name has crashed (" . $stream->state->last_error . ")";
        }
        return $produced_output;
    }

    // Iterator methods. These don't make much sense on a regular
    // process class because they cannot pull more input chunks from
    // the top of the stream like ProcessChain can.

	public function current(): mixed {
        return $this;
	}

	public function key(): mixed {
		return $this->get_chunk_type();
	}

	public function rewind(): void {
		$this->next();
	}

    private $should_stop_on_errors = false;
    public function stop_on_errors($should_stop_on_errors)
    {
        $this->should_stop_on_errors = $should_stop_on_errors;
    }

	public function next(): void {
		while(!$this->next_bytes()) {
            if($this->should_stop_on_errors && $this->state->last_error) {
                break;
            }
            if($this->is_eof()) {
                break;
            }
			usleep(10000);
		}
	}

	public function valid(): bool {
		return !$this->is_eof() || ($this->should_stop_on_errors && $this->state->last_error);
	}


    // ArrayAccess on ProcessChain exposes specific
    // sub-processes by their names.
    public function offsetExists($offset): bool {
        return isset($this->chunk_context[$offset]);
    }

    public function offsetGet($offset): mixed {
        return $this->chunk_context[$offset] ?? null;
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
    static public function stream($node_visitor_callback)
    {
        return ProcessorByteStream::demuxed(
            function () { return new WP_XML_Processor('', [], WP_XML_Processor::IN_PROLOG_CONTEXT); },
            function (WP_XML_Processor $xml_processor, ByteStreamState $state) use ($node_visitor_callback) {
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
                    $state->finish();
                }

                if (!strlen($buffer)) {
                    return false;
                }

                $state->output_bytes = $buffer;
                return true;
            }
        );
    }
}

// Imagine this method is implemented in the Client class
class HTTP_Client
{
    static public function stream($requests)
    {
        $client = new Client();
        $client->enqueue($requests);
        return new Callback_Byte_Stream(function (ByteStreamState $state) use ($client) {
            $request = null;
            while ($client->await_next_event()) {
                $request = $client->get_request();
                switch ($client->get_event()) {
                    case Client::EVENT_BODY_CHUNK_AVAILABLE:
                        $state->file_id = $request->id;
                        $state->output_bytes = $client->get_response_body_chunk();
                        return true;
                    case Client::EVENT_FAILED:
                        $state->last_error = 'Request failed: ' . $request->error;
                        break;
                }
            }

            $state->finish();
            return false;
        });
    }
}

// Imagine this method is implemented in the ZipStreamReader class
class ZIP_Reader
{
    static public function stream()
    {
        return ProcessorByteStream::demuxed(
            function () { return new ZipStreamReader(); },
            function (ZipStreamReader $zip_reader, ByteStreamState $state) {
                while ($zip_reader->next()) {
                    switch ($zip_reader->get_state()) {
                        case ZipStreamReader::STATE_FILE_ENTRY:
                            $state->file_id = $zip_reader->get_file_path();
                            $state->output_bytes = $zip_reader->get_file_body_chunk();
                            return true;
                    }
                }
                return false;
            }
        );
    }
}
