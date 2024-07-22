<?php

class ProcessManager {

    static private $last_pid = 1;
    static private $process_table = [];
    static private $reaped_pids = [];

    static public function spawn($factory, $stdin=null, $stdout=null, $stderr=null) {
        $process = $factory();
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

    public function tick($tick_context) {
        if(!$this->is_alive()) {
            return;
        }

        return $this->do_tick($tick_context);
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

    protected function set_write_channel(string $name)
    {
        $this->stderr->set_channel_for_write($name);
        $this->stdout->set_channel_for_write($name);
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
        $this->stdout->write($this->transform($data, $tick_context));
    }

    abstract protected function transform($data, $tick_context);

}

interface Pipe {
    public function read();
    public function write(string $data, $metadata=null);
    public function is_eof();
    public function close();
}

class UnixPipe implements Pipe {
    public ?string $buffer = null;
    public $metadata = null;
    private bool $closed = false;

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
        $this->channels[$name] = $pipe ?? new UnixPipe();
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
    protected function transform($data, $tick_context) {
        return strtoupper($data);
    }
}

class Demultiplexer extends Process {
    private $process_factory = [];
    public $subprocesses = [];
    private $killed_subprocesses = [];
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
}


class ShellCommandsChain extends Process {
    public array $process_factories;
    public $subprocesses = [];
    private $reaped_pids = [];

    public function __construct($process_factories) {
        $this->process_factories = $process_factories;
    }

    public function init() {
        $last_process = null;
        $names = array_keys($this->process_factories);
        $processes = array_values($this->process_factories);
        for($i = 0; $i < count($this->process_factories); $i++) {
            if(null === $last_process) {
                $stdin = $this->stdin;
            } else {
                $stdin = $last_process->stdout;
            }
            $subprocess = ProcessManager::spawn(
                $processes[$i], 
                $stdin,
                null,
                $this->stderr
            );
            $this->subprocesses[$names[$i]] = $subprocess;
            $last_process = $subprocess;
        }
    }

    protected function do_tick($tick_context) {
        foreach ($this->subprocesses as $name => $process) {
            if ($process->is_alive()) {
                $process->tick($tick_context);
            }

            if(!$process->stdout->is_eof()) {
                $metadata = $process->stdout->get_metadata();
                if (null !== $metadata) {
                    $tick_context[$name] = $metadata;
                }
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

        $data = $process->stdout->read();
        if(null !== $data && false !== $data) {
            $this->stdout->write($data, $tick_context);
        }

        if($process->stdout->is_eof()) {
            $this->kill(0);
        }
    }
}

$process = ProcessManager::spawn(fn () => new ShellCommandsChain([
    'http' => fn() => new FakeHttpClient(),
    'uc' => fn() => new Uppercaser(),
    // 'upper' => fn() => new Demultiplexer(fn() => new Uppercaser())
]));

$i = 0;

do {
    $process->tick([]);

    $data = $process->stdout->read();
    if(is_string($data)) {
        echo 'Data: ' . $data . "\n";
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
} while ($process->is_alive());

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