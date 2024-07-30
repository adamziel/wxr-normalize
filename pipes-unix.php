<?php

/**
 * @TODO:
 *
 * * ✅ Consider an interface for all streamable processor classes to make them chainable.
 *     ^ Here's some reasons not to do that:
 *       
 *       1. The same processor may need multiple stream implementations. For example,
 *          an XML processor may be used to transform the document, so go from XML bytes to XML bytes,
 *          but it can also be used to extract HTML from CDATA, so go from XML bytes to HTML bytes.
 *       2. Not all processor must output bytes, and for some processors, the metadata may be much more
 *          important than the actual output bytes.
 *       3. Exposing a method like Processor::chain($processor_2) means we can call it multiple times,
 *          which means we either need to handle forking the stream or track the chaining state and 
 *          handle errors in all ambiguous cases.
 *       4. A method like Processor::next() would be ambiguous:
 *          * For HTML and XML processors it could mean "next token", "next tag", or "next bytes chunk"
 *          * For a ZIP processor it could mean "next file", "next ZIP entry", or "next file chunk"
 *          * ...etc...
 *          A set of processor-specific methods, such as next_token(), next_tag(), next_file() etc. seems
 *          like a more intuitive choice.
 * 
 *       However, it would still be useful to have *some* common interface there. Perhaps this could work:
 * 
 *       interface StreamProcessor {
 *          public function append_bytes($bytes): bool;
 *          public function is_finished(): bool;
 *          public function is_paused_at_incomplete_input(): bool;
 *       }
 * 
 *       I am on the fence about adding the following method:
 * 
 *       interface StreamProcessor {
 *          public function get_last_error(): ?string;
 *       }
 * 
 *       Keeping Processors separate from Stream implementations seems useful. This way we don't have to
 *       worry about stdin/stdout/stderr etc. and can focus on actual processing. The stream will figure
 *       out how to use the processor semantics to transform byte chunks. 
 * * ✅ Find a naming scheme that doesn't suggest we're working with actual Unix processes and pipes.
 *   I only used it to make the development easier, I got confused with the other attempt in
 *   `pipes.php` and this kept me on track. However, keeping these names will likely confuse others.
 * * ✅ Explore merging Pipes and Processes into a single concept after all.
 *      Not doing that is nice, too. Writing to output is not equivalent to
 *      starting more computation downstream. Reading from input is not equivalent
 *      to trigerring more computations upstream. We get a buffer, a demilitarized
 *      zone between processes. Perhaps that's what was missing from the other experiment.
 * * ✅ Make ProcessChain implement the Iterator interface. Iterator semantics doesn't make
 *      as much sense on regular process classes because they may run out of input and they
 *      can't pull more bytes from the top of the stream.
 * * ✅ Explore changes updates to metadata:
 *   * Exposing metadata on a stream instance instead of a pipe.
 *     ^ With the new "execution stack" model, this seems like a great approach.
 *       $context['zip'] wouldn't be an abstract metadata array, but the actual ZipStreamReader instance
 *       with all the methods and properties available.
 *     ^ Problem is, we want the next stream to have access to the metadata.
 *     ^ Well, writing metadata to output is the same as coupling it with the stream instance.
 *       Also, since we'll need to access the metadata later, even after it's been written to the next
 *       stream, we may need to keep the Pipe class around.
 *   * Not writing bytes to a pipe but writing a new Chunk($bytes, $metadata) object to tightly couple the two
 *     ^ the problem with this is that methods like `skip_file()` affect the currently processed file and we
 *       must call them at the right time
 * * ✅ Demultiplexing modes: per "sequence_id" (e.g. ZIPping a sequence of files), per "file_id" 
 *      (e.g. XML rewriting each file separately, regardless of the chunks order)
 *      ^ $key constructor argument handles that now
 * * ✅ Figure out interop Pipe and MultiplexedPipe – they are not interchangeable. Maybe
 *     we could use metadata to pass the sequence name, and the regular pipe would ignore it?
 *     Maybe a MultiplexedPipe would just have special semantics for that metadata field?
 *     And it would keep track of eofs etc using a set of internal Pipe instances?
 *     ^ Now that each chunk is moved downstream as soon as it's produced, we don't need
 *       to keep multiple buffers around. The only remaining advantage of a MultiplexedPipe
 *       is tracking EOF for each sequence separately.
 *     ^ Do we need separate "pipes" at all?
 *       * The process chain semantics assumes every output chunk will be fully processed
 *         before the next one is produced.
 *       * Writing to a pipe before consuming its contents is an undefiend behavior similarly
 *         as with processes.
 *       * "pipes" are buffers and "processes" are buffers.
 *       * I can close a single sequence in a pipe without closing the entire pipe or the next
 *         process.
 *       * A separate Pipe class encapsulates the writing and consumption logic. It wouldn't be
 *         handy to force that on every process.
 *       * But still, could we have a ProcessPipe class? And a PipeProcess class?
 *       * Every Process needs a way to receive more data, emit its data, and emit errors.
 *         Currently we assume a tick() call that does $input->read(). We could have a public
 *         Process::write() method 
 *    ^ MultiplexedPipe isn't used anymore
 * * ✅ The process `do_tick` method typically checks for `input->is_eof()` and then
 *      whether `input->read()` is valid. Can we simplify this boilerplate somehow?
 *      ^ the BufferProcessor interface solves that problem.
 * * ✅ Explore a shared "Streamable" interface for all stream processors (HTML, XML, ZIP, HTTP, etc.)
 *      ^ Would the "Process" have the same interface? A `tick()` seems isomorphic to
 *        "append_bytes()" call followed by "next()". There's a semantic difference in that
 *        "append_bytes()" pushes the data, while "tick()" pulls the data, but perhaps the push model
 *        would work better for asynchronous piping.
 *      ^^ A single interface for everything doesn't seem to cut it, but the BufferProcessor interface
 *         with `read()` and `write($bytes, $metadata)` methods seems to be a good fit for XML, ZIP, HTTP.
 *         It resembles a Pipe interface, too. I wonder if these "Process" classes could be pipes themselves.
 * * ✅ Get rid of ProcessManager
 * * ✅ Get rid of errors. We don't need it to be a stream. A single $error field + bubbling should do.
 *      Let's keep errors after all.
 * * ✅ Remove these methods: set_write_sequence, ensure_output_sequence, add_output_sequence, close_output_sequence
 * * ✅ Declare `bool` return type everywhere where it's missing. We may even remove it later for PHP BC,
 *      but let's still add it for a moment just to make sure we're not missing any typed return.
 * * ✅ Should Process::tick() return a boolean? Or is it fine if it doesn't return anything?
 *      It now returns either "true", which means "I've produced output", or "false", which means
 *      "I haven't produced output".
 * * ✅ Pipe::read() returns a string on success, false on failure, or null if there were no writes
 *      since the last read and we'd just return an empty string. This three-state semantics is useful,
 *      but it's painful to always check for false and null, and then it may not interop well with
 *      PHP streams where fread() never returns null. Let's think this through some more.
 *      ^ Pipe::read() now returns true, false, or null. When it returns true, the data is available
 *        for being consumed via $pipe->consume_bytes().
 * 
 * Maybe not do these?
 * 
 * * Calling get_metadata() without calling read() first returns the last metadata. This
 *   bit me a few times when I was in a context where I could not call read() first because,
 *   e.g. another process was about to do that. Maybe this is a good thing, as it forces us
 *   to split a pipe in two whenever an intermediate read is involved, e.g. Process A wouldn't
 *   just connect it's input to a subprocess A.1, but it would read from input, read metadata,
 *   do processing, ant only then write to A.1 input. Still, a better error reporting wouldn't hurt.
 */

/**
 * ## Demultiplexing modes: per input sequence, per $metadata['file_id'].
 * 
 * We want to keep track of:
 * * Sequence ID – the sequential byte stream identifier. Multiple streams will produce
 *                 file chunks in an arbitrary order and, when multiplexed, the chunks will be
 *                 interleaved.
 * * File ID     – the file within that stream. A single stream may contain multiple files,
 *                 but they will always be written sequentially. When multiplexed, one file will
 *                 always be written completely before the next one is started.
 * 
 * When a specific stream errors out, we need to communicate this
 * downstream and so the consumer processes can handle the error.
 * 
 * Therefore, we need a separate pipe for each stream ID. Do we also
 * need a separate process? Not necessarily. Each process only cares
 * about the open-ness or EOF-ness of its input and output pipes,
 * not about the actual lifecycle of the other processes.
 * 
 * However, we may want to correlate the same stream ID with output and
 * errors streams, in which case intertwining stream ID and process ID
 * would be useful. But then we don't have a 1:1 mapping between
 * what a data stream does and what a process does.
 * 
 * Let's try these two approach and see where we get with it:
 * 
 * 1. Each process has a multiplexed input, output, and errors pipes.
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
 * ## Get rid of errors. We don't need it to be a stream. A single $error field + bubbling should do.
 * 
 * Maybe errors is fine after all? I'm no longer convinced about inventing a separate mechanism
 * for error propagation. We'd have to implement a lot of the same features that errors already
 * have.
 * 
 * Advantages of using errors for propagating errors:
 * 
 * * We can bubble up multiple errors from a single process.
 * * They have metadata attached and are traceable to a specific process.
 * * Piping to errors doesn't imply the entire process have crashed, which we
 *   wouldn't want in case of, say, Demultiplexer.
 * * We clearly know when the errors are done, as errors is a stream and we know
 *   when it's EOF.
 * * We can put any pipe in place of errors, e.g. a generic logger pipe
 * 
 * Disadvantages:
 * 
 * * Pipes have more features than error propagation uses, e.g. we rarely care
 *   for is_eof() on errors, but we still have to close that errors pipe.
 */

 require __DIR__ . '/bootstrap.php';

use WordPress\AsyncHttp\Client;
use WordPress\AsyncHttp\Request;

abstract class Stream implements ArrayAccess {

    const STATE_STREAMING = '#streaming';
    const STATE_FINISHED = '#finished';
    const STATE_CRASHED = '#crashed';

    private string $state = self::STATE_STREAMING;

    protected Pipe $input;
    protected Pipe $output;
    protected Pipe $errors;

    public function __construct($input=null, $output=null, $errors=null)
    {
        $this->input = $input ?? new BufferPipe();
        $this->output = $output ?? new BufferPipe();
        $this->errors = $errors ?? new BufferPipe();
    }

    public function tick($tick_context=null): bool {
        if(!$this->is_alive()) {
            return false;
        }

        return $this->do_tick($tick_context ?? []);
    }

    abstract protected function do_tick($tick_context): bool;

    protected function crash( $error_message = null )
    {
        if($error_message) {
            $this->errors->write( $error_message );
        }
        $this->state = self::STATE_CRASHED;
        $this->cleanup();
    }

    protected function finish() {
        $this->state = self::STATE_FINISHED;
        $this->cleanup();
    }

    protected function cleanup()
    {
        $this->input->close();
        $this->output->close();
        $this->errors->close();
    }

    public function has_crashed(): bool {
        return $this->state === self::STATE_CRASHED;
    }

    public function is_alive(): bool {
        return $this->state === self::STATE_STREAMING;
    }

    public function offsetExists($offset): bool {
        return isset($this->output->get_metadata()[$offset]);
    }

    public function offsetGet($offset): mixed {
        return $this->output->get_metadata()[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void {
        // No op
    }

    public function offsetUnset($offset): void {
        // No op
    }

}

abstract class BufferStream extends Stream
{
    protected function do_tick($tick_context): bool
    {
        if(true === $this->read()) {
            return true;
        }

        if (!$this->input->read()) {
            if ($this->input->is_eof()) {
                $this->finish();
            }
            return false;
        }

        $this->write(
            $this->input->consume_bytes(),
            $this->input->get_metadata(),
            $tick_context
        );

        return $this->read();
    }

    abstract protected function write($input_chunk, $metadata, $tick_context);
    abstract protected function read(): bool;
}


abstract class ProcessorStream extends Stream
{

    protected IStreamProcessor $processor;

    public function __construct($input = null, $output = null, $errors = null)
    {
        parent::__construct($input, $output, $errors);
        $this->processor = $this->create_processor();
    }

    public function get_processor()
    {
        return $this->processor;        
    }

    abstract protected function create_processor(): IStreamProcessor;

    protected function do_tick($tick_context): bool
    {
        if(true === $this->next()) {
            return true;
        }

        if (!$this->input->read()) {
            if ($this->input->is_eof()) {
                $this->finish();
            }
            return false;
        }

        $this->processor->append_bytes($this->input->consume_bytes());
        
        if($this->processor->is_paused_at_incomplete_input()) {
            return false;
        }

        if ($this->processor->get_last_error()) {
            $this->crash($this->processor->get_last_error());
            return false;
        }

        return $this->next();
    }

    abstract protected function next(): bool;

}

abstract class TransformerStream extends BufferStream {

    protected $buffer;
    protected $metadata;
    protected $tick_context;

    protected function write($input_chunk, $metadata, $tick_context)
    {
        $this->buffer .= $input_chunk;
        $this->metadata = $metadata;
        $this->tick_context = $tick_context;
    }

    protected function read(): bool
    {
        if(null === $this->buffer) {
            return false;
        }
        $transformed = $this->transform($this->buffer, $this->tick_context);
        $this->buffer = null;
        if (null === $transformed || false === $transformed) {
            return false;
        }

        $this->output->write($transformed, $this->metadata);
        return true;
    }

    abstract protected function transform($data, $tick_context);

}

interface Pipe {
    public function read(): ?bool;
    public function write(string $data, $metadata=null): bool;
    public function is_eof(): bool;
    public function close();
    public function consume_bytes();
    public function get_metadata();
}

class BufferPipe implements Pipe {
    private ?string $buffer = null;
    private $metadata = null;
    private bool $closed = false;

    public function __construct($buffer = null)
    {
        $this->buffer = $buffer;        
    }

    public function read(): ?bool {
        if(!$this->buffer && $this->closed) {
            return false;
        }
        if(null === $this->buffer) {
            return null;
        }
        return true;
    }

    public function consume_bytes()
    {
        $bytes = $this->buffer;
        $this->buffer = null;
        return $bytes;
    }

    public function get_metadata() {
        return $this->metadata;        
    }

    public function write(string $data, $metadata=null): bool {
        if($this->closed) {
            return false;
        }
        if(null === $this->buffer) {
            $this->buffer = '';
        }
        $this->buffer .= $data;
        $this->metadata = $metadata;
        return true;
    }

    public function is_eof(): bool {
        return null === $this->buffer && $this->closed;        
    }

    public function close() {
        $this->closed = true;
    }
}

class ResourcePipe implements Pipe {
    private $resource;
    private bool $closed = false;
    private $bytes;

    public function __construct($resource) {
        $this->resource = $resource;
    }

    public function read(): ?bool {
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

        if($this->bytes === null) {
            $this->bytes = '';
        }
        $this->bytes .= $data;
        return true;
    }

    public function consume_bytes()
    {
        $bytes = $this->bytes;
        $this->bytes = null;
        return $bytes;
    }

    public function write(string $data, $metadata=null): bool {
        if($this->closed) {
            return false;
        }
        fwrite($this->resource, $data);
        return true;
    }

    public function get_metadata() {
        return null;
    }

    public function is_eof(): bool {
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

class CallbackStream extends TransformerStream {
    private $callback;
    
    static public function create($callback) {
        return new static($callback);
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

class Demultiplexer extends BufferStream {
    private $stream_create = [];
    private $streams = [];
    private $killed_streams = [];
    private $demux_queue = [];
    private $last_stream;
    private $last_input_key;
    private $key;
    
    public function __construct($stream_create, $key = 'sequence') {
        $this->stream_create = $stream_create;
        $this->key = $key;
        parent::__construct();
    }

    public function get_substream()
    {
        return $this->last_stream;
    }

    protected function write($next_chunk, $metadata, $tick_context) {
        $chunk_key = is_array($metadata) && !empty( $metadata[$this->key] ) ? $metadata[$this->key] : 'default';
        $this->last_input_key = $chunk_key;
        if (!isset($this->streams[$chunk_key])) {
            $create = $this->stream_create;
            $this->streams[$chunk_key] = $create();
        }

        $stream = $this->streams[$chunk_key];
        $stream->input->write($next_chunk, $metadata);
        $this->last_stream = $stream;
    }

    protected function read(): bool
    {
        $stream = $this->last_stream;
        if(!$stream) {
            return false;
        }

        if(!$stream->tick()) {
            return false;
        }
    
        if ($stream->output->read()) {
            $output = $stream->output->consume_bytes();
            $chunk_metadata = array_merge(
                [$this->key => $this->last_input_key],
                $stream->output->get_metadata() ?? [],
            );
            $this->output->write($output, $chunk_metadata);
            return true;
        }

        if (!$stream->is_alive()) {
            if ($stream->has_crashed()) {
                $this->errors->write(
                    "Subprocess $this->last_input_key has crashed",
                    [
                        'type' => 'crash',
                        'stream' => $stream,
                    ]
                );
            }
        }

        return false;
    }

    public function skip_file($file_id)
    {
        if(!$this->last_stream) {
            return false;
        }
        return $this->last_stream->skip_file($file_id);
    }
}

require __DIR__ . '/zip-stream-reader.php';

class ZipReaderStream extends ProcessorStream {

    /**
     * @var ZipStreamReader
     */
	protected IStreamProcessor $processor;
    private $last_skipped_file = null;

    static public function create() {
        return new Demultiplexer(fn() => new ZipReaderStream());
    }

    protected function create_processor(): IStreamProcessor
    {
        return new ZipStreamReader('');
    }

    public function skip_file($file_id)
    {
        $this->last_skipped_file = $file_id;
    }

    protected function next(): bool
    {
        while ($this->processor->next()) {
            switch ($this->processor->get_state()) {
                case ZipStreamReader::STATE_FILE_ENTRY:
                    $file_path = $this->processor->get_file_path();
                    if ($this->last_skipped_file === $file_path) {
                        break;
                    }
                    $this->output->write($this->processor->get_file_body_chunk(), [
                        'file_id' => $file_path,
                        // Use a separate sequence for each file so the next
                        // process may separate the files.
                        'sequence' => $file_path,
                    ]);
                    return true;
            }
        }

        return false;        
    }
}

class StreamChain extends Stream implements Iterator {
    private $first_stream;
    private $last_stream;
    private $streams = [];
    private $streams_names = [];
    private $finished_streams = [];
    private $execution_stack = [];
    private $tick_context = [];

    public function __construct($streams, $input=null, $output=null, $errors=null) {
        parent::__construct($input, $output, $errors);

        $last_stream = null;
        $this->streams_names = array_keys($streams);
        foreach($this->streams_names as $k => $name) {
            $this->streams_names[$k] = $name . '';
        }

        $streams = array_values($streams);
        for($i = 0; $i < count($streams); $i++) {
            $stream = $streams[$i];
            if(null !== $last_stream) {
                $stream->input = $last_stream->output;
            }
            $this->streams[$this->streams_names[$i]] = $stream;
            $last_stream = $stream;
        }

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
    protected function do_tick($tick_context): bool {
        if($this->last_stream->output->is_eof()) {
            $this->finish();
            return false;
        }

        while(true) {
            if(true !== $this->input->read()) {
                break;
            }
            $this->first_stream->input->write(
                $this->input->consume_bytes(),
                $this->input->get_metadata()
            );
        }

        if($this->input->is_eof()) {
            $this->first_stream->input->close();
        }

        if(empty($this->execution_stack)) {
            array_push($this->execution_stack, $this->first_stream);
        }

        while (count($this->execution_stack)) {
            // Unpeel the context stack until we find a stream that
            // produces output.
            $stream = $this->pop_stream();
            if ($stream->output->is_eof()) {
                continue;
            }

            if(true !== $this->stream_next($stream)) {
                continue;
            }

            // We've got output from the stream, yay! Let's
            // propagate it downstream.
            $this->push_stream($stream);

            for ($i = count($this->execution_stack); $i < count($this->streams_names); $i++) {
                $next_stream = $this->streams[$this->streams_names[$i]];
                if (true !== $this->stream_next($next_stream)) {
                    break;
                }
                $this->push_stream($next_stream);
            }

            // When the last process in the chain produces output,
            // we write it to the output pipe and bale.
            if(true !== $this->last_stream->output->read()) {
                break;
            }
            $this->output->write(
                $this->last_stream->output->consume_bytes(),
                $this->tick_context
            );
            ++$this->chunk_nb;
            return true;
        }

        // We produced no output and the upstream pipe is EOF.
        // We're done.
        if(!$this->first_stream->is_alive()) {
            $this->finish();
        }

        return false;
    }

    private function pop_stream()
    {
        $name = $this->streams_names[count($this->execution_stack) - 1];
        unset($this->tick_context[$name]);
        return array_pop($this->execution_stack);        
    }

    private function push_stream($stream)
    {
        array_push($this->execution_stack, $stream);
        $name = $this->streams_names[count($this->execution_stack) - 1];
        if($stream instanceof Demultiplexer) {
            $stream = $stream->get_substream();
        }
        $this->tick_context[$name] = $stream;
    }

    private function stream_next($stream)
    {
        $produced_output = $stream->tick($this->tick_context);
        $this->handle_errors($stream);
        return $produced_output;
    }

    private function handle_errors($stream)
    {
        if($stream->has_crashed()) {
            $name = $this->streams_names[array_search($stream, $this->streams)];
            if(!isset($this->finished_streams[$name])) {
                $this->errors->write("Process $name has crashed", [
                    'type' => 'crash',
                    'stream' => $stream,
                ]);
                $this->finished_streams[$name] = true;
            }
        } else if ($stream->errors->read()) {
            $this->errors->write($stream->errors->consume_bytes(), [
                'type' => 'error',
                'stream' => $stream,
                ...($stream->errors->get_metadata() ?? []),
            ]);
        }
    }

    // Iterator methods. These don't make much sense on a regular
    // process class because they cannot pull more input chunks from
    // the top of the stream like ProcessChain can.

    private $iterator_output_cache;
    private $chunk_nb = -1;
	public function current(): mixed {
		if(null === $this->iterator_output_cache) {
			$this->iterator_output_cache = $this->output->consume_bytes();
		}
		return $this->iterator_output_cache;
	}

	public function key(): mixed {
		return $this->chunk_nb;
	}

	public function rewind(): void {
		$this->next();
	}

	public function next(): void {
		$this->iterator_output_cache = null;
		while(!$this->tick()) {
            if(!$this->is_alive()) {
                break;
            }
			usleep(10000);
		}
	}

	public function valid(): bool {
		return $this->is_alive();
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


class HttpStream extends Stream {
	private $client;
	private $requests = [];
	private $child_contexts = [];
	private $skipped_requests = [];

    static public function create($requests) {
        return new HttpStream($requests);
    }

	private function __construct( $requests ) {
		$this->client = new Client();
		$this->client->enqueue( $requests );

        parent::__construct();
	}

    protected function do_tick($tick_context): bool
    {
        while($this->client->await_next_event()) {
            $request = $this->client->get_request();
            $output_sequence = 'request_' . $request->id;
            switch ($this->client->get_event()) {
                case Client::EVENT_BODY_CHUNK_AVAILABLE:
                    $this->output->write($this->client->get_response_body_chunk(), [
                        'sequence' => $output_sequence,
                        'request' => $request
                    ]);
                    return true;

                case Client::EVENT_FAILED:
                    $this->errors->write('Request failed: ' . $request->error, [
                        'request' => $request
                    ]);
                    break;
            }
        }

        $this->finish();
        return false;
	}

}


class XMLTransformStream extends ProcessorStream {
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

	private function __construct( $node_visitor_callback ) {
		$this->node_visitor_callback = $node_visitor_callback;
        parent::__construct();
	}

    protected function create_processor(): IStreamProcessor
    {
        return new WP_XML_Processor( '', [], WP_XML_Processor::IN_PROLOG_CONTEXT );
    }

    protected function next(): bool
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
			$buffer .= $this->processor->get_unprocessed_xml();
            $this->finish();
		}

        if(!strlen($buffer)) {
            return false;
        }

        $this->output->write($buffer);

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


$stream = new StreamChain(
    [
        HttpStream::create([
            new Request('http://127.0.0.1:9864/export.wxr.zip'),
            // Bad request, will fail:
            new Request('http://127.0.0.1:9865'),
        ]),
        'zip' => ZipReaderStream::create(),
        CallbackStream::create(function ($data, $context) {
            if ($context['zip']['file_id'] !== 'export.wxr') {
                $context['zip']->skip_file('export.wxr');
                return null;
            }
            print_r($context['zip']->get_processor()->get_header());
            return $data;
        }),
        'xml' => XMLTransformStream::create(function (WP_XML_Processor $processor) {
            if (is_wxr_content_node($processor)) {
                $text = $processor->get_modifiable_text();
                $updated_text = 'Hey there, what\'s up?';
                if ($updated_text !== $text) {
                    $processor->set_modifiable_text($updated_text);
                }
            }
        }),
        CallbackStream::create(function ($data, $context) {
            return strtoupper($data);
        })
    ],
    null,
    null,
    new FilePipe('php://stderr', 'w')
);

foreach($stream as $k => $chunk) {
    var_dump([
        $k => $chunk,
        'zip file_id' => $stream['zip']['file_id']
    ]);
}

