<?php

/**
 * @TODO:
 * 
 * * Find a naming scheme that doesn't suggest we're working with actual Unix processes and pipes.
 *   I only used it to make the development easier, I got confused with the other attempt in
 *   `pipes.php` and this kept me on track. However, keeping these names will likely confuse others.
 * * Make Process implement the Iterator interface
 * * The process `do_tick` method typically checks for `stdin->is_eof()` and then
 *   whether `stdin->read()` is valid. Can we simplify this boilerplate somehow?
 * * Explore a shared "Streamable" interface for all stream processors (HTML, XML, ZIP, HTTP, etc.)
 * * ✅ Get rid of ProcessManager
 * * ✅ Get rid of stderr. We don't need it to be a stream. A single $error field + bubbling should do.
 *      Let's keep stderr after all.
 * * ✅ Remove these methods: set_write_channel, ensure_output_channel, add_output_channel, close_output_channel
 * * Explore semantic updates to metadata:
 *   * Exposing metadata on a stream instance instead of a pipe
 *   * Not writing bytes to a pipe but writing a new Chunk($bytes, $metadata) object to tightly couple the two
 * * Demultiplexing modes: per input channel, per $metadata['file_id'].
 * * Figure out interop Pipe and MultiChannelPipe – they are not interchangeable. Maybe
 *   we could use metadata to pass the channel name, and the regular pipe would ignore it?
 *   Maybe a MultiChannelPipe would just have special semantics for that metadata field?
 *   And it would keep track of eofs etc using a set of internal Pipe instances?
 * * Calling get_metadata() without calling read() first returns the last metadata. This
 *   bit me a few times when I was in a context where I could not call read() first because,
 *   e.g. another process was about to do that. Maybe this is a good thing, as it forces us
 *   to split a pipe in two whenever an intermediate read is involved, e.g. Process A wouldn't
 *   just connect it's stdin to a subprocess A.1, but it would read from stdin, read metadata,
 *   do processing, ant only then write to A.1 stdin. Still, a better error reporting wouldn't hurt.
 * * Declare `bool` return type everywhere where it's missing. We may even remove it later for PHP BC,
 *   but let's still add it for a moment just to make sure we're not missing any typed return.
 * * Should Process::tick() return a boolean? Or is it fine if it doesn't return anything?
 * * Pipe::read() returns a string on success, false on failure, or null if there were no writes
 *   since the last read and we'd just return an empty string. This three-state semantics is useful,
 *   but it's painful to always check for false and null, and then it may not interop well with
 *   PHP streams where fread() never returns null. Let's think this through some more.
 */

/**
 * ## Demultiplexing modes: per input channel, per $metadata['file_id'].
 * 
 * We want to keep track of:
 * * Stream ID – the sequential byte stream identifier. Multiple streams will produce
 *               file chunks in an arbitrary order and, when multiplexed, the chunks will be
 *               interleaved.
 * * File ID   – the file within that stream. A single stream may contain multiple files,
 *               but they will always be written sequentially. When multiplexed, one file will
 *               always be written completely before the next one is started.
 * 
 * When a specific stream errors out, we need to communicate this
 * downstream and so the consumer processes can handle the error.
 * 
 * Therefore, we need a separate pipe for each stream ID. Do we also
 * need a separate process? Not necessarily. Each process only cares
 * about the open-ness or EOF-ness of its input and output pipes,
 * not about the actual lifecycle of the other processes.
 * 
 * However, we may want to correlate the same stream ID with stdout and
 * stderr streams, in which case intertwining stream ID and process ID
 * would be useful. But then we don't have a 1:1 mapping between
 * what a data stream does and what a process does.
 * 
 * Let's try these two approach and see where we get with it:
 * 
 * 1. Each process has a multiplexed stdin, stdout, and stderr pipes.
 *    We do not use non-multiplexed pipes at all. Every process communicates
 *    "there will be more output to come" by keeping at least one output
 *    pipe open. Each process makes sure to react to sub-pipe state changes.
 *    When a read() operation is called and a specific sub-pipe is EOF, 
 *    that process cleans up its sub resources and closes the corresponding
 *    output sub-pipe.
 * 2. Each process has a single input and output pipe. A process
 *    that produces multiple data stream fakes spawning one child
 *    process per data stream. The next process gets multiple input
 *    pipes, but no actual access to the child processes of the first
 *    process. Then, it may spawn its own child processes. Hm. But that
 *    just sounds a multi-pipe solution with extra steps.
 */


/**
 * ## Get rid of stderr. We don't need it to be a stream. A single $error field + bubbling should do.
 * 
 * Maybe stderr is fine after all? I'm no longer convinced about inventing a separate mechanism
 * for error propagation. We'd have to implement a lot of the same features that stderr already
 * have.
 * 
 * Advantages of using stderr for propagating errors:
 * 
 * * We can bubble up multiple errors from a single process.
 * * They have metadata attached and are traceable to a specific process.
 * * Piping to stderr doesn't imply the entire process have crashed, which we
 *   wouldn't want in case of, say, Demultiplexer.
 * * We clearly know when the errors are done, as stderr is a stream and we know
 *   when it's EOF.
 * * We can put any pipe in place of stderr, e.g. a generic logger pipe
 * 
 * Disadvantages:
 * 
 * * Pipes have more features than error propagation uses, e.g. we rarely care
 *   for is_eof() on stderr, but we still have to close that errors pipe.
 */

use WordPress\AsyncHttp\Client;
use WordPress\AsyncHttp\Request;

abstract class Process {
    private ?int $exit_code = null;
    private bool $is_reaped = false;
    public Pipe $stdin;
    public Pipe $stdout;
    public Pipe $stderr;

    public function __construct($stdin=null, $stdout=null, $stderr=null)
    {
        $this->stdin = $stdin ?? new MultiChannelPipe();
        $this->stdout = $stdout ?? new MultiChannelPipe();
        $this->stderr = $stderr ?? new BufferPipe();
        $this->init();
    }

    public function run()
    {
        do {
            $this->tick();
            // @TODO: Implement error handling
            log_process_chain_errors($this);
        } while ($this->is_alive());
    }

    public function tick($tick_context=null) {
        if(!$this->is_alive()) {
            return;
        }

        return $this->do_tick($tick_context ?? []);
    }

    abstract protected function do_tick($tick_context);

    public function kill($code) {
        $this->exit_code = $code;
        $this->stdin->close();
        $this->stdout->close();
        $this->stderr->close();
    }

    public function reap()
    {
        if($this->is_alive()) {
            return false;
        }
        $this->is_reaped = true;
        $this->cleanup();
        return true;        
    }

    public function is_reaped()
    {
        return $this->is_reaped;        
    }

    public function has_crashed() {
        return $this->exit_code !== null && $this->exit_code !== 0;
    }

    public function is_alive() {
        return $this->exit_code === null;
    }

    protected function init() {
    }

    protected function cleanup() {
        // clean up resources
    }

    public function skip_file($file_id) {
        // Needs to be implemented by subclasses
        return false;
    }

}

abstract class TransformProcess extends Process {
    protected function do_tick($tick_context) {
        if($this->stdin->is_eof()) {
            $this->kill(0);
            return;
        }

        while (true) {
            $data = $this->stdin->read();
            if (null === $data || false === $data) {
                break;
            }
            $transformed = $this->transform($data, $tick_context);
            if (null === $transformed || false === $transformed) {
                break;
            }
            if (!$this->stdout->has_channel($this->stdin->get_current_channel())) {
                $this->stdout->add_channel($this->stdin->get_current_channel());
            }
            $this->stdout->set_write_channel($this->stdin->get_current_channel());
            $this->stdout->write($transformed, $this->stdin->get_metadata());
        }
    }

    abstract protected function transform($data, $tick_context);

}

interface Pipe {
    public function read();
    public function write(string $data, $metadata=null);
    public function is_eof();
    public function close();
    public function get_metadata();
}

class BufferPipe implements Pipe {
    public ?string $buffer = null;
    public $metadata = null;
    private bool $closed = false;

    public function __construct($buffer = null)
    {
        $this->buffer = $buffer;        
    }

    public function read() {
        $buffer = $this->buffer;
        if(!$buffer && $this->closed) {
            return false;
        }
        $this->buffer = null;
        return $buffer;
    }

    public function get_metadata() {
        return $this->metadata;        
    }

    public function write(string $data, $metadata=null) {
        if($this->closed) {
            return false;
        }
        if(null === $this->buffer) {
            $this->buffer = '';
        }
        $this->buffer .= $data;
        $this->metadata = $metadata;
    }

    public function is_eof() {
        return null === $this->buffer && $this->closed;        
    }

    public function close() {
        $this->closed = true;
    }
}

class ResourcePipe implements Pipe {
    public $resource;
    private bool $closed = false;

    public function __construct($resource) {
        $this->resource = $resource;
    }

    public function read() {
        if($this->closed) {
            return false;
        }
        $data = fread($this->resource, 1024);
        if(false === $data) {
            $this->close();
            return false;
        }

        if('' === $data) {
            if(feof($this->resource)) {
                $this->close();
            }
            return null;
        }

        return $data;
    }

    public function write(string $data, $metadata=null) {
        if($this->closed) {
            return false;
        }
        fwrite($this->resource, $data);
    }

    public function get_metadata() {
        return null;
    }

    public function is_eof() {
        return $this->closed;        
    }

    public function close() {
        if($this->closed) {
            return;
        }
        fclose($this->resource);
        $this->closed = true;
    }
}

class FilePipe extends ResourcePipe {
    public function __construct($filename, $mode) {
        parent::__construct(fopen($filename, $mode));
    }
}

/**
 * Idea 1: Use multiple pipes to pass multi-band I/O data between processes.
 */
class MultiChannelPipe implements Pipe {
    public $metadata;
    private array $channels = [];
    private ?string $last_read_channel = 'default';
    private ?string $current_channel = 'default';

    public function __construct()
    {
        $this->add_channel('default');
    }

    public function add_channel(string $name, $pipe = null) {
        if(isset($this->channels[$name])) {
            return false;
        }
        $this->channels[$name] = $pipe ?? new BufferPipe();
        return true;
    }

    public function read() {
        if (empty($this->channels)) {
            return false;
        }

        $this->metadata = null;
        $channels_to_check = $this->next_channels();
        foreach($channels_to_check as $channel_name) {
            $data = $this->channels[$channel_name]->read();
            if ($data === false || $data === null) {
                continue;
            }
            $this->last_read_channel = $this->current_channel = $channel_name;
            $this->metadata = $this->channels[$channel_name]->get_metadata();
            return $data;
        }

        return null;
    }

    private function next_channels() {
        $channels_queue = [];
        $channel_names = array_keys($this->channels);
        $last_read_channel_index = array_search($this->last_read_channel, $channel_names);
        if(false === $last_read_channel_index) {
            $last_read_channel_index = 0;
        } else if($last_read_channel_index > count($channel_names)) {
            $last_read_channel_index = count($channel_names) - 1;
        }

        $this->last_read_channel = null;
        for ($i = 1; $i <= count($channel_names); $i++) {
            $key_index = ($last_read_channel_index + $i) % count($channel_names);
            $channel_name = $channel_names[$key_index];
            if($this->channels[$channel_name]->is_eof()) {
                unset($this->channels[$channel_name]);
                continue;
            }
            $this->last_read_channel = $channel_name;
            $channels_queue[] = $channel_name;
        }
        return $channels_queue;
    }

    public function get_metadata() {
        return $this->metadata;
    }

    public function write(string $data, $metadata = null) {
        if (!isset($this->channels[$this->current_channel])) {
            return false;
        }

        $this->channels[$this->current_channel]->write($data, $metadata);
        return true;
    }

    public function close_channel($channel_name)
    {
        $this->current_channel = null;
        return $this->channels[$channel_name]->close();
    }

    public function set_write_channel($name)
    {
        $this->current_channel = $name;
    }

    public function has_channel($name)
    {
        return isset($this->channels[$name]);        
    }

    public function get_current_channel()
    {
        return $this->current_channel;
    }

    public function get_channel_pipe($index)
    {
        return $this->channels[$index];
    }

    public function is_eof() {
        foreach ($this->channels as $pipe) {
            if (!$pipe->is_eof()) {
                return false;
            }
        }
        return true;
    }

    public function close() {
        foreach ($this->channels as $pipe) {
            $pipe->close();
        }
    }
}


class Uppercaser extends TransformProcess {
    static public function stream() {
        return fn() => new static();
    }
    protected function transform($data, $tick_context) {
        return strtoupper($data);
    }
}

class CallbackProcess extends TransformProcess {
    private $callback;
    
    static public function stream($callback) {
        return fn () => new CallbackProcess($callback);
    }

    private function __construct($callback) {
        $this->callback = $callback;
        parent::__construct();
    }

    protected function transform($data, $tick_context) {
        $callback = $this->callback;
        return $callback($data, $tick_context, $this);
    }
}

class Demultiplexer extends Process {
    private $process_factory = [];
    public $subprocesses = [];
    private $killed_subprocesses = [];
    private $last_subprocess = [];
    public function __construct($process_factory) {
        $this->process_factory = $process_factory;
        parent::__construct();
    }

    protected function do_tick($tick_context) {
        if($this->stdin->is_eof()) {
            $this->kill(0);
            return;
        }

        while (true) {
            $next_chunk = $this->stdin->read();
            if (null === $next_chunk || false === $next_chunk) {
                break;
            }

            $input_channel = $this->stdin->get_current_channel();
            if (!isset($this->subprocesses[$input_channel])) {
                $this->stdout->add_channel($input_channel);
                $factory = $this->process_factory;
                $this->subprocesses[$input_channel] = $factory();
            }

            $subprocess = $this->subprocesses[$input_channel];
            $subprocess->stdin->write($next_chunk, $this->stdin->get_metadata());
            $subprocess->tick($tick_context);
            $this->last_subprocess = $subprocess;

            $output = $subprocess->stdout->read();
            if (null !== $output && false !== $output) {
                $this->stdout->set_write_channel($input_channel);
                $this->stdout->write($output, $subprocess->stdout->get_metadata());
            }

            if (!$subprocess->is_alive()) {
                if ($subprocess->has_crashed()) {
                    $this->stderr->write(
                        "Subprocess $input_channel has crashed with code {$subprocess->exit_code}",
                        [
                            'type' => 'crash',
                            'process' => $subprocess,
                        ]
                    );
                }
                $this->stdout->close_channel($input_channel);
            }
        }
    }

    public function skip_file($file_id)
    {
        if(!$this->last_subprocess) {
            return false;
        }
        return $this->last_subprocess->skip_file($file_id);
    }
}

require __DIR__ . '/zip-stream-reader.php';

class ZipReaderProcess extends Process {

    private $reader;
    private $last_skipped_file = null;

    static public function stream() {
        return fn () => new Demultiplexer(fn() => new ZipReaderProcess());
    }

    protected function init() {
        $this->reader = new ZipStreamReader('');
    }

    public function skip_file($file_id)
    {
        $this->last_skipped_file = $file_id;
    }

    protected function do_tick($tick_context) {
        if($this->stdin->is_eof()) {
            $this->kill(0);
            return;
        }

        while (true) {
            $bytes = $this->stdin->read();
            if (null === $bytes || false === $bytes) {
                break;
            }

            $this->reader->append_bytes($bytes);
            while ($this->reader->next()) {
                switch ($this->reader->get_state()) {
                    case ZipStreamReader::STATE_FILE_ENTRY:
                        $file_path = $this->reader->get_file_path();
                        if ($this->last_skipped_file === $file_path) {
                            break;
                        }
                        if (!$this->stdout->has_channel($file_path)) {
                            $this->stdout->add_channel($file_path);
                        }
                        $this->stdout->set_write_channel($file_path);
                        $this->stdout->write($this->reader->get_file_body_chunk(), [
                            'file_id' => $file_path
                        ]);
                        break;
                }
            }
        }
    }
}

class TickContext implements ArrayAccess {
    private $data;
    private $process;

    public function offsetExists($offset): bool {
        $this->get_metadata();
        return isset($this->data[$offset]);
    }

    public function offsetGet($offset): mixed {
        $this->get_metadata();
        return $this->data[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void {
        $this->data[$offset] = $value;
    }

    public function offsetUnset($offset): void {
        unset($this->data[$offset]);
    }

    public function __construct($process)
    {
        $this->process = $process;
    }

    public function get_metadata()
    {
        if(null === $this->data) {
            $this->data = $this->process->stdout->get_metadata();
        }
        return $this->data;
    }

    public function skip_file($file_id)
    {
        return $this->process->skip_file($file_id);        
    }

}

class ProcessChain extends Process {
    public array $process_factories;
    private $first_subprocess;
    private $last_subprocess;
    public $subprocesses = [];
    private $reaped_pids = [];

    public function __construct($process_factories) {
        $this->process_factories = $process_factories;
        parent::__construct();
    }

    protected function init() {
        $last_process = null;
        $names = array_keys($this->process_factories);
        foreach($names as $k => $name) {
            $names[$k] = $name . '';
        }

        $processes = array_values($this->process_factories);
        for($i = 0; $i < count($this->process_factories); $i++) {
            $factory = $processes[$i];
            $subprocess = $factory();
            if(null !== $last_process) {
                $subprocess->stdin = $last_process->stdout;
            }
            $this->subprocesses[$names[$i]] = $subprocess;
            $last_process = $subprocess;
        }

        $this->first_subprocess = $this->subprocesses[$names[0]];
        $this->last_subprocess = $this->subprocesses[$names[count($this->process_factories) - 1]];
    }

    protected function do_tick($tick_context) {
        $data = $this->stdin->read();
        if (null !== $data && false !== $data) {
            $this->first_subprocess->stdin->write($data, $this->stdin->get_metadata());
        }
        if($this->stdin->is_eof()) {
            $this->first_subprocess->stdin->close();
        }

        foreach ($this->subprocesses as $name => $process) {
            if ($process->is_alive()) {
                $process->tick($tick_context);
            }

            if(!$process->stdout->is_eof()) {
                $tick_context[$name] = new TickContext($process);
            }

            if($process->has_crashed()) {
                if (!$process->is_reaped()) {
                    $process->reap();
                    $this->stderr->write("Process $name has crashed with code {$process->exit_code}", [
                        'type' => 'crash',
                        'process' => $process,
                        'reaped' => true,
                    ]);
                    return;
                }
                continue;
            }

            while (true) {
                $err = $process->stderr->read();
                if (null === $err || false === $err) {
                    break;
                }
                $this->stderr->write($err, [
                    'type' => 'error',
                    'process' => $process,
                    ...$process->stderr->get_metadata(),
                ]);
            }
        }

        $data = $this->last_subprocess->stdout->read();
        if(null !== $data && false !== $data) {
            $this->stdout->write($data, $tick_context);
        }

        if($this->last_subprocess->stdout->is_eof()) {
            $this->kill(0);
        }
    }
}


class HttpClientProcess extends Process {
	private $client;
	private $requests = [];
	private $child_contexts = [];
	private $skipped_requests = [];
	private $errors = [];

    static public function stream($requests) {
        return fn () => new HttpClientProcess($requests);
    }

	private function __construct( $requests ) {
		$this->client = new Client();
		$this->client->enqueue( $requests );

        parent::__construct();        
	}

    protected function do_tick($tick_context)
    {
		if ( ! $this->client->await_next_event() ) {
            $this->kill(0);
			return false;
		}

		$request = $this->client->get_request();
        $output_channel = 'request_' . $request->id;
        if (!$this->stdout->has_channel($output_channel)) {
            $this->stdout->add_channel($output_channel);
        }
        $this->stdout->set_write_channel($output_channel);

		switch ( $this->client->get_event() ) {
			case Client::EVENT_BODY_CHUNK_AVAILABLE:
                $this->stdout->write($this->client->get_response_body_chunk(), [
                    'request' => $request
                ]);
				break;
            case Client::EVENT_FAILED:
                $this->stderr->write('Request failed: ' . $request->error, [
                    'request' => $request
                ]);
                $this->stdout->close_channel($output_channel);
				break;
			case Client::EVENT_FINISHED:
                $this->stdout->close_channel($output_channel);
                break;
		}
	}

}


class XMLProcess extends TransformProcess {
	private $xml_processor;
	private $node_visitor_callback;

    static public function stream($node_visitor_callback) {
        return fn () => new Demultiplexer(fn () =>
            new XMLProcess($node_visitor_callback)
        );
    }

	private function __construct( $node_visitor_callback ) {
		$this->xml_processor         = new WP_XML_Processor( '', [], WP_XML_Processor::IN_PROLOG_CONTEXT );
		$this->node_visitor_callback = $node_visitor_callback;
        parent::__construct();
	}

    protected function transform($data, $tick_context)
    {
		$processor = $this->xml_processor;
		if ( $processor->get_last_error() ) {
            $this->kill(1);
			$this->stderr->write( $processor->get_last_error() );
			return false;
		}

        $processor->stream_append_xml( $data );

		$tokens_found = 0;
		while ( $processor->next_token() ) {
			++ $tokens_found;
			$node_visitor_callback = $this->node_visitor_callback;
			$node_visitor_callback( $processor );
		}

        $buffer = '';
		if ( $tokens_found > 0 ) {
			$buffer .= $processor->get_updated_xml();
		} else if ( $tokens_found === 0 || ! $processor->paused_at_incomplete_token() ) {
			$buffer .= $processor->get_unprocessed_xml();
		}

        return $buffer;
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

$rewrite_links_in_wxr_node = function (WP_XML_Processor $processor) {
	if (is_wxr_content_node($processor)) {
		$text = $processor->get_modifiable_text();
		$updated_text = 'Hey there, what\'s up?';
		if ($updated_text !== $text) {
			$processor->set_modifiable_text($updated_text);
		}
	}
};

require __DIR__ . '/bootstrap.php';


$process = new ProcessChain([
    HttpClientProcess::stream([
        new Request('http://127.0.0.1:9864/export.wxr.zip'),
        // Bad request, will fail:
        new Request('http://127.0.0.1:9865'),
    ]),

    'zip' => ZipReaderProcess::stream(),
    CallbackProcess::stream(function ($data, $context, $process) {
        if ($context['zip']['file_id'] === 'content.xml') {
            $context['zip']->skip_file('content.xml');
            return null;
        }
        return $data;
    }),
    XMLProcess::stream($rewrite_links_in_wxr_node),
    Uppercaser::stream(),
]);
$process->stdout = new FilePipe('php://stdout', 'w');
$process->stderr = new FilePipe('php://stderr', 'w');
$process->run();

function log_process_chain_errors($process) {
    return;
    if(!($process->stderr instanceof BufferPipe)) {
        return;
    }
    
    $error = $process->stderr->read();
    if ($error) {
        echo 'Error: ' . $error . "\n";
        $meta = $process->stderr->get_metadata();
        if ($meta['type'] ?? '' === 'crash') {
            $child_error = $meta['process']->stderr->read();
            if ($child_error) {
                echo 'CRASH: ' . $meta['process']->stderr->read() . "\n";
            }
        }
    }    
}

// $process->tick([]);
// var_dump($process->stdout->read());

// var_dump($process->stdout->get_metadata());
// $process->tick([]);
// var_dump($process->stdout->get_metadata());
// var_dump($process->stderr->read());
// $process->tick([]);
// echo $process->stdout->read();
// var_dump($process->stdout->is_eof());
// var_dump($process->is_alive());