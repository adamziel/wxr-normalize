<?php

define('RUN_ZIP_SMOKE_TEST', false);

class ZipStreamReaderCursor {
	public $zip = '';
	public $bytes_parsed_so_far = 0;
	public $state = ZipStreamReader::STATE_SCAN;
	public $header = null;
	public $file_body_chunk = null;
	/**
	 * Experimental: Store the last used compressed chunk so that we can recreate
	 * the inflate context. This is necessary, because deflate-raw has a variable
	 * block size and the binary data we've already seen contain information required
	 * to continue the decompression process.
	 * 
	 * @TODO: Rigorously confirm that this reasoning is correct and then rigorously
	 *        keep track of as many $last_compressed_bytes as necessary.
	 */
	public $last_compressed_bytes = '';
	public $file_compressed_bytes_read_so_far = null;
	public $paused_incomplete_input = false;
	public $error_message;
}

// class ZipStreamReader implements IStreamProcessor {
class ZipStreamReader {

	const SIGNATURE_FILE                  = 0x04034b50;
	const SIGNATURE_CENTRAL_DIRECTORY     = 0x02014b50;
	const SIGNATURE_CENTRAL_DIRECTORY_END = 0x06054b50;
	const COMPRESSION_DEFLATE             = 8;

	private $cursor = '';
	private $inflate_handle;
	
	const STATE_SCAN = 'scan';
	const STATE_FILE_ENTRY = 'file-entry';
	const STATE_CENTRAL_DIRECTORY_ENTRY = 'central-directory-entry';
	const STATE_CENTRAL_DIRECTORY_ENTRY_EXTRA = 'central-directory-entry-extra';
	const STATE_END_CENTRAL_DIRECTORY_ENTRY = 'end-central-directory-entry';
	const STATE_END_CENTRAL_DIRECTORY_ENTRY_EXTRA = 'end-central-directory-entry-extra';
	const STATE_COMPLETE = 'complete';
	const STATE_ERROR = 'error';

    static public function stream() {
        return new Demultiplexer(fn() => new ZipReaderStream());
    }

	public function pause() {
		return $this->cursor;
	}

	static public function resume(ZipStreamReaderCursor $cursor) {
		$reader = new ZipStreamReader();
		$reader->cursor = $cursor;
		$reader->inflate_handle = inflate_init(ZLIB_ENCODING_RAW);
		inflate_add($reader->inflate_handle, $cursor->last_compressed_bytes);
		return $reader;
	}
	
	public function __construct($bytes='') {
		$this->cursor = new ZipStreamReaderCursor();
		$this->cursor->zip = $bytes;
	}

	public function append_bytes(string $bytes)
	{
		$this->cursor->zip = substr($this->cursor->zip, $this->cursor->bytes_parsed_so_far) . $bytes;	
        $this->cursor->bytes_parsed_so_far = 0;
		$this->cursor->paused_incomplete_input = false;
	}

	public function is_paused_at_incomplete_input(): bool {
		return $this->cursor->paused_incomplete_input;		
	}

	public function is_finished(): bool
	{
		return self::STATE_COMPLETE === $this->cursor->state || self::STATE_ERROR === $this->cursor->state;
	}

    public function get_state()
    {
        return $this->cursor->state;        
    }

    public function get_header()
    {
        return $this->cursor->header;
    }

    public function get_file_path()
    {
        if(!$this->cursor->header) {
            return null;
        }
        
        return $this->cursor->header['path'];        
    }

    public function get_file_body_chunk()
    {
        return $this->cursor->file_body_chunk;        
    }

    public function get_last_error(): ?string
    {
        return $this->cursor->error_message;        
    }

	public function next()
	{
        do {
            if(self::STATE_SCAN === $this->cursor->state) {
                if(false === $this->scan()) {
                    return false;
                }
            }

            switch ($this->cursor->state) {
                case self::STATE_ERROR:
                case self::STATE_COMPLETE:
                    return false;

                case self::STATE_FILE_ENTRY:
                    if (false === $this->read_file_entry()) {
                        return false;
                    }
                    break;

                case self::STATE_CENTRAL_DIRECTORY_ENTRY:
                    if (false === $this->read_central_directory_entry()) {
                        return false;
                    }
                    break;

                case self::STATE_END_CENTRAL_DIRECTORY_ENTRY:
                    if (false === $this->read_end_central_directory_entry()) {
                        return false;
                    }
                    break;

                default:
                    return false;
            }
        } while (self::STATE_SCAN === $this->cursor->state);

		return true;
	}

	private function read_central_directory_entry()
	{
		if ($this->cursor->header && !empty($this->cursor->header['path'])) {
			$this->cursor->header = null;
			$this->cursor->state = self::STATE_SCAN;
			return;
		}

		if (!$this->cursor->header) {
			$data = $this->consume_bytes(42);
			if ($data === false) {
				$this->paused_incomplete_input = true;
				return false;
			}
			$this->cursor->header = unpack(
				'vversionCreated/vversionNeeded/vgeneralPurpose/vcompressionMethod/vlastModifiedTime/vlastModifiedDate/Vcrc/VcompressedSize/VuncompressedSize/vpathLength/vextraLength/vfileCommentLength/vdiskNumber/vinternalAttributes/VexternalAttributes/VfirstByteAt',
				$data
			);
		}
		
		if($this->cursor->header) {
			$n = $this->cursor->header['pathLength'] + $this->cursor->header['extraLength'] + $this->cursor->header['fileCommentLength'];
			if (strlen($this->cursor->zip) < $this->cursor->bytes_parsed_so_far + $n) {
				$this->cursor->paused_incomplete_input = true;
				return false;
			}

			$this->cursor->header['path'] = $this->consume_bytes($this->cursor->header['pathLength']);
			$this->cursor->header['extra'] = $this->consume_bytes($this->cursor->header['extraLength']);
			$this->cursor->header['fileComment'] = $this->consume_bytes($this->cursor->header['fileCommentLength']);
			if(!$this->cursor->header['path']) {
				$this->set_error('Empty path in central directory entry');
			}
		}
	}

	private function read_end_central_directory_entry()
	{
		if ($this->cursor->header && ( !empty($this->cursor->header['comment']) || 0 === $this->cursor->header['commentLength'] )) {
			$this->cursor->header = null;
			$this->cursor->state = self::STATE_SCAN;
			return;
		}
		
		if(!$this->cursor->header) {
			$data = $this->consume_bytes(18);
			if ($data === false) {
				$this->cursor->paused_incomplete_input = true;
				return false;
			}
			$this->cursor->header = unpack(
				'vdiskNumber/vcentralDirectoryStartDisk/vnumberCentralDirectoryRecordsOnThisDisk/vnumberCentralDirectoryRecords/VcentralDirectorySize/VcentralDirectoryOffset/vcommentLength',
				$data
			);
		}
		
		if($this->cursor->header && empty($this->cursor->header['comment']) && $this->cursor->header['commentLength'] > 0) {
			$comment = $this->consume_bytes($this->cursor->header['commentLength']);
			if(false === $comment) {
				$this->cursor->paused_incomplete_input = true;
				return false;
			}
			$this->cursor->header['comment'] = $comment;
		}		
	}

	private function scan() {
		$signature = $this->consume_bytes(4);
		if ($signature === false) {
			$this->cursor->paused_incomplete_input = true;
			return false;
		}
		$signature = unpack('V', $signature)[1];
		switch($signature) {
			case self::SIGNATURE_FILE:
				$this->cursor->state = self::STATE_FILE_ENTRY;
				break;
			case self::SIGNATURE_CENTRAL_DIRECTORY:
				$this->cursor->state = self::STATE_CENTRAL_DIRECTORY_ENTRY;
				break;
			case self::SIGNATURE_CENTRAL_DIRECTORY_END:
				$this->cursor->state = self::STATE_END_CENTRAL_DIRECTORY_ENTRY;
				break;
			default:
				$this->set_error('Invalid signature ' . $signature);
				return false;
		}		
	}

	/**
	 * Reads a file entry from a zip file.
	 *
	 * The file entry is structured as follows:
	 *
	 * ```
	 * Offset    Bytes    Description
	 *   0        4    Local file header signature = 0x04034b50 (PK♥♦ or "PK\3\4")
	 *   4        2    Version needed to extract (minimum)
	 *   6        2    General purpose bit flag
	 *   8        2    Compression method; e.g. none = 0, DEFLATE = 8 (or "\0x08\0x00")
	 *   10        2    File last modification time
	 *   12        2    File last modification date
	 *   14        4    CRC-32 of uncompressed data
	 *   18        4    Compressed size (or 0xffffffff for ZIP64)
	 *   22        4    Uncompressed size (or 0xffffffff for ZIP64)
	 *   26        2    File name length (n)
	 *   28        2    Extra field length (m)
	 *   30        n    File name
	 *   30+n    m    Extra field
	 * ```
	 *
	 * @param resource $stream
	 */
	private function read_file_entry()
	{
		if (null === $this->cursor->header) {
            $data = $this->consume_bytes(26);
            if ($data === false) {
                $this->cursor->paused_incomplete_input = true;
                return false;
            }
            $this->cursor->header = unpack(
                'vversionNeeded/vgeneralPurpose/vcompressionMethod/vlastModifiedTime/vlastModifiedDate/Vcrc/VcompressedSize/VuncompressedSize/vpathLength/vextraLength',
                $data
            );
            $this->cursor->file_compressed_bytes_read_so_far = 0;
		}
		
		if($this->cursor->header && empty($this->cursor->header['path'])) {
            $n = $this->cursor->header['pathLength'] + $this->cursor->header['extraLength'];
            if(strlen($this->cursor->zip) < $this->cursor->bytes_parsed_so_far + $n) {
                $this->cursor->paused_incomplete_input = true;
                return false;
            }
    
            $this->cursor->header['path'] = $this->consume_bytes($this->cursor->header['pathLength']);
            $this->cursor->header['extra'] = $this->consume_bytes($this->cursor->header['extraLength']);
            if($this->cursor->header['compressionMethod'] === self::COMPRESSION_DEFLATE) {
                $this->inflate_handle = inflate_init(ZLIB_ENCODING_RAW);
            }
		}

		if(false === $this->read_file_entry_body_chunk()) {
			return false;
		}
	}

	private function read_file_entry_body_chunk() {
        $this->cursor->file_body_chunk = null;

		$file_body_bytes_left = $this->cursor->header['compressedSize'] - $this->cursor->file_compressed_bytes_read_so_far;
        if($file_body_bytes_left === 0) {
			$this->cursor->header = null;
			$this->inflate_handle = null;
			$this->cursor->file_compressed_bytes_read_so_far = 0;
			$this->cursor->state = self::STATE_SCAN;
			return;
		}

		if(strlen($this->cursor->zip) === $this->cursor->bytes_parsed_so_far) {
			$this->cursor->paused_incomplete_input = true;
			return false;
		}

		$chunk_size = min(8096, $file_body_bytes_left);
		$compressed_bytes = substr($this->cursor->zip, $this->cursor->bytes_parsed_so_far, $chunk_size);
		$this->cursor->bytes_parsed_so_far += strlen($compressed_bytes);
		$this->cursor->file_compressed_bytes_read_so_far += strlen($compressed_bytes);

		if ($this->cursor->header['compressionMethod'] === self::COMPRESSION_DEFLATE) {
			$uncompressed_bytes = inflate_add($this->inflate_handle, $compressed_bytes, ZLIB_PARTIAL_FLUSH);
			if($uncompressed_bytes) {
				$this->cursor->last_compressed_bytes = '';
			}
			$this->cursor->last_compressed_bytes .= $compressed_bytes;
			if ( $uncompressed_bytes === false || inflate_get_status( $this->inflate_handle ) === false ) {
				$this->set_error('Failed to inflate');
				return false;
			}
		} else {
			$uncompressed_bytes = $compressed_bytes;
		}

		$this->cursor->file_body_chunk = $uncompressed_bytes;
	}

	private function set_error($message) {
		$this->cursor->state = self::STATE_ERROR;
		$this->cursor->error_message = $message;
        $this->cursor->paused_incomplete_input = false;
	}

	private function consume_bytes($n) {
		if(strlen($this->cursor->zip) < $this->cursor->bytes_parsed_so_far + $n) {
			return false;
		}

		$bytes = substr($this->cursor->zip, $this->cursor->bytes_parsed_so_far, $n);
		$this->cursor->bytes_parsed_so_far += $n;
		return $bytes;
	}

}

if (RUN_ZIP_SMOKE_TEST) {
	for($i = 73; $i < 100; $i++) {
		$fp = fopen('./test.zip', 'r');
		$reader = new ZipStreamReader(fread($fp, $i));
		$reader->next();
		var_dump($reader->get_file_body_chunk());

		$state = $reader->pause();
		$reader2 = ZipStreamReader::resume($state);
		var_dump($reader2->get_file_body_chunk());
		$reader2->append_bytes(fread($fp, 100));
		var_dump($reader2->next());
		var_dump($reader2->get_file_body_chunk());
		// print_r($reader2->get_header());
		fclose($fp);
		die();
	}
	die();

}

if (RUN_ZIP_SMOKE_TEST) {
    $fp = fopen('./test.zip', 'r');
    $reader = new ZipStreamReader(fread($fp, 2048));
    while (true) {
        while ($reader->next()) {
            $header = $reader->get_header();
            echo "Reader state: " . $reader->get_state() . " ";
            switch ($reader->get_state()) {
                case ZipStreamReader::STATE_FILE_ENTRY:
                    echo $header['path'];
                    break;

                case ZipStreamReader::STATE_CENTRAL_DIRECTORY_ENTRY:
                    echo $header['path'];
                    break;

                case ZipStreamReader::STATE_END_CENTRAL_DIRECTORY_ENTRY:
                    echo 'End of central directory';
                    break;

                case ZipStreamReader::STATE_COMPLETE:
                    echo 'Complete';
                    break;
            }
            echo "\n";
        }
        if ($reader->is_paused_at_incomplete_input()) {
            if (feof($fp)) {
                break;
            }
            $reader->append_bytes(fread($fp, 1024));
        }
        if (ZipStreamReader::STATE_ERROR === $reader->get_state()) {
            echo 'Error: ' . $reader->get_last_error() . "\n";
            break;
        }
    }
    fclose($fp);
}
