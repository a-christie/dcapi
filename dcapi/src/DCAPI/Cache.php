<?php
/*
 *		Routines for handling the DCAPI cache (NB: special behavior for directory "^backup")
 */

namespace DCAPI;

class Cache {
	private $base;										// cache base directory
	private $backup;									// true if reading and writing to "backup" (special case - this directory is outside the normal cache directory)

	public function __construct($base) {
		if (!$base) $base = 'blobCache';
		$this->base = $base;
		$this->backup = ($base == 'backup') ? true : false;
	}

	public function construct_filename($dir, $name, $forceBlobCache = false) {		// NB: no slashes in directory or file name...
		$cachePath = dirname(dirname( __FILE__ )) . "/../cache/";					// - ends with "/" - main cache directory at same level as src directory
		if ($forceBlobCache) {
			$path = $cachePath . "blobCache/";						// force into blobCache - ends with "/" - cache directory at same level as lib directory
		} else {
			$path = $cachePath . "$this->base/";					// - ends with "/" - cache directory at same level as lib directory
		}
		if ($this->backup) {
			$path = dirname(dirname( __FILE__ )) . "/../backup/";					// - ends with "/" - backup directory at same level as cache and src directories
		}
		clearstatcache();
		if (!file_exists(realpath($cachePath)) && !is_dir($cachePath)) { $x = mkdir($cachePath); }	// create directory as side-effect if missing     
		if (!file_exists(realpath($path)) && !is_dir($path)) { $x = mkdir($path); }					// create directory as side-effect if missing     
		if ($name === '') {		// directory
			return $path . $dir;
		} else {				// file
			if ($dir === '') {
				return $path . $name . '.json';
			} else {
				return $path . $dir . '/' . $name . '.json';
			}
		}
	}

	public function cache_file_mtime($dir, $name) {			// get modified time of file called "$name.json" in the cache directory (false if not found)
		$fn = $this->construct_filename($dir, $name);
		if (file_exists(realpath($fn))) {
			return filemtime($fn);							// last modified time
		} else {
	  	  	return false;									// file not found
		}
	}

	public function read_cache_file($dir, $name) {			// read file called "$dir/$name.json" in the cache directory 
		if ($name === '') return;
		$fn = $this->construct_filename($dir, $name);
		if (file_exists(realpath($fn))) {
			try {
				$str_data = @file_get_contents($fn);		// ignore the warning message that sometimes occurs				
			} catch (Exception $e) {
				return false;
			}
			return json_decode($str_data, true);			// return the file contents
		} else {
	  	  	return false;									// file not found
		}
	}

	public function write_cache_file($dir, $name, $data, $post_modified = null) {	// write file called "$name.json" into the cache directory
		if ($name === '') return;
		if ($dir != '') {
			$path = $this->construct_filename($dir, '');						// add directory if necessary
			if (!file_exists(realpath($path)) && !is_dir($path)) { $x = mkdir($path); }	// create directory as side-effect if missing
			$route = "$dir/$name";   
		} else {
			$route = $name;   
		}
		if ($this->base != 'blobCache') $route = $this->base . '/' . $route;
		$fn = $this->construct_filename($dir, $name);
		$fh = fopen($fn, 'w') or wp_die("Error opening output file $fn");

		$cacheTimestamp = intval(date('U', time()));
		// add meta data
		$data['meta']['self'] = plugins_url("/dcapi/$route");																	// self route
		$data['meta']['copyright'] = '\u00A9 ' . date('Y', time()) . ', ' . get_option('blogname') . '. Authorized use only';
		if ($post_modified) $data['meta']['lastModified'] = $post_modified;														// Wordpress post modified timestamp (if provided)
		$data['meta']['cacheTimestamp'] = $cacheTimestamp;																		// set cache timestamp on file
		fwrite($fh, json_encode($data, JSON_UNESCAPED_UNICODE));
		fclose($fh);

		touch($fn, $cacheTimestamp);											// make modified time consistent!
		touch($this->construct_filename('', \DCAPI\TOUCHFILE), $cacheTimestamp);		// touch file (timestamp cache updates)
		return $data;
	}

	public function log_message($data) {										// log to file called "^log.json" in the cache directory (append timestamp, $data, EOL)
		$fn = $this->construct_filename('', \DCAPI\LOGFILE);
		$cacheTimestamp = intval(date('U', time()));
		$output = $cacheTimestamp . ': ' . $data . PHP_EOL;
		if (!file_exists(realpath($fn))) {
			$fh = fopen($fn, 'w') or wp_die("Error opening output file $fn");
			fwrite($fh, "Start of log" . PHP_EOL . $output);
			fclose($fh);
			clearstatcache();
		} else {
			file_put_contents($fn, $output, FILE_APPEND | LOCK_EX);			
		}
		return;
	}

	public function list_log($lines = 1, $adaptive = true) {					// return the last "$lines" of the log file (see http://stackoverflow.com/questions/15025875/what-is-the-best-way-in-php-to-read-last-lines-from-a-file - thanks Lorenzo)
		// Open file
		$fn = $this->construct_filename('', \DCAPI\LOGFILE);
		$f = @fopen($fn, "rb");
		if ($f === false) return false;
		// Sets buffer size
		if (!$adaptive) $buffer = 4096;
		else $buffer = ($lines < 2 ? 64 : ($lines < 10 ? 512 : 4096));
		// Jump to last character
		fseek($f, -1, SEEK_END);
		// Read it and adjust line number if necessary
		// (Otherwise the result would be wrong if file doesn't end with a blank line)
		if (fread($f, 1) != "\n") $lines -= 1;
		
		// Start reading
		$output = '';
		$chunk = '';
		// While we would like more
		while (ftell($f) > 0 && $lines >= 0) {
			// Figure out how far back we should jump
			$seek = min(ftell($f), $buffer);
			// Do the jump (backwards, relative to where we are)
			fseek($f, -$seek, SEEK_CUR);
			// Read a chunk and prepend it to our output
			$output = ($chunk = fread($f, $seek)) . $output;
			// Jump back to where we started reading
			fseek($f, -mb_strlen($chunk, '8bit'), SEEK_CUR);
			// Decrease our line counter
			$lines -= substr_count($chunk, "\n");
		}
		// While we have too many lines
		// (Because of buffer size we might have read too many)
		while ($lines++ < 0) {
			// Find first newline and remove all text before that
			$output = substr($output, strpos($output, "\n") + 1);
		}
		// Close file and return
		fclose($f);
		if (substr($output, 0, 12) == 'Start of log') {
			return explode("\n", trim($output));
		} else {
			return explode("\n", "...\n" . trim($output));
		}
	}

	public function is_regen_queue_empty() {
		$fn = $this->construct_filename('', \DCAPI\QUEUEEMPTYFILE, true);
		if (file_exists(realpath($fn))) {
			return true;					// file present signals queue is now empty
		} else {
	  	  	return false;					// file missing signals queue has items
		}
	}

	public function set_regen_queue_empty() {
		touch( $this->construct_filename('', \DCAPI\QUEUEEMPTYFILE, true) );
	}

	public function set_regen_queue_nonempty() {
		$this->delete_cache_file('', \DCAPI\QUEUEEMPTYFILE, true);
	}

	public function queue_for_regen($dir, $name) {	// set the modification time/date of file called "$name.json" in the cache directory to the start of Unix time + 1 (signals queued for regen)
		if ($name === '') return;
		if ($dir != '') {
			$path = $this->construct_filename($dir, '');								// add directory if necessary
			if (!file_exists(realpath($path)) && !is_dir($path)) { $x = mkdir($path); }	// create directory as side-effect if missing
		}
		$fn = $this->construct_filename($dir, $name);	
		touch($fn, 1);								// make modified time the start of Unix time (plus 1 second to avoid falsy value!)
		$this->delete_cache_file('', \DCAPI\QUEUEEMPTYFILE, true);
		if (file_exists(realpath($fn))) {
			return true;
		} else {
	  	  	return false;							// file originally not found
		}
	}

	public function delete_cache_file($dir, $name, $forceBlobCache = false) {		// delete file called "$name.json" in the cache directory
		if ($name === '') return;
		$fn = $this->construct_filename($dir, $name, $forceBlobCache);
		if (file_exists(realpath($fn))) {
			$str_data = unlink($fn);				// delete file
			return true;
		} else {
	  	  	return false;							// file not found
		}
	}

	private function recursiveDelete($str) {
	    if (is_file($str)) {
	        return @unlink($str);
	    }
	    elseif (is_dir($str)) {
	        $scan = glob(rtrim($str,'/').'/*');
	        foreach($scan as $index=>$path) {
	            $this->recursiveDelete($path);
	        }
	        return @rmdir($str);
	    }
	}

	public function empty_cache_directory($dir, $skipFile = '') {			// delete all files and directories in the given cache directory, optionally except $skipFile
		$path = $this->construct_filename($dir, '') . ( ($dir == '') ? '' : '/' );
		if ($handle = opendir($path)) {
		    while (false !== ($file = readdir($handle))) {
		    	if ( ($skipFile) and ($file == ($skipFile.'.json')) ) continue;			// don't delete file called $skipFile.json
		        if ($file != "." && $file != "..") { 		// strip the current and previous directory items
		        	$this->recursiveDelete($path . $file);
		        }
		    }
		    closedir($handle);
			return true;
		} else {
	  	  	return false;									// file not found
		}
	}

	public function list_cache_directory($dir) {			// list all files and directories in the defined cache directory
		$response = [];
		$path = $this->construct_filename($dir, '') . ( ($dir == '') ? '' : '/' );
		if ($handle = opendir($path)) {
		    while (false !== ($file = readdir($handle))) {
		        if ($file != "." && $file != "..") { 		// strip the current and previous directory items
		        	$pos = strpos($file, '.json');
		        	$str = $path . $file;
		        	if ($pos !== false) $response[substr($file, 0, $pos)] = filemtime($str);
		        	$len = strlen($path);
				    if (is_dir($str)) {
				        $scan = glob(rtrim($str,'/').'/*');
				        foreach($scan as $index => $fpath) {
				        	$f = substr($fpath, $len);
				        	$pos = strpos($f, '.json');
		        			if ($pos !== false) $response[substr($f, 0, $pos)] = filemtime($fpath);
				        }
				    }
		        }
		    }
		    closedir($handle);
		    ksort($response);
			return $response;
		} else {
	  	  	return false;							// file not found
		}
	}

	public function generate_GUID() {
		$data = openssl_random_pseudo_bytes(16);
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
		$GUID = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
		update_option('DCAPI_GUID', $GUID);										// setting the GUID in options signals that DCAPI has been installed
	}
}

?>