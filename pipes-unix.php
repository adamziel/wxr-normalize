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
 *   * Exposing metadata on a stream instance instead of a pipe.
 *     ^ With the new "execution stack" model, this seems like a great approach.
 *       $context['zip'] wouldn't be an abstract metadata array, but the actual ZipStreamReader instance
 *       with all the methods and properties available.
 *   * Not writing bytes to a pipe but writing a new Chunk($bytes, $metadata) object to tightly couple the two
 *     ^ the problem with this is that methods like `skip_file()` affect the currently processed file and we
 *       must call them at the right time
 * * Demultiplexing modes: per input channel, per $metadata['file_id'].
 * * Figure out interop Pipe and MultiChannelPipe – they are not interchangeable. Maybe
 *   we could use metadata to pass the channel name, and the regular pipe would ignore it?
 *   Maybe a MultiChannelPipe would just have special semantics for that metadata field?
 *   And it would keep track of eofs etc using a set of internal Pipe instances?
 *   ^ Now that each chunk is moved downstream as soon as it's produced, we don't need
 *     to keep multiple buffers around. The only remaining advantage of a MultiChannelPipe
 *     is tracking EOF for each channel separately.
 * * Calling get_metadata() without calling read() first returns the last metadata. This
 *   bit me a few times when I was in a context where I could not call read() first because,
 *   e.g. another process was about to do that. Maybe this is a good thing, as it forces us
 *   to split a pipe in two whenever an intermediate read is involved, e.g. Process A wouldn't
 *   just connect it's stdin to a subprocess A.1, but it would read from stdin, read metadata,
 *   do processing, ant only then write to A.1 stdin. Still, a better error reporting wouldn't hurt.
 * * Declare `bool` return type everywhere where it's missing. We may even remove it later for PHP BC,
 *   but let's still add it for a moment just to make sure we're not missing any typed return.
 * * ✅ Should Process::tick() return a boolean? Or is it fine if it doesn't return anything?
 *      It now returns either "true", which means "I've produced output", or "false", which means
 *      "I haven't produced output".
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
    }

    public function run()
    {
        do {
            $this->tick();
        } while ($this->is_alive());
    }

    public function tick($tick_context=null) {
        if(!$this->is_alive()) {
            return false;
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
            return false;
        }

        $data = $this->stdin->read();
        if (null === $data || false === $data) {
            return false;
        }

        $transformed = $this->transform($data, $tick_context);
        if (null === $transformed || false === $transformed) {
            return false;
        }

        $this->stdout->write($transformed, $this->stdin->get_metadata());
        return true;
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
    private $used = false;
    private array $channels = [];
    private ?string $last_read_channel = 'default';

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
            $this->last_read_channel = $channel_name;
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
        $this->used = true;
        $current_channel = 'default';

        if(is_array($metadata) && isset($metadata['channel'])) {
            $current_channel = $metadata['channel'];
        }

        if (!isset($this->channels[$current_channel])) {
            $this->channels[$current_channel] = new BufferPipe();
        }

        $this->metadata = $metadata;
        return $this->channels[$current_channel]->write($data, $metadata);
    }

    public function ensure_channel($channel_name)
    {
        if (isset($this->channels[$channel_name])) {
            return false;
        }
        $this->channels[$channel_name] = new BufferPipe();
    }

    public function is_channel_eof($channel_name)
    {
        if (!isset($this->channels[$channel_name])) {
            return false;
        }
        return $this->channels[$channel_name]->is_eof();
    }

    public function close_channel($channel_name)
    {
        if (!isset($this->channels[$channel_name])) {
            return false;
        }
        return $this->channels[$channel_name]->close();
    }

    public function get_channel_pipe($index)
    {
        return $this->channels[$index];
    }

    public function is_eof() {
        if(!$this->used) {
            return false;
        }
        foreach ($this->channels as $pipe) {
            if (!$pipe->is_eof()) {
                return false;
            }
        }
        return true;
    }

    public function close() {
        $this->used = true;
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
    private $demux_queue = [];
    private $last_subprocess;
    private $last_input_channel;
    
    public function __construct($process_factory) {
        $this->process_factory = $process_factory;
        parent::__construct();
    }

    protected function do_tick($tick_context) {
        if(true === $this->tick_last_subprocess()) {
            return true;
        }

        if($this->stdin->is_eof() || $this->stdout->is_eof()) {
            $this->kill(0);
            return false;
        }

        $next_chunk = $this->stdin->read();
        if (null === $next_chunk || false === $next_chunk) {
            return false;
        }

        $metadata = $this->stdin->get_metadata();
        $input_channel = is_array($metadata) && !empty( $metadata['channel'] ) ? $metadata['channel'] : 'default';
        $this->last_input_channel = $input_channel;
        if (!isset($this->subprocesses[$input_channel])) {
            $factory = $this->process_factory;
            $this->subprocesses[$input_channel] = $factory();
        }

        $subprocess = $this->subprocesses[$input_channel];
        $subprocess->stdin->write($next_chunk, $metadata);
        $this->last_subprocess = $subprocess;

        return $this->tick_last_subprocess();
    }

    private function tick_last_subprocess()
    {
        $subprocess = $this->last_subprocess;
        if(!$subprocess) {
            return false;
        }

        if(false === $subprocess->tick()) {
            return false;
        }
    
        $output = $subprocess->stdout->read();
        if (null !== $output && false !== $output) {
            $chunk_metadata = array_merge(
                ['channel' => $this->last_input_channel],
                $subprocess->stdout->get_metadata() ?? [],
            );
            $this->stdout->write($output, $chunk_metadata);
            if ($subprocess->stdout->is_channel_eof($chunk_metadata['channel'])) {
                $this->stdout->close_channel($chunk_metadata['channel']);
            }
            return true;
        }

        if (!$subprocess->is_alive()) {
            if ($subprocess->has_crashed()) {
                $this->stderr->write(
                    "Subprocess $this->last_input_channel has crashed with code {$subprocess->exit_code}",
                    [
                        'type' => 'crash',
                        'process' => $subprocess,
                    ]
                );
            }
        }

        return false;
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

    protected function __construct() {
        parent::__construct();
        $this->reader = new ZipStreamReader('');
    }

    public function skip_file($file_id)
    {
        $this->last_skipped_file = $file_id;
    }

    protected function do_tick($tick_context) {
        if(true === $this->process_buffered_data()) {
            return true;
        }

        if($this->stdin->is_eof()) {
            $this->kill(0);
            return false;
        }

        $bytes = $this->stdin->read();
        if (null === $bytes || false === $bytes) {
            return false;
        }

        $this->reader->append_bytes($bytes);
        return $this->process_buffered_data();
    }

    protected function process_buffered_data()
    {
        while ($this->reader->next()) {
            switch ($this->reader->get_state()) {
                case ZipStreamReader::STATE_FILE_ENTRY:
                    $file_path = $this->reader->get_file_path();
                    if ($this->last_skipped_file === $file_path) {
                        // break;
                    }
                    $this->stdout->write($this->reader->get_file_body_chunk(), [
                        'file_id' => $file_path,
                        // We don't want any single chunk to contain mixed bytes from
                        // multiple files.
                        // 
                        // Therefore, we must either:
                        // 
                        // * Use a separate channel for each file to have distinct
                        //   buckets that don't mix.
                        // * Use a single channel and ensure the unzipped file is fully
                        //   written and consumed before we start writing the next file.
                        // 
                        // The second option requires more implementation complexity and also
                        // requires checking whether the output pipe has been read completely
                        // which is very specific to a BufferPipe. The first option seems simpler
                        // so let's go with that.
                        'channel' => $file_path,
                    ]);
                    return true;
            }
        }

        return false;        
    }
}

class TickContext implements ArrayAccess {
    private $data;
    public $process;

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
        $this->data = $this->process->stdout->get_metadata();
        return $this->data;
    }

    public function skip_file($file_id)
    {
        return $this->process->skip_file($file_id);        
    }

}

class ProcessChain extends Process {
    private $first_subprocess;
    private $last_subprocess;
    public $subprocesses = [];
    public $subprocesses_names = [];
    private $reaped_pids = [];
    private $execution_stack = [];
    private $tick_context = [];

    public function __construct($process_factories) {
        parent::__construct();

        $last_process = null;
        $this->subprocesses_names = array_keys($process_factories);
        foreach($this->subprocesses_names as $k => $name) {
            $this->subprocesses_names[$k] = $name . '';
        }

        $processes = array_values($process_factories);
        for($i = 0; $i < count($process_factories); $i++) {
            $factory = $processes[$i];
            $subprocess = $factory();
            if(null !== $last_process) {
                $subprocess->stdin = $last_process->stdout;
            }
            $this->subprocesses[$this->subprocesses_names[$i]] = $subprocess;
            $last_process = $subprocess;
        }

        $this->first_subprocess = $this->subprocesses[$this->subprocesses_names[0]];
        $this->last_subprocess = $this->subprocesses[$this->subprocesses_names[count($process_factories) - 1]];
    }

    /**
     * ## Process chain tick
     * 
     * Pushes data through a chain of subprocesses. Every downstream data chunk
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
    protected function do_tick($tick_context) {
        if($this->last_subprocess->stdout->is_eof()) {
            $this->kill(0);
            return false;
        }

        while(true) {
            $data = $this->stdin->read();
            if (null === $data || false === $data) {
                break;
            }
            $this->first_subprocess->stdin->write($data, $this->stdin->get_metadata());
        }

        if($this->stdin->is_eof()) {
            $this->first_subprocess->stdin->close();
        }

        if(empty($this->execution_stack)) {
            array_push($this->execution_stack, $this->first_subprocess);
        }

        while (count($this->execution_stack)) {
            // Unpeel the context stack until we find a process that
            // produces output.
            $process = $this->pop_process();
            if ($process->stdout->is_eof()) {
                continue;
            }

            if(true !== $this->tick_subprocess($process)) {
                continue;
            }

            // We've got output from the process, yay! Let's
            // propagate it downstream.
            $this->push_process($process);

            for ($i = count($this->execution_stack); $i < count($this->subprocesses_names); $i++) {
                $next_process = $this->subprocesses[$this->subprocesses_names[$i]];
                if (true !== $this->tick_subprocess($next_process)) {
                    break;
                }
                $this->push_process($next_process);
            }

            // When the last process in the chain produces output,
            // we write it to the stdout pipe and bale.
            $data = $this->last_subprocess->stdout->read();
            if (null === $data || false === $data) {
                break;
            }

            $this->stdout->write($data, $this->tick_context);
            return true;
        }

        // We produced no output and the upstream pipe is EOF.
        // We're done.
        if(!$this->first_subprocess->is_alive()) {
            $this->kill(0);
        }

        return false;
    }

    private function pop_process()
    {
        $name = $this->subprocesses_names[count($this->execution_stack) - 1];
        unset($this->tick_context[$name]);
        return array_pop($this->execution_stack);        
    }

    private function push_process($process)
    {
        array_push($this->execution_stack, $process);
        $name = $this->subprocesses_names[count($this->execution_stack) - 1];
        $this->tick_context[$name] = new TickContext($process);        
    }

    private function tick_subprocess($process)
    {
        $produced_output = $process->tick($this->tick_context);
        $this->handle_errors($process);
        return $produced_output;        
    }

    private function handle_errors($process)
    {
        if(!$process->has_crashed()) {
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

        if($process->has_crashed()) {
            if (!$process->is_reaped()) {
                $process->reap();
                $name = $this->subprocesses_names[array_search($process, $this->subprocesses)];
                $this->stderr->write("Process $name has crashed with code {$process->exit_code}", [
                    'type' => 'crash',
                    'process' => $process,
                    'reaped' => true,
                ]);
            }
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

        // Pre-open all output channels to ensure the stdout stream
        // stays open until all the requests conclude. Otherwise,
        // we could have a window of time when some requests are done,
        // others haven't started outputting yet, and the stdout stream
        // is considered EOF.
        foreach($requests as $request) {
            $this->stdout->ensure_channel('request_' . $request->id);
        }
	}

    protected function do_tick($tick_context)
    {
        while($this->client->await_next_event()) {
            $request = $this->client->get_request();
            $output_channel = 'request_' . $request->id;
            switch ($this->client->get_event()) {
                case Client::EVENT_BODY_CHUNK_AVAILABLE:
                    $this->stdout->write($this->client->get_response_body_chunk(), [
                        'channel' => $output_channel,
                        'request' => $request
                    ]);
                    return true;

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

        $this->kill(0);
        return false;
	}

}


class XMLProcess extends Process {
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

    protected function do_tick($tick_context) {
        if(true === $this->process_buffered_data()) {
            return true;
        }

        if($this->stdin->is_eof()) {
            $this->kill(0);
            return false;
        }

        $bytes = $this->stdin->read();
        if (null === $bytes || false === $bytes) {
            return false;
        }

        $this->xml_processor->stream_append_xml($bytes);
        return $this->process_buffered_data();
    }

    private function process_buffered_data()
    {
        if($this->xml_processor->paused_at_incomplete_token()) {
            return false;
        }

		if ( $this->xml_processor->get_last_error() ) {
            $this->kill(1);
			$this->stderr->write( $this->xml_processor->get_last_error() );
			return false;
		}

        $tokens_found = 0;
		while ( $this->xml_processor->next_token() ) {
			++ $tokens_found;
			$node_visitor_callback = $this->node_visitor_callback;
			$node_visitor_callback( $this->xml_processor );
		}

        $buffer = '';
		if ( $tokens_found > 0 ) {
			$buffer .= $this->xml_processor->get_updated_xml();
		} else if ( 
            $tokens_found === 0 && 
            ! $this->xml_processor->paused_at_incomplete_token() &&
            $this->xml_processor->get_current_depth() === 0
        ) {
            // We've reached the end of the document, let's finish up.
			$buffer .= $this->xml_processor->get_unprocessed_xml();
            $this->kill(0);
		}

        if(!strlen($buffer)) {
            return false;
        }

        $this->stdout->write($buffer);

        return true;
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
        if ($context['zip']['file_id'] === 'export.wxr') {
            $context['zip']->skip_file('export.wxr');
            return null;
        }
        return $data;
    }),
    'xml' => XMLProcess::stream($rewrite_links_in_wxr_node),
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
