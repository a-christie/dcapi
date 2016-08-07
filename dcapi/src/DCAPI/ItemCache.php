<?php
/*
 *		Handle item cache files
 *			builds a directory of files with the given name:
 *			- the file all.json (stored in the standard blobCache directory) contains all individual items concatenated (name parametrised)
 *			- the individual item files are named by ID.json
 *			the memory structure behaves like an array of all items
 */

namespace DCAPI;

class ItemCache implements \ArrayAccess {
	private $container = [];		// no direct access to the whole blob config data
	private $cache;
	private $blobCache;
	private $dir;
	private $all;


	public function __construct($cache, $dir = '', $all = false) {			// $cache = cache name / $dir = prefix (subdirectory) / $all if true, then a ^feed file is generated with all items in it
		$this->dir = $dir;
		if ( ($cache == '') or ($cache == 'blobCache') ) $cache = 'itemCache';
		$this->cache = new \DCAPI\Cache($cache);
		$this->blobCache = new \DCAPI\Cache('');							// used for accessing "all" file (stored in blobCache)
		$this->all = ($all) ? \DCAPI\FEED_ITEMS : '';
		return;
	}
	
	public function set_item($ID, $data) {
		$cache_mtime = $this->cache->cache_file_mtime($this->dir, $ID);
		if (isset($this->container[$ID])) {							
			$temp = $this->container[$ID];
			unset($temp['meta']['cacheTimestamp']);
			unset($data['meta']['cacheTimestamp']);
			if ($temp == $data) {								// don't bother to write out if the data is unchanged!
				return $this->container[$ID];
			}
		}
		$this->container[$ID] = $this->cache->write_cache_file($this->dir, $ID, $data);	// cache the data and give it a timestamp
		return $this->container[$ID];
	}

	public function get_item($ID) {
		$cache_mtime = $this->cache->cache_file_mtime($this->dir, $ID);
		$local_copy = isset($this->container[$ID]);
		if ($cache_mtime === false) {							// no file -> error
			if ($local_copy) unset($this->container[$ID]);		// remove incorrect memory copy!
			return false;			
		}
		if ( (!$local_copy) or ($this->container[$ID]['meta']['cacheTimestamp'] != $cache_mtime)) {
			$this->container[$ID] = $this->cache->read_cache_file($this->dir, $ID);		// need to read the file in
		}
		return $this->container[$ID];							// return the data
	}

	public function get_all() {
		if (!$this->all) return false;

		$cachedir_mtime = $this->cache->cache_file_mtime($this->dir, '');		// modification time of whole itemCache directory
		if ( ($this->container) and ($this->container['meta']['cacheTimestamp'] >= $cachedir_mtime)) {
			return $this->container;
		}
		$cacheall_mtime = $this->blobCache->cache_file_mtime($this->dir, $this->all);		// modification time of the cached contents
		if ( $cacheall_mtime >= $cachedir_mtime ) {
			$this->container = $this->blobCache->read_cache_file($this->dir, $this->all);	// need to read the file in
			return $this->container;
		}
		// need to rebuild the whole list
		$this->container = [];
		$list = $this->cache->list_cache_directory($this->dir);
		foreach ($list as $ID => $ts) {
			if ($ID === $this->all) continue;
			$this->container[$ID] = $this->get_item($ID);
		}	
		$this->container = $this->blobCache->write_cache_file($this->dir, $this->all, $this->container);	// cache the data and give it a timestamp
		return $this->container;
	}

	public function unset_all() {
		$this->container = [];
		return $this->cache->empty_cache_directory($this->dir);			// clear the directory and local copy
	}

	public function offsetSet($offset, $value) {
        if (is_null($offset)) {
            $this->set_item(0, $value);
        } else {
            $this->set_item($offset, $value);
        }
    }

    public function offsetExists($offset) {
        return ($this->get_item[$offset] !== false);
    }

    public function offsetUnset($offset) {
        unset($this->container[$offset]);
        $this->cache->delete_cache_file($this->dir, $offset);
    }

    public function offsetGet($offset) {
        return $this->get_item($offset);
    }
}

?>