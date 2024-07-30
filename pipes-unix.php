<?php

/**
 * @TODO:
 *
 * * Find a naming scheme that doesn't suggest we're working with actual Unix processes and pipes.
 *   I only used it to make the development easier, I got confused with the other attempt in
 *   `pipes.php` and this kept me on track. However, keeping these names will likely confuse others.
 * * ✅ Explore merging Pipes and Processes into a single concept after all.
 *      Not doing that is nice, too. Writing to stdout is not equivalent to
 *      starting more computation downstream. Reading from stdin is not equivalent
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
 *     ^ Well, writing metadata to stdout is the same as coupling it with the stream instance.
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
 *         Currently we assume a tick() call that does $stdin->read(). We could have a public
 *         Process::write() method 
 *    ^ MultiplexedPipe isn't used anymore
 * * ✅ The process `do_tick` method typically checks for `stdin->is_eof()` and then
 *      whether `stdin->read()` is valid. Can we simplify this boilerplate somehow?
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
 * * ✅ Get rid of stderr. We don't need it to be a stream. A single $error field + bubbling should do.
 *      Let's keep stderr after all.
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
 *   just connect it's stdin to a subprocess A.1, but it would read from stdin, read metadata,
 *   do processing, ant only then write to A.1 stdin. Still, a better error reporting wouldn't hurt.
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

abstract class Process implements ArrayAccess {
    private ?int $exit_code = null;
    private bool $is_reaped = false;
    public Pipe $stdin;
    public Pipe $stdout;
    public Pipe $stderr;

    public function __construct($stdin=null, $stdout=null, $stderr=null)
    {
        $this->stdin = $stdin ?? new BufferPipe();
        $this->stdout = $stdout ?? new BufferPipe();
        $this->stderr = $stderr ?? new BufferPipe();
    }

    public function run()
    {
        while ($this->is_alive()) {
            $this->tick();
        }
    }

    public function tick($tick_context=null): bool {
        if(!$this->is_alive()) {
            return false;
        }

        return $this->do_tick($tick_context ?? []);
    }

    abstract protected function do_tick($tick_context): bool;

    public function kill($code) {
        $this->exit_code = $code;
        $this->stdin->close();
        $this->stdout->close();
        $this->stderr->close();
    }

    public function reap(): bool
    {
        if($this->is_alive()) {
            return false;
        }
        $this->is_reaped = true;
        $this->cleanup();
        return true;        
    }

    public function is_reaped(): bool
    {
        return $this->is_reaped;        
    }

    public function has_crashed(): bool {
        return $this->exit_code !== null && $this->exit_code !== 0;
    }

    public function is_alive(): bool {
        return $this->exit_code === null;
    }

    protected function cleanup() {
        // clean up resources
    }

    public function skip_file($file_id) {
        // Needs to be implemented by subclasses
        return false;
    }


    public function offsetExists($offset): bool {
        return isset($this->stdout->get_metadata()[$offset]);
    }

    public function offsetGet($offset): mixed {
        return $this->stdout->get_metadata()[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void {
        // No op
    }

    public function offsetUnset($offset): void {
        // No op
    }

}

abstract class BufferProcessor extends Process
{
    protected function do_tick($tick_context): bool
    {
        if(true === $this->read()) {
            return true;
        }

        if (!$this->stdin->read()) {
            if ($this->stdin->is_eof()) {
                $this->kill(0);
            }
            return false;
        }

        $this->write(
            $this->stdin->consume_bytes(),
            $this->stdin->get_metadata()
        );

        return $this->read();
    }

    abstract protected function write($input_chunk, $metadata);
    abstract protected function read(): bool;
}

abstract class TransformProcess extends Process {
    protected function do_tick($tick_context): bool {
        if(!$this->stdin->read()) {
            if($this->stdin->is_eof()) {
                $this->kill(0);
            }
            return false;
        }

        $transformed = $this->transform($this->stdin->consume_bytes(), $tick_context);
        if (null === $transformed || false === $transformed) {
            return false;
        }

        $this->stdout->write($transformed, $this->stdin->get_metadata());
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
    public ?string $buffer = null;
    public $metadata = null;
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
    public $resource;
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

/**
 * This isn't used anymore. Yay! It could be just removed,
 * but it looks useful so let's keep it around for a while.
 */
class MultiplexingPipe implements Pipe {
    private $used = false;
    private array $sequences = [];
    private ?string $last_read_sequence = 'default';

    public function __construct(array $pipes = [])
    {
        $this->sequences = $pipes;
    }

    public function read(): ?bool {
        if (empty($this->sequences)) {
            return false;
        }

        $sequences_to_check = $this->next_sequences();
        foreach($sequences_to_check as $sequence_name) {
            if(!$this->sequences[$sequence_name]->read()) {
                continue;
            }
            $this->last_read_sequence = $sequence_name;
            return true;
        }

        return null;
    }

    public function consume_bytes()
    {
        if(!$this->last_read_sequence || !isset($this->sequences[$this->last_read_sequence])) {
            return null;
        }
        return $this->sequences[$this->last_read_sequence]->consume_bytes();
    }

    public function get_metadata() {
        if(!$this->last_read_sequence || !isset($this->sequences[$this->last_read_sequence])) {
            return null;
        }
        return $this->sequences[$this->last_read_sequence]->get_metadata();
    }

    private function next_sequences() {
        $sequences_queue = [];
        $sequence_names = array_keys($this->sequences);
        $last_read_sequence_index = array_search($this->last_read_sequence, $sequence_names);
        if(false === $last_read_sequence_index) {
            $last_read_sequence_index = 0;
        } else if($last_read_sequence_index > count($sequence_names)) {
            $last_read_sequence_index = count($sequence_names) - 1;
        }

        $this->last_read_sequence = null;
        for ($i = 1; $i <= count($sequence_names); $i++) {
            $key_index = ($last_read_sequence_index + $i) % count($sequence_names);
            $sequence_name = $sequence_names[$key_index];
            if($this->sequences[$sequence_name]->is_eof()) {
                unset($this->sequences[$sequence_name]);
                continue;
            }
            $this->last_read_sequence = $sequence_name;
            $sequences_queue[] = $sequence_name;
        }
        return $sequences_queue;
    }

    public function write(string $data, $metadata = null): bool {
        $this->used = true;
        $current_sequence = 'default';

        if(is_array($metadata) && isset($metadata['sequence'])) {
            $current_sequence = $metadata['sequence'];
        }

        if (!isset($this->sequences[$current_sequence])) {
            $this->sequences[$current_sequence] = new BufferPipe();
        }

        $this->last_read_sequence = $current_sequence;
        return $this->sequences[$current_sequence]->write($data, $metadata);
    }

    public function is_eof(): bool {
        if(!$this->used) {
            return false;
        }
        foreach ($this->sequences as $pipe) {
            if (!$pipe->is_eof()) {
                return false;
            }
        }
        return true;
    }

    public function close() {
        $this->used = true;
        foreach ($this->sequences as $pipe) {
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

class Demultiplexer extends BufferProcessor {
    private $process_factory = [];
    public $subprocesses = [];
    private $killed_subprocesses = [];
    private $demux_queue = [];
    private $last_subprocess;
    private $last_input_key;
    private $key;
    
    public function __construct($process_factory, $key = 'sequence') {
        $this->process_factory = $process_factory;
        $this->key = $key;
        parent::__construct();
    }

    public function get_subprocess()
    {
        return $this->last_subprocess;        
    }

    protected function write($next_chunk, $metadata) {
        $chunk_key = is_array($metadata) && !empty( $metadata[$this->key] ) ? $metadata[$this->key] : 'default';
        $this->last_input_key = $chunk_key;
        if (!isset($this->subprocesses[$chunk_key])) {
            $factory = $this->process_factory;
            $this->subprocesses[$chunk_key] = $factory();
        }

        $subprocess = $this->subprocesses[$chunk_key];
        $subprocess->stdin->write($next_chunk, $metadata);
        $this->last_subprocess = $subprocess;
    }

    protected function read(): bool
    {
        $subprocess = $this->last_subprocess;
        if(!$subprocess) {
            return false;
        }

        if(!$subprocess->tick()) {
            return false;
        }
    
        if ($subprocess->stdout->read()) {
            $output = $subprocess->stdout->consume_bytes();
            $chunk_metadata = array_merge(
                [$this->key => $this->last_input_key],
                $subprocess->stdout->get_metadata() ?? [],
            );
            $this->stdout->write($output, $chunk_metadata);
            return true;
        }

        if (!$subprocess->is_alive()) {
            if ($subprocess->has_crashed()) {
                $this->stderr->write(
                    "Subprocess $this->last_input_key has crashed with code {$subprocess->exit_code}",
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

class ZipReaderProcess extends BufferProcessor {

    private $reader;
    private $last_skipped_file = null;

    static public function stream() {
        return fn () => new Demultiplexer(fn() => new ZipReaderProcess());
    }

    protected function __construct() {
        parent::__construct();
        $this->reader = new ZipStreamReader('');
    }

    public function get_zip_reader()
    {
        return $this->reader;
    }

    public function skip_file($file_id)
    {
        $this->last_skipped_file = $file_id;
    }

    protected function write($bytes, $metadata) {
        $this->reader->append_bytes($bytes);
    }

    protected function read(): bool
    {
        while ($this->reader->next()) {
            switch ($this->reader->get_state()) {
                case ZipStreamReader::STATE_FILE_ENTRY:
                    $file_path = $this->reader->get_file_path();
                    if ($this->last_skipped_file === $file_path) {
                        break;
                    }
                    $this->stdout->write($this->reader->get_file_body_chunk(), [
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

class ProcessChain extends Process implements Iterator {
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
    protected function do_tick($tick_context): bool {
        if($this->last_subprocess->stdout->is_eof()) {
            $this->kill(0);
            return false;
        }

        while(true) {
            if(true !== $this->stdin->read()) {
                break;
            }
            $this->first_subprocess->stdin->write(
                $this->stdin->consume_bytes(),
                $this->stdin->get_metadata()
            );
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
            if(true !== $this->last_subprocess->stdout->read()) {
                break;
            }
            $this->stdout->write(
                $this->last_subprocess->stdout->consume_bytes(),
                $this->tick_context
            );
            ++$this->chunk_nb;
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
        if($process instanceof Demultiplexer) {
            $process = $process->get_subprocess();
        }
        $this->tick_context[$name] = $process;
    }

    private function tick_subprocess($process)
    {
        $produced_output = $process->tick($this->tick_context);
        $this->handle_errors($process);
        return $produced_output;
    }

    private function handle_errors($process)
    {
        while ($process->stderr->read()) {
            $this->stderr->write($process->stderr->consume_bytes(), [
                'type' => 'error',
                'process' => $process,
                ...($process->stderr->get_metadata() ?? []),
            ]);
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

    // Iterator methods. These don't make much sense on a regular
    // process class because they cannot pull more input chunks from
    // the top of the stream like ProcessChain can.

    private $iterator_output_cache;
    private $chunk_nb = -1;
	public function current(): mixed {
		if(null === $this->iterator_output_cache) {
			$this->iterator_output_cache = $this->stdout->consume_bytes();
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

    protected function do_tick($tick_context): bool
    {
        while($this->client->await_next_event()) {
            $request = $this->client->get_request();
            $output_sequence = 'request_' . $request->id;
            switch ($this->client->get_event()) {
                case Client::EVENT_BODY_CHUNK_AVAILABLE:
                    $this->stdout->write($this->client->get_response_body_chunk(), [
                        'sequence' => $output_sequence,
                        'request' => $request
                    ]);
                    return true;

                case Client::EVENT_FAILED:
                    $this->stderr->write('Request failed: ' . $request->error, [
                        'request' => $request
                    ]);
                    break;
            }
        }

        $this->kill(0);
        return false;
	}

}


class XMLProcess extends BufferProcessor {
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

    public function get_xml_processor()
    {
        return $this->xml_processor;
    }

    protected function write($bytes, $metadata)
    {
        $this->xml_processor->stream_append_xml($bytes);
    }

    protected function read(): bool
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
        if ($context['zip']['file_id'] !== 'export.wxr') {
            $context['zip']->skip_file('export.wxr');
            return null;
        }
        print_r($context['zip']->get_zip_reader()->get_header());
        return $data;
    }),
    'xml' => XMLProcess::stream($rewrite_links_in_wxr_node),
    Uppercaser::stream(),
]);
// $process->stdout = new FilePipe('php://stdout', 'w');
$process->stderr = new FilePipe('php://stderr', 'w');
foreach($process as $k => $chunk) {
    var_dump([$k => $chunk]);
}

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
