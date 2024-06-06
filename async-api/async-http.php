<?php

namespace WordPress\Streams {


    class VanillaStreamWrapperData {
        public $fp;

        public function __construct( $fp ) {
            $this->fp = $fp;
        }
    }

    interface StreamWrapperInterface {

        /**
         * Sets an option on the stream
         *
         * @param int      $option
         * @param int      $arg1
         * @param int|null $arg2
         *
         * @return bool
         */
        public function stream_set_option( $option, $arg1, $arg2 = null ): bool;

        /**
         * Opens the stream
         */
        public function stream_open( $path, $mode, $options, &$opened_path );

        /**
         * @param int $cast_as
         */
        public function stream_cast( $cast_as );

        /**
         * Reads from the stream
         */
        public function stream_read( $count );

        /**
         * Writes to the stream
         */
        public function stream_write( $data );

        /**
         * Closes the stream
         */
        public function stream_close();

        /**
         * Returns the current position of the stream
         */
        public function stream_tell();

        /**
         * Checks if the end of the stream has been reached
         */
        public function stream_eof();

        /**
         * Seeks to a specific position in the stream
         */
        public function stream_seek( $offset, $whence );

        /**
         * Stat information about the stream; providing dummy data
         */
        public function stream_stat();
    }

    class VanillaStreamWrapper implements StreamWrapperInterface {
        protected $stream;

        protected $context;

        protected $wrapper_data;

        const SCHEME = 'vanilla';

        /**
         * @param \WordPress\Streams\VanillaStreamWrapperData $data
         */
        public static function create_resource( $data ) {
            static::register();

            $context = stream_context_create(
                array(
                    static::SCHEME => array(
                        'wrapper_data' => $data,
                    ),
                )
            );

            return fopen( static::SCHEME . '://', 'r', false, $context );
        }

        public static function register() {
            if ( in_array( static::SCHEME, stream_get_wrappers() ) ) {
                return;
            }

            if ( ! stream_wrapper_register( static::SCHEME, static::class ) ) {
                throw new \Exception( 'Failed to register protocol' );
            }
        }

        public static function unregister() {
            stream_wrapper_unregister( 'async' );
        }


        /**
         * @param int      $option
         * @param int      $arg1
         * @param int|null $arg2
         */
        public function stream_set_option( $option, $arg1, $arg2 = null ): bool {
            if ( \STREAM_OPTION_BLOCKING === $option ) {
                return stream_set_blocking( $this->stream, (bool) $arg1 );
            } elseif ( \STREAM_OPTION_READ_TIMEOUT === $option ) {
                return stream_set_timeout( $this->stream, $arg1, $arg2 );
            }

            return false;
        }

        public function stream_open( $path, $mode, $options, &$opened_path ) {
            $contextOptions = stream_context_get_options( $this->context );

            if ( ! isset( $contextOptions[ static::SCHEME ]['wrapper_data'] ) || ! is_object( $contextOptions[ static::SCHEME ]['wrapper_data'] ) ) {
                return false;
            }

            $this->wrapper_data = $contextOptions[ static::SCHEME ]['wrapper_data'];

            if ( $this->wrapper_data->fp ) {
                $this->stream = $this->wrapper_data->fp;
            }

            return true;
        }

        /**
         * @param int $cast_as
         */
        public function stream_cast( $cast_as ) {
            return $this->stream;
        }

        public function stream_read( $count ) {
            return fread( $this->stream, $count );
        }

        public function stream_write( $data ) {
            return fwrite( $this->stream, $data );
        }

        public function stream_close() {
            return fclose( $this->stream );
        }

        public function stream_tell() {
            return ftell( $this->stream );
        }

        public function stream_eof() {
            return feof( $this->stream );
        }

        public function stream_seek( $offset, $whence ) {
            return fseek( $this->stream, $offset, $whence );
        }

        public function stream_stat() {
            return array();
        }
    }
        
    class StreamPeekerWrapper extends VanillaStreamWrapper {
        protected $onChunk;
        protected $onClose;
        protected $position;
    
        const SCHEME = 'peek';
    
        // Opens the stream
        public function stream_open( $path, $mode, $options, &$opened_path ) {
            parent::stream_open( $path, $mode, $options, $opened_path );
    
            if ( isset( $this->wrapper_data->fp ) ) {
                $this->stream = $this->wrapper_data->fp;
            } else {
                return false;
            }
    
            if ( isset( $this->wrapper_data->onChunk ) && is_callable( $this->wrapper_data->onChunk ) ) {
                $this->onChunk = $this->wrapper_data->onChunk;
            } else {
                // Default onChunk function if none provided
                $this->onChunk = function ( $data ) {
                };
            }
    
            if ( isset( $this->wrapper_data->onClose ) && is_callable( $this->wrapper_data->onClose ) ) {
                $this->onClose = $this->wrapper_data->onClose;
            } else {
                // Default onClose function if none provided
                $this->onClose = function () {
                };
            }
    
            $this->position = 0;
    
            return true;
        }
    
        // Reads from the stream
        public function stream_read( $count ) {
            $ret             = fread( $this->stream, $count );
            $this->position += strlen( $ret );
    
            $onChunk = $this->onChunk;
            $onChunk( $ret );
    
            return $ret;
        }
    
        // Writes to the stream
        public function stream_write( $data ) {
            $written         = fwrite( $this->stream, $data );
            $this->position += $written;
    
            return $written;
        }
    
        // Closes the stream
        public function stream_close() {
            fclose( $this->stream );
            $onClose = $this->onClose;
            $onClose();
        }
    
        // Returns the current position of the stream
        public function stream_tell() {
            return $this->position;
        }
    }

    class StreamPeekerData extends VanillaStreamWrapperData {

        public $fp;
        public $onChunk = null;
        public $onClose = null;
        public function __construct( $fp, $onChunk = null, $onClose = null ) {
            $this->fp      = $fp;
            $this->onChunk = $onChunk;
            $this->onClose = $onClose;
            parent::__construct( $fp );
        }
    }
}

namespace WordPress\Util {

    use ArrayAccess;
    use IteratorAggregate;
    use Traversable;
    
    class ArrayPairIterator implements \Iterator {
        private $array;
        private $position = 0;
    
        public function __construct( array $array ) {
            $this->array = $array;
        }
    
        #[\ReturnTypeWillChange]
        public function current() {
            return $this->array[ $this->position ][1];
        }
    
        #[\ReturnTypeWillChange]
        public function key() {
            return $this->array[ $this->position ][0];
        }
    
        #[\ReturnTypeWillChange]
        public function next() {
            ++$this->position;
        }
    
        #[\ReturnTypeWillChange]
        public function rewind() {
            $this->position = 0;
        }
    
        #[\ReturnTypeWillChange]
        public function valid() {
            return isset( $this->array[ $this->position ] );
        }
    }

    class Map implements ArrayAccess, IteratorAggregate {
        private $pairs = array();

        public function __construct() {
        }

        public function offsetExists( $offset ): bool {
            foreach ( $this->pairs as $pair ) {
                if ( $pair[0] === $offset ) {
                    return true;
                }
            }

            return false;
        }

        #[\ReturnTypeWillChange]
        public function offsetGet( $offset ) {
            foreach ( $this->pairs as $pair ) {
                if ( $pair[0] === $offset ) {
                    return $pair[1];
                }
            }

            // TODO Evaluate waring: 'ext-json' is missing in composer.json
            throw new \Exception( 'Stream for resource ' . json_encode( $offset ) . ' not found' );
        }

        #[\ReturnTypeWillChange]
        public function offsetSet( $offset, $value ) {
            foreach ( $this->pairs as $k => $pair ) {
                if ( $pair[0] === $offset ) {
                    $this->pairs[ $k ] = array( $offset, $value );

                    return;
                }
            }
            $this->pairs[] = array( $offset, $value );
        }

        #[\ReturnTypeWillChange]
        public function offsetUnset( $offset ) {
            foreach ( $this->pairs as $i => $pair ) {
                if ( $pair[0] === $offset ) {
                    unset( $this->pairs[ $i ] );
                }
            }
        }

        public function getIterator(): Traversable {
            return new ArrayPairIterator( array_values( $this->pairs ) );
        }
    }

}
namespace WordPress\AsyncHttp {

    use Exception;
    use WordPress\Util\Map;
    use WordPress\Streams\VanillaStreamWrapper;
    use WordPress\Streams\VanillaStreamWrapperData;
    use WordPress\Streams\StreamPeekerData;
    use WordPress\Streams\StreamPeekerWrapper;

    class StreamData extends VanillaStreamWrapperData
    {

        public $request;
        public $client;

        public function __construct(Request $request, Client $group)
        {
            parent::__construct(null);
            $this->request = $request;
            $this->client = $group;
        }
    }

    class StreamWrapper extends VanillaStreamWrapper {

        const SCHEME = 'async-http';
    
        /** @var Client */
        private $client;
    
        protected function initialize() {
            if ( ! $this->stream ) {
                $this->stream = $this->client->get_stream( $this->wrapper_data->request );
            }
        }
    
        public function stream_open( $path, $mode, $options, &$opened_path ) {
            if ( ! parent::stream_open( $path, $mode, $options, $opened_path ) ) {
                return false;
            }
    
            if ( ! $this->wrapper_data->client ) {
                return false;
            }
            $this->client = $this->wrapper_data->client;
    
            return true;
        }
    
        /**
         * @param int $cast_as
         */
        public function stream_cast( $cast_as ) {
            $this->initialize();
    
            return parent::stream_cast( $cast_as );
        }
    
        public function stream_read( $count ) {
            $this->initialize();
    
            return $this->client->read_bytes( $this->wrapper_data->request, $count );
        }
    
        public function stream_write( $data ) {
            $this->initialize();
    
            return parent::stream_write( $data );
        }
    
        public function stream_tell() {
            if ( ! $this->stream ) {
                return false;
            }
    
            return parent::stream_tell();
        }
    
        public function stream_close() {
            if ( ! $this->stream ) {
                return false;
            }
    
            if ( ! $this->has_valid_stream() ) {
                return false;
            }
    
            return parent::stream_close();
        }
    
        public function stream_eof() {
            if ( ! $this->stream ) {
                return false;
            }
    
            if ( ! $this->has_valid_stream() ) {
                return true;
            }
    
            return parent::stream_eof();
        }
    
        public function stream_seek( $offset, $whence ) {
            if ( ! $this->stream ) {
                return false;
            }
    
            return parent::stream_seek( $offset, $whence );
        }
    
        /*
         * This stream_close call could be initiated not by the developer,
         * but by the PHP internal request shutdown handler (written in C).
         *
         * The underlying resource ($this->stream) may have already been closed
         * and freed independently from the resource represented by $this stream
         * wrapper. In this case, the type of $this->stream will be "Unknown",
         * and the fclose() call will trigger a fatal error.
         *
         * Let's refuse to call fclose() in that scenario.
         */
        protected function has_valid_stream() {
            return get_resource_type( $this->stream ) !== 'Unknown';
        }
    }

    class Request
    {

        public $url;

        /**
         * @param string $url
         */
        public function __construct(string $url)
        {
            $this->url = $url;
        }
    }

    class RequestInfo
    {
        const STATE_ENQUEUED = 'STATE_ENQUEUED';
        const STATE_STREAMING = 'STATE_STREAMING';
        const STATE_FINISHED = 'STATE_FINISHED';
        public $state = self::STATE_ENQUEUED;
        public $stream;
        public $buffer = '';

        /**
         * @param $stream
         */
        public function __construct($stream)
        {
            $this->stream = $stream;
        }

        public function is_finished()
        {
            return $this->state === self::STATE_FINISHED;
        }
    }

    /**
     * An asynchronous HTTP client library designed for WordPress. Main features:
     *
     * **Streaming support**
     * Enqueuing a request returns a PHP resource that can be read by PHP functions like `fopen()`
     * and `stream_get_contents()`
     *
     * ```php
     * $client = new AsyncHttpClient();
     * $fp = $client->enqueue(
     *      new Request( "https://downloads.wordpress.org/plugin/gutenberg.17.7.0.zip" ),
     * );
     * // Read some data
     * $first_4_kilobytes = fread($fp, 4096);
     * // We've only waited for the first four kilobytes. The download
     * // is still in progress at this point, and yet we're free to do
     * // other work.
     * ```
     *
     * **Delayed execution and concurrent downloads**
     * The actual socket are not open until the first time the stream is read from:
     *
     * ```php
     * $client = new AsyncHttpClient();
     * // Enqueuing the requests does not start the data transmission yet.
     * $batch = $client->enqueue( [
     *     new Request( "https://downloads.wordpress.org/plugin/gutenberg.17.7.0.zip" ),
     *     new Request( "https://downloads.wordpress.org/theme/pendant.zip" ),
     * ] );
     * // Even though stream_get_contents() will return just the response body for
     * // one request, it also opens the network sockets and starts streaming
     * // both enqueued requests. The response data for $batch[1] is buffered.
     * $gutenberg_zip = stream_get_contents( $batch[0] )
     *
     * // At least a chunk of the pendant.zip have already been downloaded, let's
     * // wait for the rest of the data:
     * $pendant_zip = stream_get_contents( $batch[1] )
     * ```
     *
     * **Concurrency limits**
     * The `AsyncHttpClient` will only keep up to `$concurrency` connections open. When one of the
     * requests finishes, it will automatically start the next one.
     *
     * For example:
     * ```php
     * $client = new AsyncHttpClient();
     * // Process at most 10 concurrent request at a time.
     * $client->set_concurrency_limit( 10 );
     * ```
     *
     * **Progress monitoring**
     * A developer-provided callback (`AsyncHttpClient->set_progress_callback()`) receives progress
     * information about every HTTP request.
     *
     * ```php
     * $client = new AsyncHttpClient();
     * $client->set_progress_callback( function ( Request $request, $downloaded, $total ) {
     *      // $total is computed based on the Content-Length response header and
     *      // null if it's missing.
     *      echo "$request->url – Downloaded: $downloaded / $total\n";
     * } );
     * ```
     *
     * **HTTPS support**
     * TLS connections work out of the box.
     *
     * **Non-blocking sockets**
     * The act of opening each socket connection is non-blocking and happens nearly
     * instantly. The streams themselves are also set to non-blocking mode via `stream_set_blocking($fp, 0);`
     *
     * **Asynchronous downloads**
     * Start downloading now, do other work in your code, only block once you need the data.
     *
     * **PHP 7.0 support and no dependencies**
     * `AsyncHttpClient` works on any WordPress installation with vanilla PHP only.
     * It does not require any PHP extensions, CURL, or any external PHP libraries.
     *
     * **Supports custom request headers and body**
     */
    class Client
    {
        protected $concurrency = 10;
        protected $requests;
        protected $onProgress;
        protected $queue_needs_processing = false;

        public function __construct()
        {
            $this->requests = new Map();
            $this->onProgress = function () {
            };
        }

        /**
         * Sets the limit of concurrent connections this client will open.
         *
         * @param int $concurrency
         */
        public function set_concurrency_limit($concurrency)
        {
            $this->concurrency = $concurrency;
        }

        /**
         * Sets the callback called when response bytes are received on any of the enqueued
         * requests.
         *
         * @param callable $onProgress A function of three arguments:
         *                             Request $request, int $downloaded, int $total.
         */
        public function set_progress_callback($onProgress)
        {
            $this->onProgress = $onProgress;
        }

        /**
         * Enqueues one or multiple HTTP requests for asynchronous processing.
         * It does not open the network sockets, only adds the Request objects to
         * an internal queue. Network transmission is delayed until one of the returned
         * streams is read from.
         *
         * @param Request|Request[] $requests The HTTP request(s) to enqueue. Can be a single request or an array of requests.
         *
         * @return resource|array The enqueued streams.
         */
        public function enqueue($requests)
        {
            if (!is_array($requests)) {
                return $this->enqueue_request($requests);
            }

            $enqueued_streams = array();
            foreach ($requests as $request) {
                $enqueued_streams[] = $this->enqueue_request($request);
            }

            return $enqueued_streams;
        }

        /**
         * Returns the response stream associated with the given Request object.
         * Enqueues the Request if it hasn't been enqueued yet.
         *
         * @param Request $request
         *
         * @return resource
         */
        public function get_stream($request)
        {
            if (!isset($this->requests[$request])) {
                $this->enqueue_request($request);
            }

            if ($this->queue_needs_processing) {
                $this->process_queue();
            }

            return $this->requests[$request]->stream;
        }

        /**
         * @param \WordPress\AsyncHttp\Request $request
         */
        protected function enqueue_request($request)
        {
            $stream = StreamWrapper::create_resource(
                new StreamData($request, $this)
            );
            $this->requests[$request] = new RequestInfo($stream);
            $this->queue_needs_processing = true;

            return $stream;
        }

        /**
         * Starts n enqueued request up to the $concurrency_limit.
         */
        public function process_queue()
        {
            $this->queue_needs_processing = false;

            $active_requests = count($this->get_streamed_requests());
            $backfill = $this->concurrency - $active_requests;
            if ($backfill <= 0) {
                return;
            }

            $enqueued = array_slice($this->get_enqueued_request(), 0, $backfill);
            list($streams, $response_headers) = streams_send_http_requests($enqueued);

            foreach ($streams as $k => $stream) {
                $request = $enqueued[$k];
                $total = $response_headers[$k]['headers']['content-length'];
                $this->requests[$request]->state = RequestInfo::STATE_STREAMING;
                $this->requests[$request]->stream = stream_monitor_progress(
                    $stream,
                    function ($downloaded) use ($request, $total) {
                        $onProgress = $this->onProgress;
                        $onProgress($request, $downloaded, $total);
                    }
                );
            }
        }

        protected function get_enqueued_request()
        {
            $enqueued_requests = array();
            foreach ($this->requests as $request => $info) {
                if ($info->state === RequestInfo::STATE_ENQUEUED) {
                    $enqueued_requests[] = $request;
                }
            }

            return $enqueued_requests;
        }

        protected function get_streamed_requests()
        {
            $active_requests = array();
            foreach ($this->requests as $request => $info) {
                if ($info->state !== RequestInfo::STATE_ENQUEUED) {
                    $active_requests[] = $request;
                }
            }

            return $active_requests;
        }

        /**
         * Reads up to $length bytes from the stream while polling all the active streams.
         *
         * @param Request $request
         * @param $length
         *
         * @return false|string
         * @throws Exception
         */
        public function read_bytes($request, $length)
        {
            if (!isset($this->requests[$request])) {
                return false;
            }

            if ($this->queue_needs_processing) {
                $this->process_queue();
            }

            $request_info = $this->requests[$request];
            $stream = $request_info->stream;

            $active_requests = $this->get_streamed_requests();
            $active_streams = array_map(
                function ($request) {
                    return $this->requests[$request]->stream;
                },
                $active_requests
            );

            if (!count($active_streams)) {
                return false;
            }

            while (true) {
                if (!$request_info->is_finished() && feof($stream)) {
                    $request_info->state = RequestInfo::STATE_FINISHED;
                    fclose($stream);
                    $this->queue_needs_processing = true;
                }

                if (strlen($request_info->buffer) >= $length) {
                    $buffered = substr($request_info->buffer, 0, $length);
                    $request_info->buffer = substr($request_info->buffer, $length);

                    return $buffered;
                } elseif ($request_info->is_finished()) {
                    unset($this->requests[$request]);

                    return $request_info->buffer;
                }

                $active_streams = array_filter(
                    $active_streams,
                    function ($stream) {
                        return !feof($stream);
                    }
                );
                if (!count($active_streams)) {
                    continue;
                }
                $bytes = streams_http_response_await_bytes(
                    $active_streams,
                    $length - strlen($request_info->buffer)
                );
                foreach ($bytes as $k => $chunk) {
                    $this->requests[$active_requests[$k]]->buffer .= $chunk;
                }
            }
        }
    }


/**
 * Opens multiple HTTP streams in a non-blocking manner.
 *
 * @param array $urls An array of URLs to open streams for.
 *
 * @return array An array of opened streams.
 * @see stream_http_open_nonblocking
 */
function streams_http_open_nonblocking( $urls ) {
	$streams = array();
	foreach ( $urls as $k => $url ) {
		$stream = stream_http_open_nonblocking( $url );
        if ( false !== $stream ) {
            $streams[ $k ] = $stream;
        }
	}

	return $streams;
}

/**
 * Opens a HTTP or HTTPS stream using stream_socket_client() without blocking,
 * and returns nearly immediately.
 *
 * The act of opening a stream is non-blocking itself. This function uses
 * a tcp:// stream wrapper, because both https:// and ssl:// wrappers would block
 * until the SSL handshake is complete.
 * The actual socket it then switched to non-blocking mode using stream_set_blocking().
 *
 * @param string $url The URL to open the stream for.
 *
 * @return resource|false The opened stream resource or false on failure.
 * @throws InvalidArgumentException If the URL scheme is invalid.
 * @throws Exception If unable to open the stream.
 */
function stream_http_open_nonblocking( $url ) {
	$parts  = parse_url( $url );
	$scheme = $parts['scheme'];
	if ( ! in_array( $scheme, array( 'http', 'https' ) ) ) {
		throw new InvalidArgumentException( 'Invalid scheme – only http:// and https:// URLs are supported' );
	}

	$port = $parts['port'] ?? ( $scheme === 'https' ? 443 : 80 );
	$host = $parts['host'];

	// Create stream context
	$context = stream_context_create(
		array(
			'socket' => array(
				'isSsl'       => $scheme === 'https',
				'originalUrl' => $url,
				'socketUrl'   => 'tcp://' . $host . ':' . $port,
			),
		)
	);

	$stream = stream_socket_client(
		'tcp://' . $host . ':' . $port,
		$errno,
		$errstr,
		30,
		STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
		$context
	);
	if ( $stream === false ) {
        error_log( 'stream_socket_client() was unable to open a stream to ' . $url );
        return false;		
	}

	if ( PHP_VERSION_ID >= 72000 ) {
		// In PHP <= 7.1 and later, making the socket non-blocking before the
		// SSL handshake makes the stream_socket_enable_crypto() call always return
		// false. Therefore, we only make the socket non-blocking after the
		// SSL handshake.
		stream_set_blocking( $stream, 0 );
	}

	return $stream;
}

/**
 * Sends HTTP requests using streams.
 *
 * Takes an array of asynchronous streams open using stream_http_open_nonblocking(),
 * enables crypto on the streams, and sends the request headers asynchronously.
 *
 * @param array $streams An array of streams to send the requests.
 *
 * @throws Exception If there is an error enabling crypto or if stream_select times out.
 */
function streams_http_requests_send( &$streams ) {
	$read              = $except = null;
	$remaining_streams = [...$streams];
	while ( count( $remaining_streams ) ) {
		$write = $remaining_streams;
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
		$ready = @stream_select( $read, $write, $except, 0, 5000000 );
		if ( $ready === false ) {
			error_log( 'Error: ' . error_get_last()['message'] );
            return false;
		} elseif ( $ready <= 0 ) {
			error_log( 'stream_select timed out' );
            return false;
		}

		foreach ( $write as $k => $stream ) {
			if ( PHP_VERSION_ID <= 71999 ) {
				// In PHP <= 7.1, stream_select doesn't preserve the keys of the array
				$k = array_search( $stream, $streams, true );
			}
			$enabled_crypto = stream_socket_enable_crypto( $stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT );
			if ( false === $enabled_crypto ) {
				error_log( 'Failed to enable crypto: ' . error_get_last()['message'] );
                unset( $streams[ $k ] );
                unset( $remaining_streams[ $k ] );
			} elseif ( 0 === $enabled_crypto ) {
				// Wait for the handshake to complete
			} else {
				// SSL handshake complete, send the request headers
				$context = stream_context_get_options( $stream );
				$request = stream_http_prepare_request_bytes( $context['socket']['originalUrl'] );

				if ( PHP_VERSION_ID <= 72000 ) {
                    // In PHP <= 7.1 and later, making the socket non-blocking before the
                    // SSL handshake makes the stream_socket_enable_crypto() call always return
                    // false. Therefore, we only make the socket non-blocking after the
                    // SSL handshake.
					stream_set_blocking( $stream, 0 );
				}
				fwrite( $stream, $request );
				unset( $remaining_streams[ $k ] );
			}
		}
	}
}


/**
 * Waits for response bytes to be available in the given streams.
 *
 * @param array $streams The array of streams to wait for.
 * @param int $length The number of bytes to read from each stream.
 * @param int $timeout_microseconds The timeout in microseconds for the stream_select function.
 *
 * @return array|false An array of chunks read from the streams, or false if no streams are available.
 * @throws Exception If an error occurs during the stream_select operation or if the operation times out.
 */
function streams_http_response_await_bytes( $streams, $length, $timeout_microseconds = 5000000 ) {
	$read = $streams;
	if ( count( $read ) === 0 ) {
		return false;
	}
	$write  = array();
	$except = null;
	// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
	$ready = @stream_select( $read, $write, $except, 0, $timeout_microseconds );
	if ( $ready === false ) {
		throw new Exception( 'Could not retrieve response bytes: ' . error_get_last()['message'] );
	} elseif ( $ready <= 0 ) {
		throw new Exception( 'stream_select timed out' );
	}

	$chunks = array();
	foreach ( $read as $k => $stream ) {
		if ( PHP_VERSION_ID <= 71999 ) {
			// In PHP <= 7.1, stream_select doesn't preserve the keys of the array
			$k = array_search( $stream, $streams, true );
		}
		$chunks[ $k ] = fread( $stream, $length );
	}

	return $chunks;
}

/**
 * Parses an HTTP headers string into an array containing the status and headers.
 *
 * @param string $headers The HTTP headers to parse.
 *
 * @return array An array containing the parsed status and headers.
 */
function parse_http_headers( string $headers ) {
	$lines   = explode( "\r\n", $headers );
	$status  = array_shift( $lines );
	$status  = explode( ' ', $status );
	$status  = array(
		'protocol' => $status[0],
		'code'     => $status[1],
		'message'  => $status[2],
	);
	$headers = array();
	foreach ( $lines as $line ) {
		if ( strpos( $line, ': ' ) === false ) {
			continue;
		}
		$line                              = explode( ': ', $line );
		$headers[ strtolower( $line[0] ) ] = $line[1];
	}

	return array(
		'status'  => $status,
		'headers' => $headers,
	);
}

/**
 * Prepares an HTTP request string for a given URL.
 *
 * @param string $url The URL to prepare the request for.
 *
 * @return string The prepared HTTP request string.
 */
function stream_http_prepare_request_bytes( $url ) {
	$parts   = parse_url( $url );
	$host    = $parts['host'];
	$path    = $parts['path'] . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	$request = <<<REQUEST
GET $path HTTP/1.1
Host: $host
User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/93.0.4577.82 Safari/537.36
Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9
Accept-Language: en-US,en;q=0.9
Connection: close
REQUEST;

	// @TODO: Add support for Accept-Encoding: gzip

	return str_replace( "\n", "\r\n", $request ) . "\r\n\r\n";
}

/**
 * Awaits and retrieves the HTTP response headers for multiple streams.
 *
 * @param array $streams An array of streams.
 *
 * @return array An array of HTTP response headers for each stream.
 */
function streams_http_response_await_headers( $streams ) {
	$headers = array();
	foreach ( $streams as $k => $stream ) {
		$headers[ $k ] = '';
	}
	$remaining_streams = $streams;
	while ( true ) {
		$bytes = streams_http_response_await_bytes( $remaining_streams, 1 );
		if ( false === $bytes ) {
			break;
		}
		foreach ( $bytes as $k => $byte ) {
			$headers[ $k ] .= $byte;
			if ( 
                $byte === '' ||
                ( strlen($headers[$k]) >= 4 && substr_compare( $headers[ $k ], "\r\n\r\n", - strlen( "\r\n\r\n" ) ) === 0 )
             ) {
				unset( $remaining_streams[ $k ] );
			}
		}
	}

	foreach ( $headers as $k => $header ) {
		$headers[ $k ] = parse_http_headers( $header );
	}

	return $headers;
}

/**
 * Monitors the progress of a stream while reading its content.
 *
 * @param resource $stream The stream to monitor.
 * @param callable $onProgress The callback function to be called on each progress update.
 *                             It should accept a single parameters: the number of bytes streamed so far.
 *
 * @return resource The wrapped stream resource.
 */
function stream_monitor_progress( $stream, $onProgress ) {
	return StreamPeekerWrapper::create_resource(
		new StreamPeekerData(
			$stream,
			function ( $data ) use ( $onProgress ) {
				static $streamedBytes = 0;
				$streamedBytes += strlen( $data );
				$onProgress( $streamedBytes );
			}
		)
	);
}

/**
 * Sends multiple HTTP requests asynchronously and returns the response streams.
 *
 * @param array $requests An array of HTTP requests.
 *
 * @return array An array containing the final streams and response headers.
 * @throws Exception If any of the requests fail with a non-successful HTTP code.
 */
function streams_send_http_requests( array $requests ) {
	$urls = array();
	foreach ( $requests as $k => $request ) {
		$urls[ $k ] = $request->url;
	}
	$redirects        = $urls;
	$final_streams    = array();
	$response_headers = array();
	do {
		$streams = streams_http_open_nonblocking( $redirects );
		streams_http_requests_send( $streams );
            
		$redirects = array();
		$headers   = streams_http_response_await_headers( $streams );
		foreach ( array_keys( $headers ) as $k ) {
			$code = $headers[ $k ]['status']['code'];
			if ( $code > 399 || $code < 200 ) {
				error_log( 'Failed to download file ' . $requests[ $k ]->url . ': Server responded with HTTP code ' . $code );
                fclose( $streams[ $k ] );
                continue;
			}
			if ( isset( $headers[ $k ]['headers']['location'] ) ) {
				$redirects[ $k ] = $headers[ $k ]['headers']['location'];
				fclose( $streams[ $k ] );
				continue;
			}

			$final_streams[ $k ]    = $streams[ $k ];
			$response_headers[ $k ] = $headers[ $k ];
		}
	} while ( count( $redirects ) );

	return array( $final_streams, $response_headers );
}
}