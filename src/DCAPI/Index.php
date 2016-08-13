<?php
/*
 *		Build ^index table
 */

namespace DCAPI;

class Index implements \ArrayAccess {
	public $container = [];
	private	$color;

	
	public function __construct($options = array()) {
		global $DCAPI_blob_config;
		$cachedir = '';
		$request = \DCAPI\INDEXFILE;
		$blobCache = new \DCAPI\Cache('');
		$this->color = new \DCAPI\Color();									//	used for setting category/term color using {{nextColor}} (which gets next color)

		$postTypes = [];
		foreach ($DCAPI_blob_config['postTypeMap'] as $postType => $blob_index) {
			$postTypes[] = $postType;
		}

		$utils = new \DCAPI\Utils();
		$lastModified = $utils->getLastModified();

		date_default_timezone_set('UTC');
		$modified = strtotime($lastModified['latestIndex']);

		// get all posts
		$args = array(
			'order' => 'ASC',
			'orderby' => 'ID',
			'posts_per_page' => '-1',
			'post_type' => $postTypes,
			'post_status' => array('publish', 'future', 'inherit') );
		$posts = get_posts($args);

		// check cache
		if (in_array('regen', $options)) {
			$blobCache->delete_cache_file($cachedir, $request);
			$response = false;
		} else {
			$response = $blobCache->read_cache_file($cachedir, $request);
			if ($response == null) $response = false;
		}

		if ($response !== false) {										// cache found
			if ($response['meta']['lastModified'] == $modified) {			// lastModified is latest blob update time in cache
				$this->container = $response;
				return;
			}
			if ($response['meta']['cacheTimestamp'] > $modified) {		// not stale if cacheTimestamp is later than WP modification time
				$this->container = $response;
				return;
			} 
		}	

		$data = $this->rebuild($posts, $lastModified);						// rebuild the index

		// output cache file and return results
		$time = intval( (microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]) * 1000 );
		$blobCache->log_message( "regenerated '^index' [" . $time . " ms]");
		$this->container = $blobCache->write_cache_file($cachedir, $request, $data, $modified);	// cache the data setting meta data
		return;
	}

	public function reconstruct($options = array()) {
		$this->container = null;
		$this->__construct($options);
		return;
	}

	public function regen() {
		$this->__construct(['regen']);
	}

	private function rebuild($posts, $lastModified) {
		global $DCAPI_blob_config;						// one global cached with the blob config data in it
		$temp_blob = new \DCAPI\Blob('');				// temporary Blob
		$dc = new \DCAPI\Cache('');
		$canonicalOrder = [ 'post', 'clone', 'page', 'home', 'home-page', 'config', ];		// canonical ordering of blob types jused for building $regenRoutes
																							//	NB: 'config' blobs may need to access all other items (in particular pages for the hierarchy)

		$extRoutes = [];
		$regenRoutes = [];								// canonically ordered list of internal routes 
		$regenRoutes[\DCAPI\CONFIGFILE] = \DCAPI\CONFIGFILE;					//	always first
		$regenRoutes[\DCAPI\INDEXFILE] = \DCAPI\INDEXFILE;					//	always second
		$postInfo = [];
		$termInfo = [];


		// put info into postInfo about ^blob and ^index
		$postInfo[\DCAPI\CONFIGFILE] = [
			'lastModified' => $lastModified['latestBlob'],
			'isCached' => true,
			];
		$postInfo[\DCAPI\INDEXFILE] = [
			'lastModified' => $lastModified['latestIndex'],
			'isCached' => true,
			];

		// first do termInfo
		$taxonomies = $DCAPI_blob_config['taxonomies'];
		$args = array(
			'orderby'           => 'id', 
			'order'             => 'ASC',
			'hide_empty'        => false,
			'fields'            => 'all', 
		); 
		$hiddenChildren =  [];
		$termList = get_terms($args);

		foreach ($termList as $term) {
			if (!in_array($term->taxonomy, $taxonomies)) continue; 		// only do relevant taxonomies
			$level = 0;													// keep track of the hierarchical level of the term in the taxonomy
			$parentTerms = [];
			$rootName = $term->name;
			$childTerms = get_term_children($term->term_id, $term->taxonomy);
			$n = $term->name . ' {' . $term->taxonomy . '}';
			$hiddenTerm = (is_array($DCAPI_blob_config['hiddenCategories']) and (in_array($n, $DCAPI_blob_config['hiddenCategories'])) ) ? true : false;
			$t = $term;
			while ($t->parent != 0) {
				$level++;
				$parentTerms[] = $t->parent;
				$t = get_term($t->parent, $t->taxonomy);
				$rootName = $t->name;
			}
			$termInfo[$term->term_id] = array(
				'term_id' => $term->term_id,
				'taxonomy' => $term->taxonomy,
				'name' => $term->name,
				'longName' => $n,
				'rootName' => $rootName,
				'hidden' => $hiddenTerm,
				'level' => $level,
				'parentTerms' => $parentTerms,
				'childTerms' => $childTerms,
				);
			$this->process_fields('termInfo', $term, $termInfo[$term->term_id]);		// do template driven processing
			if ($childTerms) {
				if ($hiddenTerm) $hiddenChildren = array_merge($hiddenChildren, $childTerms);
			}
		}

		foreach ($termInfo as $key => $value) {
			if (in_array($key, $hiddenChildren)) $termInfo[$key]['hidden'] = true;		// hide the whole tree below given setting
		}

		$cloneBlobs = [];
		foreach ($DCAPI_blob_config['blobs'] as $b) {
			$surl = get_site_url();
			switch ($b['blobType']) {
				case 'post':
				case 'page':
					$allLastMod = 1;
					foreach ($posts as $post) {
						if ($b['postType'] != $post->post_type) continue;				// there is one list of posts for all
						$i = $b['prefix'] . '/' . $post->ID;
						$route = $this->generate_routes($b, $post);
						$x = $route['extRoute'];
						if ($x) {
							if ($x != '/' . \DCAPI\HOMEROUTE) {							// not Wordpress front page
								$extRoutes[$x] = $i;
							} else {
								if (!isset($extRoutes[$x])) {							// do not override if already set by home/home-page blob
									$extRoutes[$x] = $i;
								}
							}
						}
						$lastMod = $post->post_modified_gmt;
						if ($lastMod > $allLastMod) $allLastMod = $lastMod;
						$postInfo[$post->ID] = [
							'ID' => $post->ID,
							'postType' => $post->post_type,
							'blobType' => $b['blobType'],
							'isCached' => $b['isCached'],
							'route' => $route,
							'lastModified' => $lastMod,
							];
						if ($b['blobType'] == 'page') {
							$postInfo[$post->ID]['_menuOrder'] = $post->menu_order;
							$postInfo[$post->ID]['parentPage'] = $post->post_parent;
							$postInfo[$post->ID]['childPages'] = [];
						} else if ($b['blobType'] == 'post') {
							// determine "public" relevant terms here (full list in itemCache - see DCAPI\Blob\get_relevant_terms() )
							$terms = wp_get_post_terms($post->ID, $b['taxonomy'], array("fields" => "ids"));
							if (!is_array($terms)) $terms = [];
							$postInfo[$post->ID]['relevantTerms'] = $this->build_relevant_terms_list($terms, $termInfo);
						}
						$this->process_fields('postInfo', $post, $postInfo[$post->ID]);		// do template driven processing
					}
					$fi = $b['prefix'] . '/' . \DCAPI\FEED_ITEMS;						// special placeholder for feed items
					$postInfo[$fi] = [
						'blobType' => $b['blobType'],
						'postType' => $b['post_type'],
						'route' => 	[ 'intRoute' => $fi ],
						'isCached' => true,
						'lastModified' => $allLastMod,
						];

					break;

				case 'clone':						//	only keep list of clone blobs here - need full local postInfo for local cloning
					$cloneBlobs[] = $b;
					break;

				case 'home':
				case 'home-page':
					$i = $b['prefix']. '/' . $b['fixedID'];
					$x = '/' . \DCAPI\HOMEROUTE;
					$extRoutes[$x] = $i;			// override even if already set by Wordpress front page
					$postInfo[0] = [
						'ID' => 0,
						'postType' => ($b['blobType'] == 'home') ? 'option' : 'page',
						'blobType' => $b['blobType'],
						'isCached' => $b['isCached'],
						'route' => [
							'intRoute' => $i,
							'extRoute' => $x,
							],
						'lastModified' => $lastModified['config'],
						'_menuOrder' => 0,
						'parentPage' => null,
						'childPages' => [],
						];
					$this->process_fields('postInfo', null, $postInfo[0]);		// do template driven processing
					break;

				case 'search':
					$i = $b['prefix'] . '/';
					$x = '/' . $b['routePrefix'] . '/';
					$extRoutes[$x] = $i;
					$extRoutes['/'. \DCAPI\SEARCHROUTE .'/'] = $i;
					break;			
				
				case 'config':
						$postInfo['config'] = [
							'blobType' => $b['blobType'],
							'postType' => $b['postType'],
							'isCached' => $b['isCached'],
							'route' => [ 'intRoute' => $b['prefix'] ],
							'lastModified' => $lastModified['latestIndex'],
							];

					break;

				default:
					break;
			}
		}
		unset($temp_blob);
		unset($dc);

		foreach ($postInfo as $key => $page) {
			if ( ($page['postType'] != 'page') and ($page['postType'] != 'option') ) continue;
			if ( ($page['parentPage'] !== null) and ($page['postType'] == 'page') ) $postInfo[$page['parentPage']]['childPages'][$key] = $page['_menuOrder'];
			unset($postInfo[$key]['_menuOrder']);
		}
		foreach ($postInfo as $key => $page) {				// sort the child pages into menu order (now hidden)
			if ( ($page['postType'] != 'page') and ($page['postType'] != 'option') ) continue;
			if (!empty($page['childPages'])) {
				$c = $page['childPages'];
				asort($c);				
				$postInfo[$key]['childPages'] = [];
				foreach ($c as $k => $v) $postInfo[$key]['childPages'][] = $k;
			}
		}		

		// process clone blobs once the termInfo and postInfo lists are fully completed
		foreach ($cloneBlobs as $b) {
			$sp = $b['sourcePath'];
			$sb = $b['sourceBlob'];
			$st = $b['sourceTerm'];
			$tt = $b['targetTerm'];

			if ($sp) {										// remote
				$data = @file_get_contents($sp . \DCAPI\INDEXFILE);
				$source_index = json_decode($data, true);	// get the remote index
				$ti = $source_index['termInfo'];
				$pi = $source_index['postInfo'];
				$pt = null; 					
			} else {										// local
				$ti = $termInfo;
				$pi = $postInfo;
				foreach ($DCAPI_blob_config['blobs'] as $bb) {
					if ($bb['blobType'] == $sb) { $pt = $bb['postType']; break; }
				}		
			}

			$sourceTermID = null;
			foreach ($ti as $term_id => $term) {
				if ($term['longName'] == $st) { $sourceTermID = $term_id; break; }
			}
			$targetTermID = null;
			foreach ($termInfo as $term_id => $term) {
				if ($term['longName'] == $tt) { $targetTermID = $term_id; break; }
			}
			if ($sourceTermID) {
				$allLastMod = 1;
				foreach ($pi as $ID => $item) {
					if ( ($item['blobType'] != $sb) or ($pt and $item['postType'] != $pt) ) continue;		// only look at local items of correct blob type
					if ( ($ID != intval($ID) ) or (!$item['relevantTerms']) ) continue;						// then only include local posts with the correct term setting
					if ( in_array( $sourceTermID, $item['relevantTerms']) ) {
						$cloneRoute = $b['prefix'] . '/' . $ID;
						$lastMod = $item['lastModified'];
						if ($lastMod > $allLastMod) $allLastMod = $lastMod;
						$relevantTerms = $this->build_relevant_terms_list([ $targetTermID, ], $termInfo);
						$postInfo[$cloneRoute] = [		// add entries to local postInfo
							'ID' => $cloneRoute,
							'blobType' => $b['blobType'], 
							'postType' => '(' . $item['postType'] . ')',				// NB: this is the remote value, hence the parentheses
							'route' => [ 'intRoute' => $cloneRoute, ],
							'lastModified' => $lastMod,
							'relevantTerms' => $relevantTerms,							// target setting (always based on local value)
							'title' => $item['title'],
							];
						if ($sp) {						// remote
							$postInfo[$cloneRoute]['remoteRoute'] = $item['route']['intRoute'];		// the internal route in the remote site
						} else {
							$postInfo[$cloneRoute]['sourceRoute'] = $item['route']['intRoute'];		// the internal route in the local site
						}
						// NB: no template driven processing for cloned items (at the moment)
					}
				}
				$fi = $b['prefix'] . '/' . \DCAPI\FEED_ITEMS;									// special placeholder for feed items
				$postInfo[$fi] = [
					'blobType' => $b['blobType'],
					'postType' => $pt,
					'route' => 	[ 'intRoute' => $fi ],
					'isCached' => true,
					'lastModified' => $allLastMod,
					];
			}
		}

		// build canonical list of routes for regen processing
		foreach ($canonicalOrder as $blobType) {
			foreach ($postInfo as $pid => $pi) {
				if ($pi['blobType'] == $blobType) {
					$regenRoutes[ $pi['route']['intRoute'] ] = $pid;
				}
			}
		}

		ksort($extRoutes);

		return array(
			'meta' => [],							// placeholder
			'lastModified' => $lastModified,
			'extRoutes' => $extRoutes,
			'regenRoutes' => $regenRoutes,
			'postInfo' => $postInfo,
			'termInfo' => $termInfo,
			);
	}

	private function build_relevant_terms_list($terms, $termInfo) {
		$relevantTerms = $terms;
		foreach ($terms as $t) {
			if ($termInfo[$t]['hidden']) continue;
			$relevantTerms = array_merge($relevantTerms, $termInfo[$t]['parentTerms']);
		}
		$outArr = [];
		foreach ($relevantTerms as $value) {
			if (!in_array($value, $outArr)) $outArr[] = $value;
		}
		return $outArr;
	}

	public function generate_routes($blob, $post) {	// returns an array containing [ 'intRoute, 'extRoute' ]
		if ($blob['style'] == 'ID') {
			$intRoute = $blob['prefix'] . '/' . $post->ID ;
		} else if ($blob['style'] == 'fixedID') {
			$intRoute = $blob['prefix'] . '/' . $blob['fixedID'] ;
		} else {
			$intRoute = $blob['prefix'];
		}
	
		if ($blob['generateRoute']) {
			if ($blob['routeStyle'] == 'ID') {
				$extRoute = $post->ID;

			} else if ($blob['routeStyle'] == 'permalink') {
				$pl = explode(home_url(), get_permalink($post->ID))[1];
				preg_match("/^\/(\?([a-zA-Z0-9-_]*)=([a-zA-Z0-9-_]*)|([a-zA-Z0-9-_\/]*))\/?$/U", $pl, $m);
				if ($m) {
					if ($m[2]) {
						if ($m[2] == 'p') {
							$extRoute = $m[3];						// if ?p=nnn just use the post ID				
						} else {
							$extRoute = $m[2] . '/' . $m[3];		// ?xxx=nnn				
						}
					} else {
						$extRoute = $m[4];							// ppp/qqq/rrr
					}
				} else {	
					$extRoute = $post->ID;						
				}
				if ($extRoute == '') $extRoute = \DCAPI\HOMEROUTE;	// fixed Wordpress start page is set
			}
			$extRoute = ( ($blob['routePrefix']) ? '/' . $blob['routePrefix'] : '' ) . '/'  . $extRoute;
			return [
				'intRoute' => $intRoute,
				'extRoute' => $extRoute,
				];
		} else {
			return [
				'intRoute' => $intRoute,
				];
		}
	}			

	private function process_fields($outputType, $item, &$element) {		// $outputType = 'postInfo' or 'termInfo' / $item is post or term
		global $DCAPI_blob_config;		
		global $DCAPI_fields;
		global $DCAPI_transform;
		$utils = new \DCAPI\Utils();
		$cacheKey = $outputType;

		// build preprocessed copy of fields table with handlebars template
		$transform = $DCAPI_fields[$cacheKey];		// try to read in the cached version

		if ($transform === false) {					// need to generate the data
			$template = new \DCAPI\Handlebars();
			$transform = [ 							// placeholders
				'meta' => [],
				'fields' => [], 
				];

			$fields = $DCAPI_blob_config['commonFields'][$outputType];		// note that partial templates do not apply for ^index fields
			foreach ($fields as $fk => $field) {
				$x = $field['template'];
				if ( (substr($x, 0, 3) == "<p>") and (substr($x, -5) == "</p>\n") ) $x = substr($x, 3, -5);
				$tpl = str_replace("<br />\n", "\n", $x);
				if ($tpl) {
					$template->compile($tpl);
					$fields[$fk]['code'] = $template->save_code();
				} else {			
					$fields[$fk]['code'] = null;							// no handlebars template
				}
			}
			$transform['fields'] = $fields;
			$DCAPI_fields[$cacheKey] = $transform;							// store away in the cache

		} else {
			$template = new \DCAPI\Handlebars();							// retrieve from the cache
			$fields = $transform['fields'];
		}

		// now carry out the processing of the fields - first, set up the context data
		date_default_timezone_set(get_option('timezone_string'));

		if ($outputType == 'postInfo') {
			$context = [
				'input' => $element,													// the incoming prebaked data
				'post' => (array) $item, 												// the incoming post
				'acf' => ($item->ID) ? \get_fields($item->ID) : \get_fields('option'),	// the incoming ACF fields
				'out' => [],															// output array 
				];
		} else if ($outputType == 'termInfo') {
			$context = [
				'input' => $element,													// the incoming prebaked data
				'term' => (array) $item, 												// the incoming term
				'acf' => \get_fields('category_' . $item->term_id),						// the incoming ACF fields
				'_color' => $this->color,												// used for setting color (if any) - private to handlebars!
				'out' => [],															// output array 
				];
		}

		// then do the processing
		if (!$fields) $fields = [];
		foreach ($fields as $fk => $field) {
			if ($field['output_type'] != $outputType) continue;
			$o = $field['output_field'];

			if ($field['code']) {
				$transformed_value = $template->run($field['code'], $context);			// run the handlebars template
			} else {			
				$transformed_value = '';												// no handlebars template
			}
			if ($field['function']) {
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
				$path = str_replace('/', '.', $o);		// normalise to dot notation (NB: no flattened names)
				$utils->set_field($output_value, $path, $context['out']);
			}
		}

		if ($context['out']) {
			foreach ($context['out'] as $key => $value) {
				if ( empty($value) or ($value === null) ) {
					continue;
				} else if ( (isset($element[$key])) and (is_array($element[$key])) and (is_array($value)) ) {
					$element[$key] = array_merge_recursive($element[$key], $value);
				} else {
					$element[$key] = $value;
				}
			}
		}
		return;
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