<?php

use WordPress\AsyncHttp\Client;
use WordPress\AsyncHttp\Request;

class ProcessManager {

    static private $last_pid = 1;
    static private $process_table = [];
    static private $reaped_pids = [];

    static public function spawn($factory_or_process, $stdin=null, $stdout=null, $stderr=null) {
        $process = $factory_or_process instanceof Process ? $factory_or_process : $factory_or_process();
        $process->stdin = $stdin ?? new MultiChannelPipe();
        $process->stdout = $stdout ?? new MultiChannelPipe();
        $process->stderr = $stderr ?? new MultiChannelPipe();
        $process->pid = self::$last_pid++;
        $process->init();
        self::$process_table[$process->pid] = $process;
        return $process;
    }

    static public function kill($pid, $code) {
        self::$process_table[$pid]->kill($code);
    }

    static public function reap($pid) {
        self::$reaped_pids[] = $pid;
        self::$process_table[$pid]->cleanup();
        unset(self::$process_table[$pid]);
    }

    static public function is_reaped($pid) {
        return in_array($pid, self::$reaped_pids);
    }

}

abstract class Process {
    public ?int $exit_code = null;
    public Pipe $stdin;
    public Pipe $stdout;
    public Pipe $stderr;
    public $pid;

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

    public function has_crashed() {
        return $this->exit_code !== null && $this->exit_code !== 0;
    }

    public function is_alive() {
        return $this->exit_code === null;
    }

    public function init() {
    }

    public function cleanup() {
        // clean up resources
    }

    public function skip_file($file_id) {
        // Needs to be implemented by subclasses
        return false;
    }

    protected function set_write_channel(string $name)
    {
        $this->stderr->set_channel_for_write($name);
        $this->stdout->set_channel_for_write($name);
    }

    protected function ensure_output_channel(string $name)
    {
        if(!$this->stderr->has_channel($name)) {
            $this->stderr->add_channel($name);
        }
        if(!$this->stdout->has_channel($name)) {
            $this->stdout->add_channel($name);
        }
    }

    protected function add_output_channel(string $name)
    {
        $this->stderr->add_channel($name);
        $this->stdout->add_channel($name);
    }

    protected function close_output_channel(string $name)
    {
        $this->stderr->close_channel($name);
        $this->stdout->close_channel($name);
    }
}

abstract class TransformProcess extends Process {
    protected function do_tick($tick_context) {
        if($this->stdin->is_eof()) {
            $this->kill(0);
            return;
        }

        $data = $this->stdin->read();
        if (null === $data || false === $data) {
            return;
        }
        $transformed = $this->transform($data, $tick_context);
        if (null === $transformed || false === $transformed) {
            return;
        }
        $this->ensure_output_channel($this->stdin->get_current_channel());
        $this->set_write_channel($this->stdin->get_current_channel());
        $this->stdout->write($transformed, $this->stdin->get_metadata());
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
        $this->channels[$name] = $pipe ?? new BufferPipe();
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
    }

    public function close_channel($channel_name)
    {
        $this->channels[$channel_name]->close();
        $this->current_channel = null;
    }

    public function set_channel_for_write($name)
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

/**
 * Idea 2: Use multiple child processes for
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
class FakeHttpClient extends Process
{
    protected const SIDE_EFFECTS = true;
    
    public function init()
    {
        $this->close_output_channel('default');        
    }

    protected function do_tick($tick_context)
    {
        static $tick_nb = 0;
        if (++$tick_nb === 1) {
            $this->add_output_channel('stream_1');
            $this->set_write_channel('stream_1');
            $this->stdout->write("stream-1-chunk-1", [
                'file_id' => 1,
            ]);

            $this->add_output_channel('stream_2');
            $this->set_write_channel('stream_2');
            $this->stdout->write("stream-2-chunk-1!", [
                'file_id' => 2,
            ]);
        } else if (++$tick_nb === 2) {
            $this->set_write_channel('stream_3');
            $this->stdout->write("stream-3-chunk-1!");
        } else {
            $this->set_write_channel('stream_1');
            $this->stdout->write("stream-1-chunk-2", [
                'file_id' => 1,
            ]);
            $this->stdout->write("stream-1-chunk-3", [
                'file_id' => 3,
            ]);

            $this->add_output_channel('stream_3');
            $this->set_write_channel('stream_3');
            $this->stdout->write("stream-3-chunk-2!", [
                'file_id' => 2,
            ]);

            $this->kill(0);
        }
    }
}


class HelloWorld extends Process {
    protected function do_tick($tick_context) {
        $this->stdout->write("Hello, world!", [
            'file_id' => 1,
        ]);
        $this->stderr->write("Critical error has occured :(");
        $this->kill(1);
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
    }

    protected function do_tick($tick_context) {
        if($this->stdin->is_eof()) {
            $this->kill(0);
            return;
        }

        $next_chunk = $this->stdin->read();
        if(null === $next_chunk || false === $next_chunk) {
            return;
        }

        $input_channel = $this->stdin->get_current_channel();
        if(!isset($this->subprocesses[$input_channel])) {
            $this->add_output_channel($input_channel);
            $this->subprocesses[$input_channel] = ProcessManager::spawn(
                $this->process_factory
            );
        }

        $subprocess = $this->subprocesses[$input_channel];
        $subprocess->stdin->write( $next_chunk, $this->stdin->get_metadata() );
        $subprocess->tick($tick_context);
        $this->last_subprocess = $subprocess;

        $output = $subprocess->stdout->read();
        if(null !== $output && false !== $output) {
            $this->set_write_channel($input_channel);
            $this->stdout->write($output, $subprocess->stdout->get_metadata());
        }

        if (!$subprocess->is_alive()) {
            if($subprocess->has_crashed()) {
                $this->stderr->write("Subprocess $input_channel has crashed with code {$subprocess->exit_code}", [
                    'type' => 'crash',
                    'process' => $subprocess,
                ]);
            }
            $this->close_output_channel($input_channel);
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

    public function init() {
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

        $bytes = $this->stdin->read();
        if(null === $bytes || false === $bytes) {
            return;
        }

        $this->reader->append_bytes($bytes);
        while ($this->reader->next()) {
            switch($this->reader->get_state()) {
                case ZipStreamReader::STATE_FILE_ENTRY:
                    $file_path = $this->reader->get_file_path();
                    if($this->last_skipped_file === $file_path) {
                        break;
                    }
                    $this->ensure_output_channel($file_path);
                    $this->set_write_channel($file_path);
                    $this->stdout->write($this->reader->get_file_body_chunk(), [
                        'file_id' => $file_path
                    ]);
                    break;
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
    }

    public function init() {
        $last_process = null;
        $names = array_keys($this->process_factories);
        foreach($names as $k => $name) {
            $names[$k] = $name . '';
        }

        $processes = array_values($this->process_factories);
        for($i = 0; $i < count($this->process_factories); $i++) {
            $factory = $processes[$i];
            $subprocess = ProcessManager::spawn(
                $factory, 
                null !== $last_process ?$last_process->stdout : null,
                null,
                $this->stderr
            );
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
                if (!ProcessManager::is_reaped($process->pid)) {
                    ProcessManager::reap($process->pid);
                    $this->stderr->write("Process $name has crashed with code {$process->exit_code}", [
                        'type' => 'crash',
                        'process' => $process,
                        'reaped' => true,
                    ]);
                    return;
                }
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
	}

    protected function do_tick($tick_context)
    {
		if ( ! $this->client->await_next_event() ) {
            var_dump('nope');
            $this->kill(0);
			return false;
		}

		$request = $this->client->get_request();
        $output_channel = 'request_' . $request->id;
        $this->ensure_output_channel($output_channel);

        var_dump($this->client->get_event());

		switch ( $this->client->get_event() ) {
			case Client::EVENT_BODY_CHUNK_AVAILABLE:
				$this->set_write_channel($output_channel);
                $this->stdout->write($this->client->get_response_body_chunk(), [
                    'request' => $request
                ]);
				break;
			case Client::EVENT_FAILED:
                $this->stderr->write('Request failed: ' . $request->error, [
                    'request' => $request
                ]);
                $this->close_output_channel($output_channel);
				break;
			case Client::EVENT_FINISHED:
                $this->close_output_channel($output_channel);
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


$process = ProcessManager::spawn(
    new ProcessChain([
        HttpClientProcess::stream([
            new Request('http://127.0.0.1:9864/export.wxr.zip'),
        ]),

        'zip' => ZipReaderProcess::stream(),
        CallbackProcess::stream(function ($data, $context, $process) {
            if($context['zip']['file_id'] === 'content.xml') {
                $context['zip']->skip_file('content.xml');
                return null;
            }
            return $data;
        }),
        XMLProcess::stream($rewrite_links_in_wxr_node),
        Uppercaser::stream(),
    ])
);
$process->stdout = new FilePipe('php://stdout', 'w');
$process->run();

function log_process_chain_errors($process) {
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