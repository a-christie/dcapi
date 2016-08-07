<?php
/*
 *		Retrieve the blob data
 */

namespace DCAPI;

class Blob implements \ArrayAccess {
	public $container = [];		// allow direct access to the whole blob config data

	public function __construct($input) {
		global $DCAPI_blob_config;
		global $DCAPI_index;
		if (!$input) {
			$this->container = [];
			return;
		}

		$options = [];
		preg_match("/^([\^\w\*][\w-\/\^\*]*)(\?(\w[\w=\/]*(\;\w[\w=\/]*)*))?$/", $input, $m);	// allow for a request of the form "post/*?option1;option2"
		if ($m) {
			$request = $m[1];
			$options = explode(";", $m[3]);
		} else {
			$request = $input;					// no options
			$options = [];
		}

		if ($request == "*") {												// iterate all items
			$this->container = $this->process_all($request, $options);		// prefix missing means match all blobs (including ^blob and ^index)

		} else {
			$blob = $this->parse_route($request, $prefix, $ID, $post);
			if ($ID == '*') {													// iterate all items with the same prefix
				$this->container = $this->process_all($request, $options);		// specific prefix only
			} else {
				$this->container = $this->regen_single_blob($request, $options, $blob, $post, $prefix, $ID, true); 		// process a single item
			}
		}
	}

	private function parse_route($request, &$prefix, &$ID, &$post) {	// parse an internal route to get prefix, post id and potentially post
		global $DCAPI_blob_config;
		global $DCAPI_index;
		$blob = false;
		$post = null;
		$prefix = '';

		$p = strpos($request, '/');										// slash can't be the first character
		if ($p) {
			$r = substr($request, 0, $p+1);								// look for match of prefix with trailing '/'
			$ID = substr($request, $p+1);
		}

		$blobIndex = (isset($DCAPI_blob_config['routingMap'][$request])) ? $DCAPI_blob_config['routingMap'][$request] : null;		// try to find the route directly
		if ($blobIndex === null) {										// not an explicit route such as 'page/0'
			if ($p) {
				$blobIndex = (isset($DCAPI_blob_config['routingMap'][$r])) ? $DCAPI_blob_config['routingMap'][$r] : null;			// try to find the route prefix		
				if ($blobIndex === null) {
					return null;										// no blob index matched
				}	
			} else {
				return null;											// no blob index matched
			}
		}

		$blob = $DCAPI_blob_config['blobs'][$blobIndex];
		if ($blob) {
			$prefix = $blob['prefix'];
			if ( ($ID != '*') and ($ID != \DCAPI\FEED_ITEMS) ) {
				$post = null;
				if ($blob['blobType'] == 'clone') {
					if (!isset($DCAPI_index['postInfo'][$request])) return null;
				} else if ($blob['postType'] != 'option') {
					$post = get_post($ID);
					if ($post->post_type != $blob['postType']) {
						$post = null;
						return null;									// incorrect post type
					}
				}
			}
		}
		return $blob;
	}

	public function reconstruct($input) {
		$this->container = [];
		$this->__construct($input);
		return $this->container;
	}

	private function getFileInfo(&$canonicalList, $cache_dir, $cached = true, $dir = null) {		// NB: directory names should have trailing slashes
		clearstatcache();
		if ($dir === null) $dir = $cache_dir;
		if ($dir == '/') return;										// do nothing if directory doesn't exist!

		// open pointer to directory and read list of files
		$d = @dir($dir) or die("getFileInfo: Failed opening directory $dir for reading");
		while (false !== ($entry = $d->read())) {
			// skip hidden files
			if ($entry[0] == ".") continue;
			$fn = "$dir$entry";
			if (is_dir($fn)) {
				if (is_readable("$dir$entry/")) {
					$this->getFileInfo($canonicalList, $cache_dir, $cached, "$dir$entry/");
				}
			} else if (is_readable($fn)) {
				$route = substr(explode($cache_dir, $fn)[1], 0, -5);		// strip off trailing '.json'
				if ($canonicalList[$route]) {
					if ( ($cached and $canonicalList[$route]['isCached']) or (!$cached and !$canonicalList[$route]['isCached']) ) {
						$canonicalList[$route]['fileSize'] = filesize("$dir$entry");
						$canonicalList[$route]['fileModified'] = filemtime("$dir$entry");						
					}
				} else if ( ($route != \DCAPI\TOUCHFILE) and ($route != \DCAPI\LOGFILE) and ($route != \DCAPI\QUEUEEMPTYFILE) ) {
					$canonicalList[$route] = [								// unmatched file - should be deleted (except for special signalling files)
						'toDo' => true,
						'fileToDelete' => $fn,
						];
				}
			}
		}
		$d->close();
		return $response;
	}

	public function regen_all($options, $background = false) {
		$this->container = $this->process_all('*', $options, $background);
	}

	private function output_progress($tag, $string, $background) {
		$dc = new \DCAPI\Cache('');
		$dc->log_message("$tag $string [" . $this->time() . " ms]");
		return $string;
	}

	private function time() {
		return intval( (microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]) * 1000 );
	}

	private function process_all($request, $options, $background = false) {					// $background set true forces output to the ^log file
		global $DCAPI_blob_config;
		global $DCAPI_index;
		
		$fullAudit = [];
		$bc = new \DCAPI\Cache('');
		$ic = new \DCAPI\Cache('itemCache');
		$opt = implode(";", $options);
		$req = '.../dcapi/' . $request . ( $opt ? "?$opt" : '');
		$prefix = ($request == '*') ? '' : substr($request, 0, strpos($request, '/'));
		date_default_timezone_set(get_option('timezone_string'));
		$tag = date('Y-m-d H:i:s') . ($background ? ' B' : ' F');							// a tag for this run of process_all (in local time)
		if ($background) {
			$timeLimit = (ini_get('max_execution_time') * 0.95) * 1000;						// "background" timeout limit (less 5%) in ms
			if ($timeLimit > 40000) $timeLimit = 40000;										// copout: mod_fcgid is set to 40 s
		} else {
			$timeLimit = \get_field('timeout_limit', 'option') * 1000;						// "foreground" timeout limit in ms
		}

		// routine starts here
		if ( ($prefix === '') and (in_array('regen', $options)) and (in_array('do', $options)) ) {	
			$bc->set_regen_queue_nonempty();			  	// ensure if full regen being done that it runs to completion
		}

		if (!in_array('clear', $options)) {
			$fullAudit[] = $this->output_progress($tag, "*** starting '$req'", $background);
		} else {																			// clear all or part of cache
			if ($prefix === '') {
				$bc->empty_cache_directory('');	 											// clear out the cache directory
				$fullAudit[] = $this->output_progress($tag, "*** starting '$req'", $background);			// don't let the log file get deleted with the first line in it!
				$ic->empty_cache_directory('');												// clear out the item cache directory
				$fc = new \DCAPI\Cache('fieldsCache');
				$fc->empty_cache_directory('');												// clear out the field transformation directory
				$fullAudit[] = $this->output_progress($tag, 'cache directories cleared', $background);
				$DCAPI_blob_config = new \DCAPI\Config();									// allways regenerate ^blob and ^index (only) when clearing the cache
				$fullAudit[] = $this->output_progress($tag, \DCAPI\CONFIGFILE . ' regenerated', $background);
				$DCAPI_index = new \DCAPI\Index();
				$fullAudit[] = $this->output_progress($tag, \DCAPI\INDEXFILE . ' regenerated', $background);
			} else {
				$fullAudit[] = $this->output_progress($tag, "*** starting '$req'", $background);
				$response = $bc->empty_cache_directory($prefix);							// clear out the cache (sub)directory
				$response = $ic->empty_cache_directory($prefix);							// clear out the item cache (sub)directory
				$fullAudit[] = $this->output_progress($tag, "'$prefix' cache subdirectories cleared [", $background);
			}
		}

		$blobCache_dir = realpath(__DIR__ . '/../../cache/blobCache') . '/';
		$itemCache_dir = realpath(__DIR__ . '/../../cache/itemCache') . '/';
		date_default_timezone_set('UTC');

		$canonicalList = [];
		$allLastModified = 1;

		foreach ($DCAPI_index['regenRoutes'] as $route => $pid) {
			$pi = $DCAPI_index['postInfo'][$pid];
			$isCached = $pi['isCached'] ? true : false;
			$lastModified = strtotime($pi['lastModified']);					// lastModified for current item
			$toDo = ($prefix === '') ? true : ( (strpos($route, "$prefix/") === 0) ? true : false );
			$canonicalList[$route] = [
				'isCached' => $isCached,
				'lastModified' => $lastModified,
				];
			if ($toDo) $canonicalList[$route]['toDo'] = $toDo;
			if ($pi['sourceRoute'])	{
				$canonicalList[$route]['sourceRoute'] = $pi['sourceRoute'];
				if ($toDo) $canonicalList[ $pi['sourceRoute'] ]['toDo'] = $toDo;		// force source of clone to be done too
			}		
		}
	
		if (!in_array('force', $options)) {
			$this->getFileInfo($canonicalList, $itemCache_dir, false);					// add file information from item cache (for non cached items) 
			$this->getFileInfo($canonicalList, $blobCache_dir);							// add file information from cache			
		}

		foreach ($canonicalList as $key => $value) {		// scan through in canonical order...
			if (!$value['toDo']) continue;					// skip unwanted items
			$p = strpos($key, '/');
			if ($p) {
				$dir = substr($key, 0, $p);
				$name = substr($key, $p+1);
			} else {
				$dir = '';
				$name = $key;
			}

			if ($value['fileToDelete']) {
				$bc->delete_cache_file($dir, $name);
				$ic->delete_cache_file($dir, $name);
				$fullAudit[] = $this->output_progress($tag, "'$key' deleted", $background);
				continue;
			}

			$reason = '';
			if (in_array('force', $options)) {
				$reason = 'forced regen';			// regeneration queued via ?regen
			} else if (!$value['fileModified']) {
				$reason = 'file missing';			// file missing
			} else if ($value['fileModified'] == 1) {
				$reason = 'regen queued';			// regeneration has been queued already
			} else if ($value['fileModified'] < $value['lastModified']) {
				$reason = 'cache stale';			// file present but stale (older than WP item)
			} else if ($value['fileSize'] == 0) {
				$reason = 'file empty';				// file found but empty
			} else {
				if ($value['sourceRoute']) {				// look at the original (in the case of clones)
					$a = $actions[$value['sourceRoute']];	// should have been set already (earlier in the canonical list)
					if ($a) $reason = $a;				
				}
			}

			if (!in_array('regen', $options)) {
				if (in_array('quiet', $options))  {
					if ( (in_array('clear', $options)) and ($reason == 'file missing') ) {
						$reason = '';
					}			
				} else {
					if (!$reason) {
						$reason = 'up to date';
					}				
				}
			}

			if ($reason) {
				$time = $this->time();		// current execution time in ms
				if ($time > $timeLimit) {
					$fullAudit[] = $this->output_progress($tag, "*** timed out '$req'", $background);
					return ($background) ? null : $fullAudit;
				}

				if (in_array('regen', $options)) {					// regenerate
					if (in_array('do', $options)) {						// do processing
						$blob = $this->parse_route($key, $pf, $ID, $post);
						$this->regen_single_blob($key, ['regen'], $blob, $post, $pf, $ID);
						$fullAudit[] = $this->output_progress($tag, "'$key' regenerated", $background);

					} else {											// only queue for processing
						if ($value['isCached']) {
							$bc->queue_for_regen($dir, $name);			
						} else {
							$ic->queue_for_regen($dir, $name, true);	
						}
						$fullAudit[] = $this->output_progress($tag, "'$key' queued ($reason)", $background);
					}
				} else {											// just show status
					$fullAudit[] = $this->output_progress($tag, "'$key' status is '$reason'", $background);
				}
			}
		}

		if ( ($prefix === '') and (in_array('regen', $options)) and (in_array('do', $options)) ) {	
			$bc->set_regen_queue_empty();			  	// only signal nothing to regen if full regen being done and it is successful
		}

		if (count($fullAudit) == 1) {								// only the start message was output
			$fullAudit[] = $this->output_progress($tag, "no changes made", $background);
		}
		$fullAudit[] = $this->output_progress($tag, "*** finished '$req'", $background);
		return ($background) ? null : $fullAudit;
	}

	private function process_fields($blob, $post, $request, &$out, &$backend) {
		global $DCAPI_blob_config;		
		global $DCAPI_index;		
		global $DCAPI_transform;
		global $DCAPI_fields;
		global $DCAPI_items;

		$repeatingFieldName = $blob['repeatingField'];
		$cacheKey = $blob['cacheKey'];
		$utils = new \DCAPI\Utils();

		// build preprocessed copy of fields table with handlebars template
		$transform = $DCAPI_fields[$cacheKey];		// try to read in the cached version

		if ($transform === false) {					// need to generate the data
			$template = new \DCAPI\Handlebars();
			$templateFields = [];
			$fields = [];
			$transform = [ 							// placeholders
				'meta' => [],
				'fields' => [], 
				'partials' => [],
				];

			if ($blob['blobType'] != 'clone') {
				$commonFields = $DCAPI_blob_config['commonFields'];						// these fields are always processed - (from options)
				$templateFields = array_merge($commonFields['template'], $blob['fields']['template']);
				$fields = array_merge($commonFields['standard'], $blob['fields']['standard'], $commonFields['repeat'], $blob['fields']['repeat']);
			} else {
				$templateFields = $blob['fields']['template'];
				$fields = array_merge($blob['fields']['clone'], $blob['fields']['standard'], $blob['fields']['repeat']);
			}

			// take care of the templates
			foreach ($templateFields as $field) {
				$x = $field['template'];
				if ( (substr($x, 0, 3) == "<p>") and (substr($x, -5) == "</p>\n") ) $x = substr($x, 3, -5);
				$tpl = str_replace("<br />\n", "\n", $x);
				$o = $field['output_field'];
				$template->registerPartial($o, $tpl);		// register a partial template
			}
			$transform['partials'] = $template->save_partials();

			foreach ($fields as $fk => $field) {
				$x = $field['template'];
				if ( (substr($x, 0, 3) == "<p>") and (substr($x, -5) == "</p>\n") ) $x = substr($x, 3, -5);
				$tpl = str_replace("<br />\n", "\n", $x);
				if ($tpl) {
					if ( ($field['output_type'] == 'repeat') and ($repeatingFieldName) ) {
						$template->compile( "{{#each out.$repeatingFieldName 'cloneValue' ~}}" . $tpl . '{{~/each}}' );
					} else {
						$template->compile($tpl);
					}
					$fields[$fk]['code'] = $template->save_code();
				} else {			
					$fields[$fk]['code'] = null;										// no handlebars template
				}
			}
			$transform['fields'] = $fields;

			$DCAPI_fields[$cacheKey] = $transform;							// store away in the cache

		} else {
			$template = new \DCAPI\Handlebars($transform['partials']);		// retrieve from the cache
			$fields = $transform['fields'];
		}

		// now carry out the processing of the fields - first, set up the context data
		date_default_timezone_set(get_option('timezone_string'));
		$info_sep = '';
		$date_status_sep = '';

		$b = $blob;							// copy for context
		unset($b['fields']);
		unset($b['feedTypes']);
		unset($b['exportFields']);

		$context = [
			'request' => $request,													// the incoming request
			'post' => (array) $post, 												// the incoming post
			'blob' => $b,															// the incoming blob (abridged)
			'baseUrl' => $DCAPI_blob_config['baseUrl'],								// the base_url for external routes
			'locale' => get_locale(),												// the locale as defined in Wordpress
			'out' => [],															// output array 
			];

		if ($blob['blobType'] == 'page') {
			$p = $this->get_pageInfo($post->ID);
			$p['parentPage'] = $this->get_pageInfo($p['parentPage']);
			unset($p['parentPage']['parentPage']);
			unset($p['parentPage']['childPages']);
			$q = $p['childPages'];
			if (!empty($q)) {
				$p['childPages'] = [];
				foreach ($q as $v) {
					$c = $this->get_pageInfo($v);
					unset($c['parentPage']);
					unset($c['childPages']);
					$p['childPages'][] = $c;
				}
			}
			$context['acf'] = ($post->ID) ? \get_fields($post->ID) : \get_fields('option');	// the incoming ACF fields
			$context['postInfo'] = $p;														// page information for this page

		} else if ($blob['blobType'] == 'post') {
			$terms = wp_get_post_terms($post->ID, $blob['taxonomy'], ['fields' => 'ids'] );
			$ti = [];
			foreach ($terms as $v) {
				$t = $this->get_termInfo($v);
				$ti[] = $t;
			}
			$context['acf'] = ($post->ID) ? \get_fields($post->ID) : \get_fields('option');	// the incoming ACF fields
			$context['termInfo'] = $ti;														// the locale as defined in Wordpress

		} else if ($blob['blobType'] != 'clone') {
			$context['acf'] = ($post->ID) ? \get_fields($post->ID) : \get_fields('option');	// the incoming ACF fields

		} else {
			$sourcePath = $blob['sourcePath'];
			$pos = strpos($sourceRoute, '/');
			$sourcePrefix = substr($sourceRoute, 0, $pos);
			$sourceID = substr($sourceRoute, $pos+1);
			$sourceRelevantTerms = $DCAPI_index['postInfo'][$request]['relevantTerms'];

			if ($sourcePath == '') {							// local source blob
				$sourceRoute = $DCAPI_index['postInfo'][$request]['sourceRoute'];				// sources for clones always have format ppp/nnn
				$tempBlob = new \DCAPI\Blob($sourceRoute);		// get local blob
				$source_blob = $tempBlob->container;
				unset($tempBlob);
				$source_feed = $DCAPI_items[$sourcePrefix][$sourceID]['feedData'];
			} else {											// remote source blob
				$remoteRoute = $DCAPI_index['postInfo'][$request]['remoteRoute'];				// sources for clones always have format ppp/nnn
				$data = @file_get_contents("{$sourcePath}{$remoteRoute}");
				$source_blob = json_decode($data, true);		// get the file contents
				$data = @file_get_contents("{$sourcePath}{$sourcePrefix}/^feed");
				$source_items = json_decode($data, true);		// get the file contents
				$source_feed = $source_items[$sourceID]['feedData'];
			}
			unset($context['post']);							// not available for cloned blobs
			$context['sourceBlob'] = $source_blob;				// the source blob
			$context['sourceFeed'] = $source_feed;				// the source feed

			$newFields = [];
			$context['out']['meta']['route']['intRoute'] = $request;	// set the route for the clone
			$newFields[] = [
				'output_type' => 'clone',			// treated like 'normal' afterwards
				'output_field' => 'meta.route.intRoute',
				'output_to' => [ 'blob', 'feed' ],
				];
			$context['out']['route']['intRoute'] = $request;	// set the route for the clone
			$newFields[] = [
				'output_type' => 'clone',			// treated like 'normal' afterwards
				'output_field' => 'route.intRoute',
				'output_to' => [ 'blob', 'feed' ],
				];

			if ($source_blob['terms']) {
				$terms = [];
				foreach ($sourceRelevantTerms as $term_id) {
					$terms[] = $this->get_termInfo($term_id);
				}
				$context['out']['terms'] = $terms;		// set the terms for the clone
				$newFields[] = [
					'output_type' => 'clone',			// treated like 'normal' afterwards
					'output_field' => 'terms',
					'output_to' => [ 'blob' ],
					];
			}

			foreach ($fields as $fk => $field) {
				if ($field['output_type'] == 'clone') {
					$output_to = $field['output_to'];
					$blobOut = (in_array('blob', $output_to)) ? true : false;
					$feedOut = (in_array('feed', $output_to)) ? true : false;
					if ($blobOut and $feedOut) {
						$source = array_merge($source_feed, $source_blob);			// collapse both source arrays together
					} else if ($blobOut) {
						$source = $source_blob;
					} else if ($feedOut) {
						$source = $source_feed;
					} else {
						continue;
					}
					foreach ($source as $key => $value) {
						if ( ($key == 'meta') or ($key == 'route') or ($key == 'terms') or ($key == \DCAPI\HIDDEN) or ($key == \DCAPI\REPEAT) ) continue;
						$context['out'][$key] = $value;
						$newFields[] = [
							'output_type' => 'clone',			// treated like 'normal' afterwards
							'output_field' => $key,
							'output_to' => $output_to,
							];
					}
				} else {
					$newFields[] = $field;
				}
			}
			$fields = $newFields;								// overwrite fields array
		}

		if ($blob['blobType'] == 'search') {					// do extra stuff for search blob
			$p =  strpos($request, '/');										// supply search parameter
			if ($p > 0) {
				$context['out']['feed']['search'] = substr($request, $p+1);
				$context['out']['feed/search'] = substr($request, $p+1);
			}
		}

		// then do the processing
		foreach ($fields as $fk => $field) {
			$outputType = $field['output_type'];
			if ($outputType == 'clone') continue;								// no more field processing for clone items at this stage

			$repeatingField = $context['out'][$repeatingFieldName];				// set to an array with values if repeating
			$o = $field['output_field'];
			if ( ($outputType != 'postInfo') and ($outputType != 'termInfo') and ($outputType != 'template') ) {
				$context['out_field'] = $o;
				if ($field['code']) {
					if ($outputType != 'repeat') {
						$transformed_value = $template->run($field['code'], $context);			// run the handlebars template
					} else {
						if (is_array($repeatingField)) {						// deal with creation of repeating field data (but only if the repeating field is an array!)
							$transformed_value = $template->run($field['code'], $context);		// run the handlebars template
							if (!is_array($transformed_value)) $transformed_value = '';						
						}
					}
				} else {			
					$transformed_value = '';													// no handlebars template
				}
				if ( ($field['function']) and ($outputType != 'back end')) {
					$f = $DCAPI_transform[$field['function']];
					$t = $f['function'];
					if (is_array($t)) {						// deal with built-in functions
						$output_value = $t[0]->{$t[1]}($blob, $post, $transformed_value, $context['out']);
					} elseif ($t != '') {
						$output_value = $t($blob, $post, $transformed_value, $context['out']);					
					}
				} else {
					$output_value = $transformed_value;		// "simple copy"
				}

				if ($output_value != null) {				// NB: do not overwrite the output unless there is an output value
					$path = str_replace('/', '.', $o);		// normalise to dot notation
					if ($outputType == 'back end') {


						if ( ($blob['blobType'] == 'page') or ($blob['blobType'] == 'post') ) {	// only do back end fields for post and page type blobs
							if ($field['back_end'] == 'date/status') {								// process Back End 'date/status' column
								$backend['date_status'] .= $date_status_sep . $output_value;
								$date_status_sep = '<br/>';
							} 
							if ($field['back_end'] == 'info') {										// process Back End 'info' column
								$backend['info'] .= $info_sep . $output_value;
								$info_sep = '<br/>';
							}
						}

					} else if ($outputType == 'hidden') {
						$utils->set_field($output_value, $path, $context['out'][\DCAPI\HIDDEN]);
						$fields[$fk]['output_field'] = \DCAPI\HIDDEN . '.' . $path;

					} else if ($outputType == 'repeat') {
						if (is_array($repeatingField)) {						// deal with creation of repeating field data (but only if the repeating field is an array!)
							$utils->set_field($output_value, $path, $context['out'][\DCAPI\REPEAT]);
							$fields[$fk]['output_field'] = \DCAPI\REPEAT . '.' . $path;
						}

					} else {
						$o = str_replace('.', '/', $path);	// use slash notation to also output flattened names
						$context['out'][$o] = $output_value;						
						$utils->set_field($output_value, $path, $context['out']);
					}
				}
			}
		}
		$out = $context['out'];
		unset($context);

		gc_collect_cycles();
		return $fields;
	}

	private function get_pageInfo($ID) {					// remove unwanted items from ^index pageInfo
		global $DCAPI_index;
		$p = $DCAPI_index['postInfo'][$ID];
		unset($p['ID']);
		unset($p['blobType']);
		unset($p['postType']);
		unset($p['lastModified']);
		return $p;
	}

	private function get_termInfo($ID) {					// remove unwanted items from ^index pageInfo
		global $DCAPI_index;
		$t = $DCAPI_index['termInfo'][$ID];
		unset($t['childTerms']);
		unset($t['hidden']);
		unset($t['longName']);
		unset($t['rootName']);
		return $t;
	}

	private function regen_single_blob($request, $options, $blob, $post, $prefix, $ID, $log = false) {		// do single item
		global $DCAPI_blob_config;	
		global $DCAPI_index;	
		global $DCAPI_items;
		date_default_timezone_set('UTC');

		$utils = new \DCAPI\Utils();
		$lastModified = $utils->getLastModified();

		if (!$request) {
			return [ 'error' => "empty route" ];							// if no data supplied, then do nothing
		}

		if (!$blob) {														// see if it is ^blob or ^index
			if ($request == \DCAPI\CONFIGFILE) {
				$DCAPI_blob_config = new \DCAPI\Config($options);			// respond with blob configuration, allow regen
				return $DCAPI_blob_config->container;
			} else if ($request == \DCAPI\INDEXFILE) {
				$DCAPI_index = new \DCAPI\Index($options);					// respond with blob configuration, allow regen
				return $DCAPI_index->container;
			} else {
				return [ 'error' => "route '$request' could not be matched" ];
			}
		}

		if ($ID === \DCAPI\FEED_ITEMS) {											// 'xxx/^feed'
			if ($prefix) {
				$itemCache = new \DCAPI\ItemCache('itemCache', $prefix, true);

				if (in_array('regen', $options)) {
					$blobCache = new \DCAPI\Cache('');
					$blobCache->delete_cache_file($prefix, \DCAPI\FEED_ITEMS);		// force regeneration
					$response = $itemCache->get_all();				
					if ($log) $q = $this->output_progress('', "regenerated '$request'", true);
				} else {
					$response = $itemCache->get_all();								// just get it				
				}

				if ($response !== false) {
					return $response;
				} else {
					return [ 'error' => "unknown prefix '$prefix'" ];
				}
			} else {
				return [ 'error' => "missing prefix" ];
			}
		}

		$blobCache = new \DCAPI\Cache('');
		if ($blob['blobType'] == 'clone') {
			$post_modified = $DCAPI_index['postInfo'][$request]['lastModified'];
		} else {
			$post_modified = ($post) ? $post->post_modified_gmt : $lastModified['latestIndex'];
		}

		if ($blob['isCached']) {	
			$cachedir = $blob['cachedir'];
			$cachename = $blob['cachename'];
			if ($cachename === '*') $cachename = $ID;

			// force regenerate if requested
			if (in_array('regen', $options)) {
				$response = false;
			} else {
				if (!$DCAPI_items[$prefix][$ID]) {				// check if item cache entry is present
					$response = false;
				} else {
					$response = $blobCache->read_cache_file($cachedir, $cachename);
					if ($response == null) $response = false;
				}
			}
		} else {												// there is no cached blob
			// force regenerate if requested
			if (in_array('regen', $options)) {
				$response = false;
			} else {
				$response = $DCAPI_items[$prefix][$ID];			// retrieve the item cache entry
				if ($response == null) $response = false;
			}
		}

		if ($response !== false) {										// cache found
			if ( ($blob['postType'] != 'option') and 
					($post) and ($response['meta']['lastModified'] == $post_modified_gmt) ) {	// NB: there is no last modified date for options
				if ($blob['isCached']) return $response;				// skipped when wildcarding (item is cached)
			}
			if ($response['meta']['cacheTimestamp'] > $post_modified_gmt ) {			// not stale if cacheTimestamp is later than WP modification time
				if ($blob['isCached']) return $response;				// skipped when wildcarding (item is cached)
			}
		}	

		$backend = [ 'info' => '', 'date_status' => '', ];
		$out = [];
		$fields = [];

		// process all field transformations and populate $out and $backend
		$fields = $this->process_fields($blob, $post, $request, $out, $backend);

		$blobData = [ 'meta' => [] ];			// with meta data placeholder
		$feedData = [ ];						// stored in itemCache, without meta data placeholder (as it is outside this data set)

		foreach ($fields as $field) {
			if ($field['back_end'] == 'template') continue;	// only output real "output" fields
			$o = $field['output_field'];
			$path = str_replace('/', '.', $o);				// normalise to dot notation
			$outputType = $field['output_type'];
			$outputTo = $field['output_to'];
			if ($outputTo) {
				if (in_array('blob', $outputTo) ) $utils->do_field_output($out, $path, $blobData);
				if (in_array('feed', $outputTo) ) $utils->do_field_output($out, $path, $feedData);
			}
		}

		// remove the hidden parts as necessary
		if (empty($blobData[\DCAPI\HIDDEN])) unset($blobData[\DCAPI\HIDDEN]);
		if (empty($feedData[\DCAPI\HIDDEN])) unset($feedData[\DCAPI\HIDDEN]);

		// reformat the repeating part
		$utils->reorg_repeater($blobData[\DCAPI\REPEAT]);
		if (empty($blobData[\DCAPI\REPEAT]) or ($blobData[\DCAPI\REPEAT] === null)) unset($blobData[\DCAPI\REPEAT]);
		$utils->reorg_repeater($feedData[\DCAPI\REPEAT]);
		if (empty($feedData[\DCAPI\REPEAT]) or ($feedData[\DCAPI\REPEAT] === null)) unset($feedData[\DCAPI\REPEAT]);

// There are two data parts $blobData (the blob itself), $feedData (the feed item part - 'post' type blobs only)
//		- hidden parts are in subtree ^hidden and repeat parts are in subtree ^repeat
//
		$item = [];

		if ( ($blob['blobType'] == 'post') or ($blob['blobType'] == 'clone') ) {
			$item['feedData'] = $feedData;					// cache the feedData for posts and clones
		} else if ( ($blob['blobType'] == 'page') or ($blob['blobType'] == 'home') or ($blob['blobType'] == 'home-page') ) {
			if ($blobData['title']) $item['title'] = $blobData['title'];
			if ($blobData['shortTitle']) $item['shortTitle'] = $blobData['shortTitle'];
			if ($blobData['feed']['type']) $item['feedType'] = $blobData['feed']['type'];			
			$item['feedData'] = $feedData;					// cache the feedData for pages too
		}

		if ($blob['hasFeed']) {								// now deal with the feed part of the blob
			$feed = $blobData['feed'];
			if ($feed['type'] == 'none') {
				unset($blobData['feed']);
			} else {
				$hidden_feed = $feed;
				$this->feed_processor($feed, $hidden_feed, $blob, $out, $actualFeedTerms);
				if ($feed['count'] == 0) {					// don't return empty feeds
					unset($blobData['feed']);
				} else {
					$blobData['feed'] = $feed;
				}
				if ( ($hidden_feed['count'] > 0) and ($hidden_feed['count'] > $feed['count']) ) {			// don't return empty feeds or ones with no hidden items
					$blobData[\DCAPI\HIDDEN]['feed'] = $hidden_feed;
				}
			}
		}

// handle back end field data and pageMap/postMap

		if ( ($ID) and ($blob['blobType'] != 'clone') ) {
			if ( ($backend['info']) or ($backend['date_status']) ) {
				$item['backend'] = $backend;
			}
		}

		if ( ($blob['blobType'] == 'page') or ($blob['blobType'] == 'home') or ($blob['blobType'] == 'home-page') ) {
			$feedTerms = $this->get_feed_terms($actualFeedTerms);
			$item[\DCAPI\FEED_TERMS] = $feedTerms;

		} else if ($blob['blobType'] == 'post') {
			$relevantTerms = $this->get_relevant_terms($post->ID, $blob, $hidden);
			$item['relevantTerms'] = $relevantTerms;
			if ($hidden) {
				$item['hidden'] = $hidden;
				$blobData[\DCAPI\HIDDENBLOB] = true;			// signal that this blob is "hidden"
			}

		} else if ($blob['blobType'] == 'clone') {
			$relevantTerms = $DCAPI_index['postInfo'][$request]['relevantTerms'];
			$item['relevantTerms'] = $relevantTerms;
		}
		if ($post_modified) $item['meta']['lastModified'] = $post_modified;		// Wordpress post modified timestamp

		if ( ($blob['blobType'] != 'config') and ($blob['blobType'] != 'search') ) {
			$item = $DCAPI_items[$blob['prefix']]->set_item($ID, $item);			// update the item cache (and retrieve the meta data)
		}

// cache the results and generate repeated cache files if required, return the data

		if ($blob['isCached']) {
			$blobData = $blobCache->write_cache_file($cachedir, $cachename, $blobData, $post_modified);		// cache the data setting the meta data
		} else {
			if ($blob['blobType'] == 'search') {
				$item['meta']['self'] = substr($item['meta']['self'], 0, -1) . $blobData['feed']['search'];	// skip trailing '0' and repace with search string
			}
			$blobData['meta'] = $item['meta'];
			$blobData['meta']['self'] = str_replace('/itemCache/', '/', $blobData['meta']['self']);		// adjust self reference (used where there is no cached file)			
			unset($blobData['meta']['cacheTimestamp']);														// lack of cacheTimestamp indicates that there is no cached file
			unset($blobData['meta']['cacheStale']);														// lack of cacheTimestamp indicates that there is no cached file
		}

		unset($blobCache);

		gc_collect_cycles();
		if ($log) $q = $this->output_progress('', "regenerated '$request'", true);
		return $blobData;
	}

	private function get_feed_terms($actualFeedTerms) {
		global $DCAPI_index;
		$feedTerms = $actualFeedTerms;
		if (!is_array($feedTerms)) $feedTerms = [];
		if (is_array($actualFeedTerms)) {
			$feedTerms = array_merge($feedTerms, $actualFeedTerms);
			foreach ($actualFeedTerms as $t) {												// $actualFeedTerms returned by feed processor
				$feedTerms = array_merge($feedTerms, $DCAPI_index['termInfo'][$t]['childTerms']);
			}						
		}
		return $this->cleanup_array($feedTerms);
	}

	private function get_relevant_terms($ID, $blob, &$hidden) {
		global $DCAPI_index;

		$terms = wp_get_post_terms($ID, $blob['taxonomy'], array("fields" => "ids"));
		if (!is_array($terms)) $terms = [];
		$relevantTerms = $terms;
		$hidden = false;
		foreach ($terms as $t) {
			$relevantTerms = array_merge($relevantTerms, $DCAPI_index['termInfo'][$t]['parentTerms']);
			if ($DCAPI_index['termInfo'][$t]['hidden']) $hidden = true;
		}
		return $this->cleanup_array($relevantTerms);
	}

	private function cleanup_array($arr) {		// remove duplicates in a simple array list
		$outArr = [];
		foreach ($arr as $value) {
			if (!in_array($value, $outArr)) $outArr[] = $value;
		}
		return $outArr;
	}

	private function get_feedItem($post) {
		global $DCAPI_blob_config;
		global $DCAPI_items;
		$prefix = $DCAPI_blob_config['postTypePrefix'][$post->post_type];
		$cachedItems = $DCAPI_items[$prefix]->get_all();
		$it = $cachedItems[$post->ID];
		if ($it['hidden']) $it['feedData']['_hidden'] = true;					// signal hidden item
		$fd = [ 'route' => $it['meta']['route'] ];
		$fd = array_merge($fd, $it['feedData']);
		return $fd;
	}

	private function feed_processor(&$feed, &$hidden_feed, $blob, $out, &$terms) {	// $feed is the feed normally presented , $hidden_feed is the feed presented for privileged users
		global $DCAPI_blob_config;
		global $DCAPI_index;
		global $DCAPI_feed_filter;
		global $DCAPI_items;

		$terms = $out['param/terms']; 				// array of terms optionally provided via the $out array
		$posts = $out['param/posts']; 				// array of posts provided via the $out array overrides built-in query (used for home page)

		$field_types = $blob['feedTypes'];
		foreach ($field_types as $field_type) {
			if ($field_type['feed_type'] == $feed['type']) {
				$order = $field_type['order'];							// ASC or DESC or (as retrieved)
				$orderby = $field_type['order_by'];						// date, title or menu_order
				$source_blob = $field_type['source_blob'];
				$filter = $field_type['filter'];
				$tf = str_replace('.', '/', $field_type['terms_field']);	// use "flattened" field
				$terms = ( (!$tf) or ($tf == '(anything)') ) ? ( is_array($terms) ? $terms : [] ) : $out[$tf];
				$lf = str_replace('.', '/', $field_type['limit_field']);	// use "flattened" field
				$feedLimit = ( (!$lf) or ($lf == '(anything)') ) ? null : $out[$lf];
				break;
			}
		}

// build up list of individual feed items (without handling repeat or filtering yet)
		$feedItemList = [];
		foreach ($source_blob as $sb) {
			if ($sb == '(param/posts)') {									// post list provided via $out['param/posts']
				if (!empty($posts)) {
					foreach ($posts as $post) {			// NB: local items
						$feedItemList[] = $this->get_feedItem($post);
					}
				}
			} else if ($sb == '(search)') {									// need to carry out search for posts (if not provided via $out['param/posts'])
				if (empty($posts)) {
					$search = $out['feed/search'];			// filled in automatically

					// remove certain accented characters
					$search = str_replace(array('ä', 'à'), 'a', $search);
					$search = str_replace(array('é', 'è'), 'e', $search);
					$search = str_replace('ö', 'o', $search);
					$search = str_replace('ü', 'u', $search);
					$search = str_replace('Ä', 'A', $search);
					$search = str_replace('Ö', 'O', $search);
					$search = str_replace('Ü', 'U', $search);

					if (function_exists('relevanssi_do_query')) {
						// use Relevanssi for text search
						$query = new \WP_Query();
						$query->query_vars['s'] = urlencode($search);
						$query->query_vars['posts_per_page'] = -1;								// all posts
						$query->query_vars['post_type'] = $args['post_type'];					// all types
						if (!empty($terms)) $query->query_vars['tax_query'] = $args['tax_query'];
						relevanssi_do_query($query);
						$posts = $query->posts;					
					} else {
						// use Wordpress for search
						$args['s'] = urlencode($search);
						$query = new \WP_Query();
						$posts = $query->query($args);
					}
					foreach ($query->posts as $post) {
						if (substr($post->post_mime_type, 0, 6) != 'image/') {			// filter out any images from the search
							$posts[] = $post;
						}
					}	
				}	
				if (!empty($posts)) {
					foreach ($posts as $post) {			// NB: local items
						$feedItemList[] = $this->get_feedItem($post);
					}
				}
			} else {																	// use source blob to access items
				$blobIndex = $DCAPI_blob_config['routingMap']["$sb/"];					// NB: no trailing '*' or ID
				$prefix = $DCAPI_blob_config['blobs'][$blobIndex]['prefix'];
				$cachedItems = $DCAPI_items[$prefix]->get_all();
				foreach ($cachedItems as $ID => $it) {
					if ( ($ID != 'meta') and ($it['relevantTerms']) ) {
						$a = array_intersect($terms, $it['relevantTerms']);
						if (count($a) > 0) {
							if ($it['hidden']) $it['feedData']['_hidden'] = true;					// signal hidden item
							$fd = [ 'route' => $it['meta']['route'] ];
							$fd = array_merge($fd, $it['feedData']);
							$feedItemList[] = $fd;
						}
					}
				}
			}
		}

//  $feedItemList now contains all relevant post feed items (without processing repeats and feed filter) - now build $items
		$items = [];
		foreach ($feedItemList as $feedItem) {
			$itemSet = $this->build_item_set($feedItem, $order);			// process repeating parts
			foreach ($itemSet as $item) {
				$f = $DCAPI_feed_filter[$filter];
				$t = $f['function'];
				if (is_array($t)) {											// deal with built-in functions (feed filter)
					$key = $t[0]->{$t[1]}($out, $order, $orderby, $item);
				} elseif ($t != '') {
					$key = $t($out, $order, $orderby, $item);
				}
				if ($key) {													// if the feed filter does not return null the item is wanted
					$items[$key] = $item;
					$items[$key]['index'] = 0;		//***** needed for compatibility reasons to deal with Front End V1.0 -> points to dummy details array
				}
			}
		}
		if ($order == 'ASC') {
			ksort($items);
		} elseif ($order == 'DESC') {
			krsort($items);
		}												// do nothing if "(as retrieved)"

		// generate the two feed variants
		$feed = $this->generate_feed($feed, $items, $feedLimit);
		foreach ($items as $k => $v) {
			unset($items[$k]['_hidden']);		// reveal hidden items
		}
		$hidden_feed = $this->generate_feed($hidden_feed, $items, $feedLimit);
	}

	private function build_item_set($feedItem, $order) {
		$utils = new \DCAPI\Utils();
		$itemSet = [];
		$repeatSet = $feedItem[\DCAPI\REPEAT];
		if ($repeatSet) {
			foreach ($repeatSet as $key => $value) {
				$item = $feedItem;
				$utils->overwrite_repeat($item, $key);
				$itemSet[] = $item;
			}

			if ($order == 'DESC') {
				$itemSet = array_reverse($itemSet);				// read repeated items in the reverse order if necessary
			}
		} else {
			$itemSet = [ $feedItem ];		// make single item into an array
		}
		return $itemSet;
	}

	private function generate_feed($feed, $items, $feedLimit) {
		$count = 0;							// leave hidden items out of this count
		$itemList = [];						// output item list

		global $DCAPI_blob_config;

		foreach ($items as $item) {
			if (!$item['_hidden']) {
				$count++;				
				$itemList[] = $item;
			}
			if ( ($feedLimit) and ($count >= $feedLimit) ) { $count--; break; }
		}

		$feed['count'] = count($itemList);
		$feed['items'] = $itemList;
		$feed['details'] = array( array( '_compability' => 'needed for V1.0' ) );		//***** needed for compatibility reasons - only needed for Front End V1.0

		return $feed;
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