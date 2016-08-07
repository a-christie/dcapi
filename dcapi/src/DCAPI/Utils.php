<?php
/*
 *		General utility routines
 */

namespace DCAPI;

class Utils {

	function getLastModified() {
		date_default_timezone_set('UTC');
		$lastModified = [];
		$lastModified['blob'] = get_option('DCAPI_blob_lastModified', null);
		$lastModified['config'] = get_option('DCAPI_config_lastModified', null);
		$lastModified['page'] = get_option('DCAPI_page_lastModified', null);
		$lastModified['post'] = get_option('DCAPI_post_lastModified', null);
		$lastModified['term'] = get_option('DCAPI_term_lastModified', null);

		$latestBlobMod = max ( [ $lastModified['blob'], $lastModified['config'], ] );						// used for ^blob lastModified
		$lastModified['latestBlob'] = ($latestBlobMod) ? $latestBlobMod : date('Y-m-d H:i:s');
		$latestIndexMod = max ( [ $lastModified['blob'], $lastModified['config'], $lastModified['page'], $lastModified['post'], $lastModified['term'], ] );
		$lastModified['latestIndex'] = ($latestIndexMod) ? $latestIndexMod : date('Y-m-d H:i:s');			// used for ^index and config blob lastModified
		
		return $lastModified;
	}			

	function overwrite_hidden(&$element) {				// overwrite hidden parts recursively
		if (isset($element[\DCAPI\HIDDEN])) {
			$overwrite = $element[\DCAPI\HIDDEN];
			unset($element[\DCAPI\HIDDEN]);
			foreach ($overwrite as $k => $v) {
				if (is_array($v)) {
					foreach ($v as $kk => $vv) {
						if (is_array($vv)) {
							foreach ($vv as $kkk => $vvv) {	// max of 3 levels deep
								if ($vvv == \DCAPI\REMOVE_MARKER) { unset($element[$k][$kk][$kkk]); } else { $element[$k][$kk][$kkk] = $vvv; }
							}
						} else {
							if ($vv == \DCAPI\REMOVE_MARKER) { unset($element[$k][$kk]); } else { $element[$k][$kk] = $vv; }
						};
					}
				} else {
					if ($v == \DCAPI\REMOVE_MARKER) { unset($element[$k]); } else { $element[$k] = $v; }
				};
			}
		} 
		foreach ($element as $key => $value) if (is_array($element[$key])) overwrite_hidden($element[$key]);
	}

	function overwrite_repeat(&$element, $repKey) {	// overwrite repeat parts (no recursion needed!)
		$overwrite = $element[\DCAPI\REPEAT][$repKey];
		foreach ($overwrite as $k => $v) {
			if (is_array($v)) {
				foreach ($v as $kk => $vv) {
					if (is_array($vv)) {
						foreach ($vv as $kkk => $vvv) {			// max of 3 levels deep 
							if ($vvv == \DCAPI\REMOVE_MARKER) {
								unset($element[$k][$kk][$kkk]);
							} else {
								$pos = strpos($vvv, \DCAPI\RETAIN_MARKER);
								if ($pos !== false) $vvv = substr_replace($vvv, $element[$k][$kk][$kkk], $pos, strlen(\DCAPI\RETAIN_MARKER));
								$vvv = str_replace(\DCAPI\RETAIN_MARKER, '', $vvv);			// maximum one replacement
								$element[$k][$kk][$kkk] = $vvv;
							}
						}
					} else {
						if ($vv == RDCAPI\REMOVE_MARKER) {
							unset($element[$k][$kk]);
						} else {
							$pos = strpos($vv, \DCAPI\RETAIN_MARKER);
							if ($pos !== false) $vv = substr_replace($vv, $element[$k][$kk], $pos, strlen(\DCAPI\RETAIN_MARKER));
							$vv = str_replace(\DCAPI\RETAIN_MARKER, '', $vv);			// maximum one replacement
							$element[$k][$kk] = $vv;
						}
					}
				}
			} else {				
				if ($v == \DCAPI\REMOVE_MARKER) {
					unset($element[$k]);
				} else {
					$pos = strpos($v, \DCAPI\RETAIN_MARKER);
					if ($pos !== false) $v = substr_replace($v, $element[$k], $pos, strlen(\DCAPI\RETAIN_MARKER));
					$v = str_replace(\DCAPI\RETAIN_MARKER, '', $v);				// maximum one replacement
					$element[$k] = $v;
				}
			};
		}
		unset($element[\DCAPI\REPEAT]);
	}

	public function reveal($logged_in) {
		$reveal = false;
		if ($logged_in) {
			set_wordpress_context();
			$user = new \DCAPI\User();
			$info = $user->current_user();
			$reveal = $info['reveal'];
		}
		return $reveal;
	}

	public function handle_hidden_and_repeat(&$element, $repKey) {
		// deal with hidden items

		if ($this->reveal($logged_in)) {		
			$this->overwrite_hidden($element);					// reveal hidden items
		} else {
			if ($response[\DCAPI\HIDDENBLOB] === true) {
				$response = array( 'error' => "could not parse DCAPI request '$init_request'.", );
				output_and_exit($response, $format);	// write out the blob results in JSON format	
			}
			$this->remove_hidden($element);			 		// remove hidden items
		}
		unset($response[\DCAPI\HIDDENBLOB]);

		// deal with repeating items
		if ( ($repKey) and ($element['meta']['route'][\DCAPI\REPEAT]) ) {	// overwrite repeat fields
			$this->overwrite_repeat($element['meta']['route'], $repKey);
		}
		unset($element['meta']['route'][\DCAPI\REPEAT]);

		if ( ($repKey) and ($element[\DCAPI\REPEAT]) ) {	// overwrite repeat fields
			$this->overwrite_repeat($element, $repKey);
		}
		unset($element[\DCAPI\REPEAT]);
	}

	public function remove_hidden(&$element) {					// remove hidden parts recursively
		if (!is_array($element)) return;
		if (isset($element[\DCAPI\HIDDEN])) unset($element[\DCAPI\HIDDEN]);
		foreach ($element as $key => $value) if (is_array($element[$key])) $this->remove_hidden($element[$key]);
	}

	public function set_field($output_value, $path, &$element) {
		$parts = explode('.', $path, 4);		// get the parts, max four deep (to account for ^repeat fields)
		if (count($parts) == 4) {
			$element[$parts[0]][$parts[1]][$parts[2]][$parts[3]] = $output_value;			
		} else if (count($parts) == 3) {
			$element[$parts[0]][$parts[1]][$parts[2]] = $output_value;			
		} else if (count($parts) == 2) {
			$element[$parts[0]][$parts[1]] = $output_value;
		} else {
			$element[$parts[0]] = $output_value;
		}
	}

	public function do_field_output($out, $path, &$element) {		// do not flatten the output field name
		$parts = explode('.', $path, 4);		// get the path parts, max four deep (to account for ^repeat fields)
		if (count($parts) == 4) {
			if ($out[$parts[0]][$parts[1]][$parts[2]][$parts[3]]) $element[$parts[0]][$parts[1]][$parts[2]][$parts[3]] = $out[$parts[0]][$parts[1]][$parts[2]][$parts[3]];	
		} else 	if (count($parts) == 3) {
			if ($out[$parts[0]][$parts[1]][$parts[2]]) $element[$parts[0]][$parts[1]][$parts[2]] = $out[$parts[0]][$parts[1]][$parts[2]];	
		} else if (count($parts) == 2) {
			if ($out[$parts[0]][$parts[1]]) $element[$parts[0]][$parts[1]] = $out[$parts[0]][$parts[1]];
		} else {
			if ($out[$parts[0]]) $element[$parts[0]] = $out[$parts[0]];
		}
	}

	public function reorg_repeater(&$element) {			// takes the individual fields and reorganises the items by repeating field key
		if (empty($element) or ($element === null)) return;
		$items = [];
		foreach ($element as $key => $value) {
			if (is_array($value)) {
				foreach ($value as $k => $v) {
					if (is_array($v)) {
						foreach ($v as $kk => $vv) {
							if (is_array($vv)) {				// handle items up to 3 levels deep (general out field limitation is 3 levels)
								foreach ($vv as $kkk => $vvv) {
									$items[$kkk][$key][$k][$kk] = $vvv;							
								}
							} else {
								$items[$kk][$key][$k] = $vv;							
							}
						}
					} else {
						$items[$k][$key] = $v;
					}
				}
			}
		}
		$element = [];
		foreach ($items as $key => $value) {
			$element[$key] = $value;
		}
	}
}

?>