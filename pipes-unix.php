<?php

class ProcessManager {

    static private $last_pid = 1;
    static private $process_table = [];
    static private $reaped_pids = [];

    static public function spawn($factory, $stdin=null, $stdout=null, $stderr=null) {
        $process = $factory();
        $process->stdin = $stdin ?? new UnixPipe();
        $process->stdout = $stdout ?? new UnixPipe();
        $process->stderr = $stderr ?? new UnixPipe();
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
    public UnixPipe $stdin;
    public UnixPipe $stdout;
    public UnixPipe $stderr;
    public $pid;

    abstract public function tick($tick_context);

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
        // initialize resources
    }

    public function cleanup() {
        // clean up resources
    }
}

class UnixPipe {
    public string $buffer = '';
    public $metadata = null;
    private bool $closed = false;

    public function read() {
        $buffer = $this->buffer;
        if(!$buffer && $this->closed) {
            return false;
        }
        $this->buffer = '';
        return $buffer;
    }

    public function get_metadata() {
        return $this->metadata;        
    }

    public function write(string $data, $metadata=null) {
        if($this->closed) {
            return false;
        }
        $this->buffer .= $data;
        $this->metadata = $metadata;
    }

    public function is_eof() {
        return '' === $this->buffer && $this->closed;        
    }

    public function close() {
        $this->closed = true;
    }
}

class HelloWorld extends Process {
    public function tick($tick_context) {
        $this->stdout->write("Hello, world!", [
            'file_id' => 1,
        ]);
        $this->stderr->write("Critical error has occured :(");
        $this->kill(1);
    }
}

class Uppercaser extends Process {
    public function tick($tick_context) {
        if($this->stdin->is_eof()) {
            $this->stdout->write('Final chunk');
            $this->kill(0);
            return;
        }

        $data = $this->stdin->read();
        if ($data) {
            $this->stdout->write(strtoupper($data));
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
            if($i === count($this->process_factories) - 1) {
                $stdout = $this->stdout;
            } else {
                $stdout = null;
            }
            $subprocess = ProcessManager::spawn(
                $processes[$i], 
                $stdin,
                $stdout
            );
            $this->subprocesses[$names[$i]] = $subprocess;
            $last_process = $subprocess;
        }
    }

    public function tick($tick_context) {
        $this->stdout->metadata = null;
        foreach ($this->subprocesses as $name => $process) {
            $process->tick($tick_context);
            if($process->has_crashed()) {
                if (!ProcessManager::is_reaped($process->pid)) {
                    ProcessManager::reap($process->pid);
                    $this->stderr->write("Process $name has crashed with code {$process->exit_code}", [
                        'reaped' => true,
                        'process' => $name,
                        'exit_code' => $process->exit_code,
                    ]);
                    return;
                } else {
                    continue;
                }
            }
            $metadata = $process->stdout->get_metadata();
            if (null !== $metadata) {
                $tick_context[$name] = $metadata;
            }
        }
        $this->stdout->metadata = $tick_context;
        if(!$process->is_alive()) {
            $this->kill(0);
        }
        print_r($this->stdout);
    }
}

$process = ProcessManager::spawn(fn () => new ShellCommandsChain([
    'hello' => fn() => new HelloWorld(),
    'upper' => fn() => new Uppercaser()
]));

$process->tick([]);
echo $process->stdout->read();
// var_dump($process->stdout->get_metadata());
$process->tick([]);
var_dump($process->stdout->get_metadata());
var_dump($process->stderr->read());
$process->tick([]);
echo $process->stdout->read();
// var_dump($process->stdout->is_eof());
// var_dump($process->is_alive());