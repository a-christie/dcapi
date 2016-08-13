<?php
/*
 *		Retrieve the blob config data
 */

namespace DCAPI;

class Config implements \ArrayAccess {
	public $container = [];		// allow access to the whole data structure

	public function __construct($options = array()) {
		$cachedir = '';
		$request = \DCAPI\CONFIGFILE;
		$blobCache = new \DCAPI\Cache('');

		$utils = new \DCAPI\Utils();
		$lastModified = $utils->getLastModified();

		date_default_timezone_set('UTC');
		$modified = strtotime($lastModified['latestBlob']);						// latest blob update used for checking staleness

		$page_prefix = '<undefined>';											// get page prefix (for use with home type blobs)
		$args = array(
			'post_type'   => 'blob',
			'sort_order'  => 'ASC',
			'sort_column' => 'menu_order',
			'post_status' => 'publish',
			'numberposts' => -1
		);
		$blob_posts = \get_posts($args);
		foreach ($blob_posts as $blob) {
			if ($blob->post_modified_gmt > $modified) $modified = $blob->post_modified_gmt;		// look up the latest blob modification time
			$blobType = \get_field('blob_type', $blob->ID);
			if ( ($page_prefix == '<undefined>') and ($blobType == 'page') ) $page_prefix = \get_field('prefix', $blob->ID);
		}

		// check cache
		if (in_array('regen', $options)) {
			$blobCache->delete_cache_file($cachedir, $request);
			$response = false;
		} else {
			$response = $blobCache->read_cache_file($cachedir, $request);
			if ($response == null) $response = false;
		}

		if ($response !== false) {										// cache found
			if ($response['meta']['lastModified'] == $modified) {				// lastModified is latest blob update time in cache
				$this->container = $response;
				return;
			}
			if ($response['meta']['cacheTimestamp'] > $modified) {		// not stale if cacheTimestamp is later than WP modification time
				$this->container = $response;
				return;
			} 
		}	

		$data = $this->rebuild($blob_posts, $page_prefix);			// build the response from back end Wordpress data

		// output cache file and return results
		$time = intval( (microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]) * 1000 );
		$blobCache->log_message( "regenerated '^blob' [" . $time . " ms]");
		$this->container = $blobCache->write_cache_file($cachedir, $request, $data, $modified);		// cache the data setting meta data
		return;
	}

	public function reconstruct($options = array()) {
		$this->__construct($options);
		return;
	}

	public function regen() {
		$this->__construct(['regen']);
	}

	public function rebuild(&$blob_posts, $page_prefix) {
		global $DCAPI_version;
		global $wp_version;
		$blobs = [];						// the blob data
		$routingMap = [];					// mapping from route string to blob
		$blobTypeMap = [];					// mapping from blobType to blob
		$postTypeMap = [];					// mapping from postType to blob
		$postTypePrefix = [];				// mapping from postType to prefix
		$IDMap = [];						// mapping from blob post ID to blob
		$backend = [];						// information about back end fields
		$taxonomies = [];
		$index = 0;

		$siteName = \get_field('site_name', 'option');
		$this->container['baseUrl'] = \get_field('base_url', 'option');
		$baseUrl = $this->base_url();							// derive base url if not provided by user
		$DCAPIUrl = plugins_url('/dcapi/');

		$commonFields = [];
		$commonFields['template'] = [];
		$commonFields['standard'] = [							// standard fields that are always processed
			['template' => '', 'function' => '(just copy)',
				'output_type' => 'normal', 'output_field' => 'hierarchy', 'output_to' => array( 'blob' ),
			],
			['template' => '', 'function' => '(just copy)', 
				'output_type' => 'normal', 'output_field' => 'termInfo', 'output_to' => array( 'blob' ),
			],
			['template' => '', 'function' => '(just copy)',
				'output_type' => 'normal', 'output_field' => 'feed/search', 'output_to' => array( 'blob' ),
			],
		];
		$commonFields['repeat'] = [];
		$commonFields['postInfo'] = [];
		$commonFields['termInfo'] = [];

		$fields = \get_field('fields', 'option');
		if ($fields) foreach ($fields as $field) {
			$ot = $field['output_type'];
			if ( ($ot == 'normal') or ($ot == 'hidden') ) {
				unset($field['back_end']);
				$commonFields['standard'][] = $field;						// the "usual" output types...
			} else if ($ot == 'back end') {
				$commonFields['standard'][] = $field;						// ... including 'back end' in this case!
			} else {
				unset($field['back_end']);
				$commonFields[$ot][] = $field;								// the other output types (repeat, postInfo, termInfo, template)
			}
		}

		$revealTo = \get_field('reveal_to', 'option');
		$hiddenCategories = \get_field('hidden_categories', 'option');
		if ($revealTo) {
			$revealTo = array_merge($reveal_to, array('Administrator'));		// always reveal to administrators			
		} else {
			$revealTo = array( 'Administrator' );								// always reveal to administrators			
		}
		$dst = \get_field('date_status_column_title', 'option');
		$inf = \get_field('info_column_title', 'option');
		if ( ($dst) or ($inf) )	{
			$backend['date_status_column_title'] = $dst;
			$backend['info_column_title'] = $inf;			
		}			

		$GUID = get_option('DCAPI_GUID');
		if (!$GUID) {								// force the creation of a GUID if mssing for some reason
			$dc = new \DCAPI\Cache('');
			$dc->generate_GUID(); 					// generate the site GUID
			unset($dc);
		}

		foreach ($blob_posts as $blobKey => $blob) {
			$ID = $blob->ID;
			$title = $blob->post_title;
			if ($blob->post_modified_gmt > $modified) $modified = $blob->post_modified_gmt;		// record the latest blob modification

			$blobType = \get_field('blob_type', $ID);
			$postType = \get_field('post_type', $ID);
			$tax = \get_field('taxonomy', $ID);
			if (is_array($tax)) $tax = current($tax);
			$prefix = \get_field('prefix', $ID);
			$style = null;
			$fixedID = null;
			$isCached = ( \get_field('is_cached', $ID) === false) ? false : true;
			$cacheKey = $blobType . '-' . $postType;

			$blob_fields = [];
			$blob_fields['template'] = [];
			$blob_fields['standard'] = [];
			$blob_fields['repeat'] = [];
			$blob_fields['clone'] = [];
			$fields = \get_field('fields', $ID);							// repeating field 'fields'
			if ($fields) foreach ($fields as $field) {
				$ot = $field['output_type'];
				if ( ($ot == 'normal') or ($ot == 'hidden') or ($ot == 'back end') ) {
					$blob_fields['standard'][] = $field;					// the "usual" output types
				} else {
					$blob_fields[$ot][] = $field;								// the other output types (repeat, postInfo, termInfo, template)
				}
			}

			$blobs[$index] = array(
				'ID' => $ID,						// possibly needed for accessing blob ACF fields
				'title' => $title,
				'blobType' => $blobType,
				'postType' => $postType,
				'lastModified' => $blob->post_modified_gmt,
				'taxonomy' => $tax,
				'fields' => $blob_fields,
				);

			$hasFeed = \get_field('has_feed', $ID);
			if ($hasFeed) {
				$blobs[$index]['hasFeed'] = true;
				$blobs[$index]['feedTypes'] = \get_field('feed_types', $ID);
				$blobs[$index]['exportFields'] = \get_field('export_fields', $ID);
			} else {
				$blobs[$index]['hasFeed'] = false;
			}

			switch ($blobType) {
				case 'clone':
					$sp = \get_field('source_path', $ID);
					$sb = \get_field('source_blob', $ID);
					$st = \get_field('source_term', $ID);
					$tt = \get_field('target_term', $ID);

					if ( ($sp == '') or ($sp == $DCAPIUrl) ) {							// it is a local clone
						$sp = '';														// force "local" in blob config data
					} else {
						try {
							$err = '';
							$file_data = @file_get_contents($sp . \DCAPI\CONFIGFILE);	// ignore the warning message that sometimes occurs
							$source_blob = json_decode($file_data, true);				// get the file contents
							if (!$source_blob['GUID']) $err = '(invalid DCAPI site)';	// check if GUID present
						} catch (Exception $e) {
							$err = '(inaccessible DCAPI site)';
						}
						if ($err) {
							$sp = $err;
							$sb = '';
							$st = '';
							$tt = '';
						}
						if ($source_blob['GUID'] == $GUID) $sp = '';					// override path if it is the local path!
					}
					$blobs[$index]['sourcePath'] = $sp;
					$blobs[$index]['sourceBlob'] = $sb;
					$blobs[$index]['sourceTerm'] = $st;
					$blobs[$index]['targetTerm'] = $tt;
					$blobs[$index]['repeatingField'] = \get_field('repeating_field', $ID);
					$style = 'ID';
					$cachedir = $prefix;
					$cachename = '*';				// filename = ID if this setting is "*"					
					$cacheKey = $blobType . '-' . $prefix;
					break;

				case 'post':
					$blobs[$index]['repeatingField'] = \get_field('repeating_field', $ID);
				case 'page':
					$style = 'ID';
					$cachedir = $prefix;
					$cachename = '*';				// filename = ID if this setting is "*"					
					$generateRoute = \get_field('generate_route', $ID);
					$routePrefix = \get_field('route_prefix', $ID);
					$routeStyle =  \get_field('route_style', $ID);			

					$acfDF = \get_field('acf_date_field', $ID);
					if ($acfDF) $blobs[$index]['acf_date_field'] = $acfDF;
					$acfTF = \get_field('acf_time_field', $ID);
					if ($acfTF) $blobs[$index]['acf_time_field'] = $acfTF;
					$updateAD = \get_field('update_acf_date', $ID);
					if ($updateAD) $blobs[$index]['update_acf_date'] = $updateAD;
					$updateAT = \get_field('update_acf_time', $ID);
					if ($updateAT) $blobs[$index]['update_acf_time'] = $updateAT;
					break;
				
				case 'home':
					$prefix = $page_prefix;
					$style = 'fixedID';
					$fixedID = '0';
					$postType = 'option';
					$cachedir = $prefix;
					$cachename = '0';				// filename = ID if this setting is "*"					
					$generateRoute = true;
					$routePrefix = \DCAPI\HOMEROUTE;
					$routeStyle = null;			
					break;

				case 'home-page':
					$prefix = $page_prefix;
					$style = 'fixedID';
					$fixedID = strval(\get_field('home_page', $ID));
					$postType = 'page';
					$isCached = false;					// ignore cache settings
					$hasFeed = false;					// ignore any feed settings
					$fields = null; 					// ignore any field settings
					$generateRoute = true;
					$routePrefix = \DCAPI\HOMEROUTE;
					$routeStyle = null;			
					break;

				case 'search':
					$style = 'search-string';
					$cachedir = $prefix;
					$cachename = '*';					// search string placeholder	
					$generateRoute = \get_field('generate_route', $ID);
					$routePrefix = \get_field('route_prefix', $ID);
					$routeStyle = 'search-string';			
					break;

				case 'config':
					$style = null;
					$cachedir = '';
					$cachename = $prefix;				// fixed filename			
					$generateRoute = false;
					break;

				default:
					$style = null;
					break;
			}

			$blobs[$index]['cacheKey'] = $cacheKey;

			if ($prefix) $blobs[$index]['prefix'] = $prefix;

			if ($generateRoute) {
				$blobs[$index]['generateRoute'] = true;
				$blobs[$index]['routePrefix'] = $routePrefix;
				$blobs[$index]['routeStyle'] = $routeStyle;			
			} else {
				$blobs[$index]['generateRoute'] = false;
			}

			$r = ( ($cachedir) ? $cachedir.'/' : '' ) . ( ($cachename != '*') ? $cachename : '');			// produce route maps to enable easy blob identification
			if ($r and !$routingMap[$r]) $routingMap[$r] = $index;										// only record the first entry if there are repeats
			if ($isCached) {	
				$blobs[$index]['isCached'] = true;
				$blobs[$index]['cachedir'] = $cachedir;
				$blobs[$index]['cachename'] = $cachename;
			} else {
				$blobs[$index]['isCached'] = false;
			}

			$blobs[$index]['style'] = $style;
			$blobs[$index]['fixedID'] = $fixedID;

			if ( ($blobType != 'post') and ($blobType != 'clone') and (!$blobTypeMap[$blobType]) ) {
				$blobTypeMap[$blobType] = $index;			// only record the first entry
			}
			if ( ($blobType != 'clone') and (!$postTypeMap[$postType]) and ($postType != 'option') ) {
				$postTypeMap[$postType] = $index;										// only record the first entry and don't record "option"
				$postTypePrefix[$postType] = $blobs[$index]['prefix'];
			}
			$IDMap[$ID] = $index;															// mapping from blob post ID to blob data
			if (($tax) and (!in_array($tax, $taxonomies))) $taxonomies = array_merge(array($tax), $taxonomies);
			$index++;
		}

		$acf = "<ACF not found>";
		if ( function_exists('acf') ) {
			$a = acf();
			$n = $a->settings['name'];
			$v = $a->settings['version'];
			$acf = "$n, V$v";
		}
		$searchType = (function_exists('relevanssi_do_query')) ? 'Relevanssi' : 'Wordpress' ;
		$timeoutLimit = \get_field('timeout_limit', 'option');
		$cronUpdate = \get_field('cron_update', 'option');

		$data = array(								// return a structured array of blob data
			'meta' => [],							// placeholder
			'siteName' => $siteName,
			'baseUrl' => $baseUrl,
			'localTimezoneString' => get_option('timezone_string'),
			'localTimezoneOffset' => get_option('gmt_offset'),
			'DCAPIUrl' => $DCAPIUrl,
			'DCAPIVersion' => $DCAPI_version,		// use the global
			'GUID' => get_option('DCAPI_GUID'),
			'PHPVersion' => phpversion(),
			'WordpressVersion' => $wp_version,		// use the global
			'ACFVersion' => $acf,
			'searchType' => $searchType,
			'timeoutLimit' => ($timeoutLimit) ? $timeoutLimit : 20,				// default 20 seconds
			'cronUpdate' => ($cronUpdate) ? true : false,						// true => cron job used to update entries
			'taxonomies' => $taxonomies, 			// all referenced taxonomies
			'routingMap' => $routingMap,
			'blobTypeMap' => $blobTypeMap,
			'postTypeMap' => $postTypeMap,
			'postTypePrefix' => $postTypePrefix,
			'IDMap' => $IDMap,
			'commonFields' => $commonFields,
			'revealTo' => $reveal_to,
			'hiddenCategories' => $hiddenCategories,
			'blobs' => $blobs,
			'backend' => $backend,
			);
		return $data;
	}

	public function base_url() {
		if ($baseUrl == '') $baseUrl = $this->container['baseUrl'];			// try this first
		if ($baseUrl == '') {
			$s = get_site_url();
			if (substr($s, -10) == '/wordpress') {
				$baseUrl = substr($s, 0 ,-10) . '/';
			} else if (substr($s, -11) == '/wordpress/') {
				$baseUrl = substr($s, 0 ,-11) . '/';
			} else {
				$baseUrl = $s;
			}
		}
		if (substr($baseUrl, -1) != '/') {
			$baseUrl .= '/';
		}
		return $baseUrl;
	}

	private function guidv4() {
	    $data = openssl_random_pseudo_bytes(16);
	    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
	    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
	    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	public function offsetSet($offset, $value) {
        if (is_null($offset)) {
            $this->container[] = $value;
        } else {
            $this->container[$offset] = $value;
        }
    }

    public function offsetExists($offset) {
        return isset($this->container[$offset]);
    }

    public function offsetUnset($offset) {
        unset($this->container[$offset]);
    }

    public function offsetGet($offset) {
        return isset($this->container[$offset]) ? $this->container[$offset] : null;
    }
}

?>