<?php
/*
 *		Routines for processing handlebars templates in DCAPI
 *
 *		Implementation of handlebars analogous to handlebars.js, with the following restrictions:
 *		- no processing of quadruple "raw" mustaches
 *		- paths using embedded '/' not processed
 *		- @../index etc. paths are not supported
 *		- the names of "as" parameters are passed into handlers as hash parameters with the names "asParam-n" (n = 1..count)
 *		- log helper echoes to the screen, no "level=" support provided
 *
 *		See http://handlebarsjs.com/
 */

namespace DCAPI;

class Handlebars {
	private $block = [];						// parsed block
	private $helpers = [];						// helper to function map
	private $partials = [];						// pre-stored partial list

	private $contextStack = [];					// keep a stack of contexts
	private $contextTop = -1;
	private $atParams = [];						// current "@-params"

	private $headings = [];						// headings table

	public function __construct($partials = null) {
		if ($partials) $this->partials = $partials;

		// register internal helpers
		$this->registerHelper('if', array($this, 'do_if'));
		$this->registerHelper('unless', array($this, 'do_unless'));
		$this->registerHelper('each', array($this, 'do_each'));
		$this->registerHelper('with', array($this, 'do_with'));
		$this->registerHelper('lookup', array($this, 'do_lookup'));
		$this->registerHelper('log', array($this, 'do_log'));

		// register DCAPI helpers
		$this->registerHelper('br', array($this, 'do_br'));
		$this->registerHelper('remove', array($this, 'do_remove'));
		$this->registerHelper('retain', array($this, 'do_retain'));
		$this->registerHelper('wordpress', array($this, 'do_wordpress'));
		$this->registerHelper('postmeta', array($this, 'do_postmeta'));
		$this->registerHelper('acf', array($this, 'do_acf'));
		$this->registerHelper('route', array($this, 'do_route'));
		$this->registerHelper('nextColor', array($this, 'do_nextColor'));
		$this->registerHelper('map', array($this, 'do_map'));
		$this->registerHelper('eval', array($this, 'do_eval'));
		$this->registerHelper('format', array($this, 'do_format'));
		$this->registerHelper('list', array($this, 'do_list'));

		$this->compile('');						// ensure engine is initialised
	}

	public function save_partials() {
		return $this->partials;
	}

	public function save_code() {
		return $this->block;
	}

	public function restore($code) {
		$this->block = $code;
		return;
	}

	public function run($code, $context) {
		$this->block = $code;
		return $this->process($context);
	}

	private function do_if($params, $argValues, $hashValues, $ifBlock, $elseBlock) {
		if ($argValues[1]) {
			return $this->process(null, $ifBlock);
		} else {
			return $this->process(null, $elseBlock);
		}
	}

	private function do_unless($params, $argValues, $hashValues, $ifBlock, $elseBlock) {
		if (!$argValues[1]) {
			return $this->process(null, $ifBlock);
		} else {
			return $this->process(null, $elseBlock);
		}
	}

	private function do_each($params, $argValues, $hashValues, $doBlock, $elseBlock) {		// {{#each array}} ...interate block across that context.... {{/each}}
																							//	if second parameter is "cloneKey" then the output array uses the same key value
																							//		if it is "cloneValue" then the output array uses the input value as key
																							//		if it is "Map" then the output array is a map from value to input key
		$currentAtParams = $this->atParams;				// store previous settings
		if (is_array($argValues[1])) {
			$result = [];
			$count = 0;
			$last = count($argValues[1]);
			foreach ($argValues[1] as $key => $value) {
				$count++;
				$this->atParams['@first'] = ($count == 1) ? true : false;
				$this->atParams['@last'] = ($count == $last) ? true : false;
				$this->atParams['@key'] = $key;
				$this->atParams['@index'] = $key;
				if ($hashValues['asParam-2']) $this->atParams[$hashValues['asParam-2']] = $key;
				$this->atParams['@value'] = $value;
				if ($hashValues['asParam-1']) $this->atParams[$hashValues['asParam-1']] = $value;
				if ($argValues[2] == 'cloneKey') {
					$result[$key] = $this->process($value, $doBlock);
				} else if ($argValues[2] == 'cloneValue') {
					$result[$value] = $this->process($value, $doBlock);
				} else if ($argValues[2] == 'Map') {
					$result[$this->process($value, $doBlock)] = $key;
				} else {
					$result[] = $this->process($value, $doBlock);
				}
			}
		} else {
			$result = '';
			$this->atParams['this'] = $argValues[1];
			$result = $this->process(null, $elseBlock);
		}
		$this->atParams = $currentAtParams;				// restore previous settings
		return $result;
	}

	private function do_with($params, $argValues, $hashValues, $doBlock, $elseBlock) {		// {{#with array}} ...block in that context.... {{/with}}
																							//	 if no array given, just run the block contents
		if (empty($argValues)) {
			return $this->process(null, $doBlock);						// just run it!
		} else if (is_array($argValues[1])) {
			return $this->process($argValues[1], $doBlock);
		} else if (is_array($hashValues) and (count($hashValues) > 0) ) {
			return $this->process($hashValues, $doBlock);
		} else {
			return $this->process(null, $elseBlock);
		}
	}

	private function do_lookup($params, $argValues, $hashValues, $ifBlock, $elseBlock) {	// {{lookup array index}}
		if ($argValues[1]) {
			$result = $argValues[1][$argValues[2]];
			return $result;
		}
	}

	private function do_log($params, $argValues, $hashValues, $ifBlock, $elseBlock) {		// {{log value}}
		foreach ($argValues as $key => $value) {
			if ($key !== 0) echo "\nLOG '$value' ";
		}
		echo "\n";
	}

	// DCAPI helpers

	private function do_br($params, $argValues, $hashValues, $ifBlock, $elseBlock) {		// {{br}}	- inserts a newline break tag
		return '<br />';
	}

	private function do_remove($params, $argValues, $hashValues, $ifBlock, $elseBlock) {	// {{remove}}	- inserts a "remove this attribute" signal
		return \DCAPI\REMOVE_MARKER;		// a single 'ESC' character signals that the repeat/hidden field should be removed rather than overwritten
	}

	private function do_retain($params, $argValues, $hashValues, $ifBlock, $elseBlock) {	// {{retain}}	- inserts a "retain this attribute" signal
		return \DCAPI\RETAIN_MARKER;		// a double 'ESC' character signals that the repeat/hidden field should be retained in the output (replaced by the current value)
	}

	private function do_wordpress($params, $argValues, $hashValues, $ifBlock, $elseBlock) {	// {{wordpress "option_name"}}
		return get_option($argValues[1]);
	}

	private function do_postmeta($params, $argValues, $hashValues, $ifBlock, $elseBlock) {	// {{postmeta "key"}} - returns a "single" post meta value, given the key
		$postID = $this->contextStack[0]['post']['ID'];
		return get_post_meta($postID, $argValues[1], true);	
	}

	private function do_acf($params, $argValues, $hashValues, $ifBlock, $elseBlock) {		// {{acf "key"}} - returns a "single" ACF option value, given the key
		return \get_field($argValues[1], 'option');	
	}

	private function do_route($params, $argValues, $hashValues, $ifBlock, $elseBlock) {		// {{route "ID"}} - returns a route array, given a (post) ID, else use context request to look up route
		global $DCAPI_index;
		$request = $this->contextStack[0]['request'];
		$reqRoute = $DCAPI_index['regenRoutes'][$request];

		$pid = ($argValues[1]) ? $argValues[1] : $reqRoute;
		if ($pid) {
			return $DCAPI_index['postInfo'][$pid]['route'];
		} else if ($pid === 0) {
			return $DCAPI_index['postInfo'][0]['route'];
		} else {
			return null;
		}
	}

	private function do_nextColor($params, $argValues, $hashValues, $ifBlock, $elseBlock) {		// {{nextColor "root"}} - returns the next color (only used in termInfo processing!)
		if ($this->contextStack[0]['_$color']) {
			if ( ($argValues[1]) and ($argValues[1] != $this->contextStack[0]['input']['rootName']) ) return;		// if argument is given it must match the root term name
			return $this->contextStack[0]['_color']->nextColor();
		}
	}

	private function do_map($params, $argValues, $hashValues, $doBlock, $elseBlock) {		//  {{#map input_value}} ... {{/map}}}}
																							//		'...': lines with format test=>output_string
																							//		first match of input_value returns output_string
																							//		test can be a regex in the form /.../p
																							//	if second parameter is "cloneKey" then the output array uses the same key value
																							//		if it is "cloneValue" then the output array uses the input value as key
																							//		if it is "Map" then the output array is a map from value to input key
		$currentAtParams = $this->atParams;				// store previous settings
		if (is_array($argValues[1])) {
			$count = 0;
			$last = count($argValues[1]);
			$result = [];
			foreach ($argValues[1] as $key => $value) {
				$count++;
				$this->atParams['@first'] = ($count == 1) ? true : false;
				$this->atParams['@last'] = ($count == $last) ? true : false;
				$this->atParams['@key'] = $key;
				$this->atParams['@index'] = $key;
				if ($hashValues['asParam-2']) $this->atParams[$hashValues['asParam-2']] = $key;
				$this->atParams['@value'] = $value;
				if ($hashValues['asParam-1']) $this->atParams[$hashValues['asParam-1']] = $value;
				$map = $this->process($value, $doBlock);
				$t = $this->do_mapping($value, $map);
				if ($t !== null) {
					if ($argValues[2] == 'cloneKey') {
						$result[$key] = $t;
					} else if ($argValues[2] == 'cloneValue') {
						$result[$value] = $t;
					} else if ($argValues[2] == 'Map') {
						$result[$t] = $key;
					} else {
						$result[] = $t;
					}
				}
			}
		} else {
			$this->atParams['this'] = $argValues[1];
			$map = $this->process(null, $doBlock);
			$result = $this->do_mapping($argValues[1], $map);
		}
		$this->atParams = $currentAtParams;				// restore previous settings
		return $result;
	}

	private function do_eval($params, $argValues, $hashValues, $doBlock, $elseBlock) {		//  {{#eval input_value}} ... {{/eval}}}}
																							//		evaluates the content as if PHP code (with a limited set of functions)
																							//		with the input value given (sets {{@value}} for an array)
																							//	if second parameter is "cloneKey" then the output array uses the same key value
																							//		if it is "cloneValue" then the output array uses the input value as key
																							//		if it is "Map" then the output array is a map from value to input key
		$currentAtParams = $this->atParams;				// store previous settings
		if (is_array($argValues[1])) {
			$count = 0;
			$last = count($argValues[1]);
			$result = [];
			foreach ($argValues[1] as $key => $value) {
				$count++;
				$this->atParams['@first'] = ($count == 1) ? true : false;
				$this->atParams['@last'] = ($count == $last) ? true : false;
				$this->atParams['@key'] = $key;
				$this->atParams['@index'] = $key;
				if ($hashValues['asParam-2']) $this->atParams[$hashValues['asParam-2']] = $key;
				$this->atParams['@value'] = $value;
				if ($hashValues['asParam-1']) $this->atParams[$hashValues['asParam-1']] = $value;
				$exp = $this->process($value, $doBlock);
				$t = $this->expression($exp);
				if ($t !== null) {
					if ($argValues[2] == 'cloneKey') {
						$result[$key] = $t;
					} else if ($argValues[2] == 'cloneValue') {
						$result[$value] = $t;
					} else if ($argValues[2] == 'Map') {
						$result[$t] = $key;
					} else {
						$result[] = $t;
					}
				}
			}
		} else {
			$this->atParams['this'] = $argValues[1];
			$exp = $this->process(null, $doBlock);
			$result = $this->expression($exp);
		}
		$this->atParams = $currentAtParams;				// restore previous settings
		return $result;
	}

	private function do_format($params, $argValues, $hashValues, $doBlock, $elseBlock) {		//  {{format DT fmt="Y-m-d"}}	multiple arguments are concatenated with spaces (or use sep parameter)
		global $DCAPI;

		$fmt = ($hashValues['fmt']) ? $hashValues['fmt'] : 'Y-m-d H:i:s';		// default date formatting
		$sep = ($hashValues['sep']) ? $hashValues['sep'] : ' ';					// default separator is a single space

        $buffer = '';
        $s = '';
        $array_found = false;
        foreach ($argValues as $k => $v) {					// build space separated string if multiple arguments
        	if ($v) {
				if ( (is_array($v)) or (is_object($v)) ) {
					$array_found = true;
					foreach ($v as $value) {
		        		$buffer .= $s . $DCAPI->translate_datetime($value, $fmt);
		        		$s = $sep;
					}
				} else {
	        		$buffer .= $s . $v;
	        		$s = $sep;						
				}
        	}
        }
        if ($array_found) {
			return $buffer;
        } else {
			return $DCAPI->translate_datetime($buffer, $fmt);
        }
	}

	private function do_list($params, $argValues, $hashValues, $doBlock, $elseBlock) {		//  {{list DT sep="" pre="" post="" tag=""}}
																				//		multiple arguments are concenated with sep (default single space)
																				//		tag creates a matching pair of HTML tags around the content (with special heading processing)
																				//		pre and post are optional prefix and postfix
		$sep = ($hashValues['sep']) ? $hashValues['sep'] : ' ';					// default separator is a single space
		$pre = ($hashValues['pre']) ? $hashValues['pre'] : '';					// default prefix
		$post = ($hashValues['post']) ? $hashValues['post'] : '';				// default postfix
		$tag = ($hashValues['tag']) ? $hashValues['tag'] : '';					// default tag

		$buffer = '';
		$s = '';
		foreach ($argValues as $object) {
			if (is_array($object)) {
				foreach ($object as $value) {
					if ($value) {
						$buffer .= $s . $value;
						$s = $sep;				
					}
				}
			} else if (is_object($object)) {
				foreach ($object as $key => $value) {
					if ($value) {
						$buffer .= $s . $key . '=' . $value;
						$s = $sep;
					}
				}
			} else {
				if ($object) {
					$buffer .= $s . $object;
					$s = $sep;
				}
			}
		}
		$buffer = trim($buffer);
		if ($buffer) {
			if ($tag == '') {
				$buffer = wp_strip_all_tags($buffer, false);					// clear all HTML tagging
			} else if ($tag == 'a') {
				$buffer = "<$tag href='" . $buffer . "'>&rArr;</$tag> ";		// special formatting for "a" tag
			} else if ($tag == 'img') {
				$buffer = "<$tag src='" . $buffer . "' />";						// special formatting for a tag
			} else if ($tag == 'br') {
				$buffer .= "<$tag />";											// special formatting for <br> tag
			} else {
				preg_match("/h(\d)/", $tag, $m);
				if ( $m[0] ) {								// a heading tag h1..h9
					$x = intval($m[1]);
					if ($buffer != $this->headings[$x])	{			// not repeated text at same level
						$this->headings[$x] = $buffer;
						for ($i = $x+1; $i < 10; $i++) { 
							$this->headings[$i] = '';
						}
						$buffer = "<$tag>" . $buffer . "</$tag>";	
					} else {
						$buffer = '';						// never write out the same heading content twice unless reset in the meanwhile
					}		
				} else {														// all other tags
					$buffer = "<$tag>" . $buffer . "</$tag>";									
				}
			}
			$buffer = $pre . $buffer . $post;
		}
		return  $buffer;
	}

/*
 *	Internals
 */
	public function compile($input, $partialName = null) {			// tokenise input string and build "block" array for processing
		$i = $input;
		$pointerStack = [];
		if (!$partialName) {
			$this->block = [];										// reset each time compile is run
			$pointerStack[] = &$this->block;		
		} else {
			$this->partials[$partialName] = [];						// reset each time compile is run
			$pointerStack[] = &$this->partials[$partialName];		// used for storing partials	
		}
		$pointerIndex = 0;
		$ifLevel = 0;							// how many EXTRA if blocks to unwind

		while ($i) {
			preg_match("/^([^{]*)(([{]{2,3})([~]?)(!--|!|#|\/|\s*>)?)?/s", $i, $m);
			if (!$m) {																// no mustache found and no preceding string (should not occur!)
				$i = '';
			} else  if (!$m[2]) {													// no mustache but preceding string found
				if (strlen($m[0]) > 0) $pointerStack[$pointerIndex][] = array( 'function' => \DCAPI\F_STRING, 'string' => $m[0] );
				$i = substr($i, strlen($m[0]));	// move on in input string

			} else {
				if (substr($m[1], -1) == '\\') {									// the mustache was preceded by backslash
					if (strlen($m[0]) > 0) $pointerStack[$pointerIndex][] = array( 'function' => \DCAPI\F_STRING, 'string' => $m[0] );		// capture the whole string including mustache (if any)
					$i = substr($i, strlen($m[0]));	// move on in input string

					preg_match("/^([^}]*[^~}])(([~]?)([}]{2,3}))/", $i, $m);		// look for closing mustache
					if ($m[2]) {
						if (strlen($m[0]) > 0) $pointerStack[$pointerIndex][] = array( 'function' => \DCAPI\F_STRING, 'string' => $m[0] );		// capture whole string up to mustache
					}
					$i = substr($i, strlen($m[0]));	// move on in input string

				} else {
					if (strlen($m[1]) > 0) $pointerStack[$pointerIndex][] = array( 'function' => \DCAPI\F_STRING, 'string' => $m[1] );			// capture the preceding string
					$i = substr($i, strlen($m[0]));									// move on in input string

					if ( (strlen($m[3]) == 2) and ( ($m[5] == '!') or ($m[5] == '!--') ) ) {		// a comment
						if ($m[5] == '!') {
							preg_match("/^[^}]*\}\}/", $i, $m);				// look for closing mustache
							$x = 2;				// length of closing mustache
						} else {
							preg_match("/^.*--\}\}/", $i, $m);			// look for closing mustache
							$x = 4;				// length of closing mustache
						}
						if ($m) {
							$len = strlen($m[0]);
							$pointerStack[$pointerIndex][] = array( 'function' => \DCAPI\F_COMMENT, 'string' => substr($i, 0, $len-$x) );
							$i = substr($i, $len);				// move on in input string
						} else {
							$pointerStack[$pointerIndex][] = array( 'function' => \DCAPI\F_COMMENT, 'string' => $i );
							$pointerStack[$pointerIndex][] = array( 'error' => "Missing closing comment braces" );
							$i = '';
						}
						continue;
					}

					if ($m[4]) $pointerStack[$pointerIndex][] = array( 'function' => \DCAPI\F_TRIMBEFORE );								// and the trimming	before
					$endM = (strlen($m[3]) == 2) ? '}}': '}}}';						// closing mustache to look for
					$args = $this->parse_params($i, $endM, $len);					// parse forward to closing mustache

					$numArgs = [];
					$asKey = null;
					foreach ($args as $key => $value) {
						if (is_numeric($key)) {
							$numArgs[$key] = $value;
							if ($value == 'as') $asKey = $key;
							if (substr($value, 0, 1) == '|') { $firstParam = $key; $numArgs[$key] = substr($value, 1); }
							if (substr($value, -1) == '|') { $lastParam = $key; $numArgs[$key] = substr($value, 0, -1); }
							if ($asKey) unset($args[$key]);
						}
					}

					if ( ($asKey) and ($firstParam == ($asKey+1)) and ($lastParam > $firstParam) ) {
						for ($z = $firstParam; $z <= $lastParam; $z++) { 
							$y = $z - $asKey;
							$args["asParam-$y"] = '"'. $numArgs[$z] . '"';
						}
					}

					$trimFollowing = ($endM[0] == '~') ? true : false;				// must trim afterwards
					$noEndMustache = (!$endM) ? true : false;						// missing end mustache
					$params = trim(substr($i, 0, $len));							// matched params string, trimmed
					$len += strlen($endM);											// take account of ending mustache length

					if (strlen($m[3]) == 2) {
						if ($m[5] == '#') {			// block start
							$pointerStack[$pointerIndex][] = array( 'function' => \DCAPI\F_BLOCK, 'params' => $params, 'args' => $args, 'block' => [] );
							$x = count($pointerStack[$pointerIndex]) - 1;			// current index
							$pointerIndex++;
							$pointerStack[$pointerIndex] = &$pointerStack[$pointerIndex-1][$x]['block'];

						} else if ($m[5] == '/') {			// block end
							if ($args[0] == 'if') {
								while ($ifLevel > 0) {			// unwind additional if blocks
									$ifLevel--;
									$pointerIndex--;
									$x = count($pointerStack[$pointerIndex]) - 1;			// current index
									$startParams = $pointerStack[$pointerIndex][$x]['params'];
								}
							}
							$pointerIndex--;
							$x = count($pointerStack[$pointerIndex]) - 1;			// current index
							$startParams = $pointerStack[$pointerIndex][$x]['params'];
							if (strpos($startParams, $params) !== 0) {
								$pointerStack[$pointerIndex][] = array( 'error' => "Block end '$params' does not match start block '$startParams'" );
							}

						} else if (!$m[5]) {
							if ($args[0] == 'else') {		// handle else block
								$x = count($pointerStack[$pointerIndex-1]) - 1;			// current index (one level up)
								$pointerStack[$pointerIndex-1][$x]['else_block'] = [];
								$pointerStack[$pointerIndex] = &$pointerStack[$pointerIndex-1][$x]['else_block'];

								if ($args[1] == 'if') {
									$ifLevel++;			// remember to unwind the additional if block
									array_shift($args);							// remove the 'else' part
									$params = preg_replace("/(\s*else\s*)(if.*)/", "$2", $params);

									$pointerStack[$pointerIndex][] = array( 'function' => \DCAPI\F_BLOCK, 'params' => $params, 'args' => $args, 'block' => [] );
									$x = count($pointerStack[$pointerIndex]) - 1;			// current index
									$pointerIndex++;
									$pointerStack[$pointerIndex] = &$pointerStack[$pointerIndex-1][$x]['block'];
								}

							} else {						// normal mustache
								$pointerStack[$pointerIndex][] = array( 'function' => \DCAPI\F_MUSTACHE, 'params' => $params, 'args' => $args );	// single mustache	
							}
						} else {							// partial
							$pointerStack[$pointerIndex][] = array( 'function' => \DCAPI\F_PARTIAL, 'params' => $params, 'args' => $args );	// partial mustache

						}
					} else {
						if ($m[5]) $pointerStack[$pointerIndex][] = array( 'error' => "Block marker '$m[5]' not allowed in this context" );
						$pointerStack[$pointerIndex][] = array( 'function' => \DCAPI\F_MUSTACHERAW, 'params' => $params, 'args' => $args );	// triple mustache (NB: a block cannot use triple '{')
					}

					if ($noEndMustache) $pointerStack[$pointerIndex][] = array( 'error' => "Missing closing mustache braces" );
					if ($trimFollowing) $pointerStack[$pointerIndex][] = array( 'function' => \DCAPI\F_TRIMAFTER );		// and following trimming (if any)
					$i = substr($i, $len);				// move on in input string
				}
			}
		}
	}

	private function parse_params($input, &$endM, &$len) {	// scan forward until }} or }}} and ignore characters within single- or double-quoted strings, and backslash escaped characters
		$p = 0;												//	endM is matching end mustache to look for - return actual match, $len is the length of the params string before closing mustache
		$args = [];											//	returns arguments as an array
		$index = -1;

		$argsStack = [];
		$argsStack[] = &$args;
		$argsIndex = 0;

		$inString = '';					// set to string quote character if within string
		while ($p < strlen($input)) {
			if ($inString) {									// in string
				if ($input[$p] == '\\') {				// backslash escape (only valid inside a string)
					$p++;								// skip following character
					$z = $input[$p];
					$char = "\$z";
					if ($name) { $argsStack[$argsIndex][$name] .= $char; } else { $argsStack[$argsIndex][count($args)-1] .= $char; }		// append escaped backslash character 
				} else if ($input[$p] == $inString) {
					if ($name) { $argsStack[$argsIndex][$name] .= '"'; } else { $argsStack[$argsIndex][count($args)-1] .= '"'; }			// append last character
					$inString = '';						// no longer in string
				} else {
					if ($name) { $argsStack[$argsIndex][$name] .= $input[$p]; } else { $argsStack[$argsIndex][count($args)-1] .= $input[$p]; }		// append a character
				}
			} else {											// not in string
				if ( ($input[$p] == "'") or ($input[$p] == '"') ) {	
					$inString = $input[$p];				// now in string
					if ($name) { $argsStack[$argsIndex][$name] = '"'; } else { $argsStack[$argsIndex][] = '"'; }

				} else if ($input[$p] == '(') {
					$x = count($argsStack[$argsIndex]);
					$argsStack[$argsIndex][] = [];
					$argsIndex++;
					$argsStack[$argsIndex] = &$argsStack[$argsIndex-1][$x];

				} else if ($input[$p] == ')') {	
					$argsIndex--;

				} else if ( ($input[$p] == '~') and (substr($input, $p+1, strlen($endM)) == $endM) ) {
					$endM = '~' . $endM;
					$len = $p;									// found the closing mustache with trim character - return params string
					return $args;

				} else if (substr($input, $p, strlen($endM)) == $endM) {
					$len = $p;									// found the closing mustache - return params string
					return $args;

				} else {
					$i = substr($input, $p);
					preg_match("/^\s*(([a-zA-Z][\w-]*)\s*([=]))?\s*((true|false|null|undefined)|([0-9][0-9.]*)|(@?([\.]{1,2}\/)?([\|]?[a-zA-Z][\w-]*[\|]?)(\.([a-zA-Z][\w-]*|(\[.+\])))*))?[^\"'~}()\s]*/", $i, $m);
					$p += strlen($m[0]) - 1;		// string length matched
					if ($m[3]) {					// equals sign
						$name = $m[2];
					} else {
						$name = null;
					}
					if ($m[5]) {				// a constant
						$value = ($m[5] == 'true') ? true : ( ($m[5] == 'false') ? false : ( ($m[5] == 'null') ? null : 'undefined' ) );
						if ($name) { $argsStack[$argsIndex][$name] = $value; } else { $argsStack[$argsIndex][] = $value; }
					} else if ($m[6]) {			// a number
						if (strpos($m[6], '.') === false) { $value = intval($m[6]); } else { $value = floatval($m[6]); }
						if ($name) { $argsStack[$argsIndex][$name] = $value; } else { $argsStack[$argsIndex][] = $value; }
					} else if ($m[7]) {			// a context path
						$value = $m[7];
						if ($m[12]) {
							$value = preg_replace("/(\.)?\[(.*)\](\.)?/", "$1$2$3", $value);
						}
						if ($name) { $argsStack[$argsIndex][$name] = $value; } else { $argsStack[$argsIndex][] = $value; }
					}
				}
			}
			$p++;
		}
		$endM = '';
		$len = strlen($input);					// whole string scanned without finding end mustache
		return $args;
	}

	public function registerHelper($name, $function) {
		$this->helpers[$name] = $function;
	}

	public function registerPartial($name, $input) {
		$this->partials[$name] = [];
		$this->compile($input, $name);
	}

	public function process($context, $block = 'outermostBlock') {
		if (!$block) return;
		if ($block == 'outermostBlock') {
			$block = $this->block;
			$this->contextStack = [];					// keep a stack of contexts
			$this->contextTop = -1;
			$this->atParams = [];						// current "@-params"
		}
		if ($context) {
			$this->contextTop++;
			$this->contextStack[$this->contextTop] = &$context; 
			if ($this->contextTop == 0) $this->atParams['@root'] = &$context;
		}

		$result = '';
		$step = 0;
		while ($step < count($block) ) {
			$item = $block[$step];
			switch ($item['function']) {
				case \DCAPI\F_STRING:
					$answer = $item['string'];
					if ($block[$step-1]['function'] == \DCAPI\F_TRIMAFTER) $answer = ltrim($answer); 
					$result = (!$result) ? $answer : $result . $answer;
					break;
				
				case \DCAPI\F_TRIMBEFORE:
					$result = rtrim($result);
					break;
				
				case \DCAPI\F_TRIMAFTER:
					// processed by following item
					break;
				
				case \DCAPI\F_MUSTACHE:
				case \DCAPI\F_MUSTACHERAW:
					$raw = ($item['function'] == \DCAPI\F_MUSTACHE) ? false : true;
					$answer = $this->mustache($item['params'], $item['args'], $raw);
					if ($block[$step-1]['function'] == \DCAPI\F_TRIMAFTER) $answer = ltrim($answer); 
					$result = (!$result) ? $answer : $result . ( (string) $answer );
					break;
				
				case \DCAPI\F_PARTIAL:
					$partialName = $item['args'][0];		// grab the partial name
					$partialArgs = $item['args'];
//					array_shift($partialArgs);				// get the partial's arguments
					$partialArgs[0] = 'with';				// wrap the partial call in a with block
					$partialParams = $item['params'];
					$partialParams = str_replace($partialName, 'with', $partialParams);
					$answer = $this->mustache($partialParams, $partialArgs, true, $this->partials[$partialName]);
					if ($block[$step-1]['function'] == \DCAPI\F_TRIMAFTER) $answer = ltrim($answer); 
					$result = (!$result) ? $answer : $result . ( (string) $answer );
					break;
				
				case \DCAPI\F_BLOCK:
					$answer = $this->mustache($item['params'], $item['args'], true, $item['block'], $item['else_block']);
					if ($block[$step-1]['function'] == \DCAPI\F_TRIMAFTER) $answer = ltrim($answer); 
					$result = (!$result) ? $answer : $result . ( (string) $answer );
					break;			

				default:
					break;
			}
			$step++;			// move on
		}

		if ($context) $this->contextTop--;
		return $result;
	}

	private function lookup($params, $args, $arg, $returnRaw = false) {			// looks up a single argument and returns its value; if a string it is HTML-encoded unless returnRaw is true
		$result = null;
		$localContext = $this->contextStack[($this->contextTop >= 0) ? $this->contextTop : 0];		
		if ($this->atParams['this']) {
			$thisContext = $this->atParams['this'];		
		} else {
			$thisContext = $localContext;		
		}

		if ( ($arg === null) or ($arg === true) or ($arg === false) or ($arg === 'undefined') or (is_numeric($arg)) ) {
			return $arg;
		} else if ( ($arg[0] == '"') and ($arg[strlen($arg)-1] == '"') ) {
			if ($returnRaw) {
				$result = substr($arg, 1, -1);
			} else {
				$result = htmlspecialchars(substr($arg, 1, -1));
			}

		} else if ($arg == 'this') {
			$result = $thisContext;

		} else {
			$p = strpos($arg, '.');
			$a = ($p > 0) ? substr($arg, 0, $p) : $arg;
			if (strpos($arg, '@root.') === 0 ) {
				$base = $this->contextStack[0];
				$path = substr($arg, 6);

			} else if ($this->atParams[$arg] or ($this->atParams[$arg] === 0) )  {		// must follow test for '@root.'!
				return $this->atParams[$arg];

			} else if ($this->atParams[$a] )  {											// must follow test for '@root.'!
				$base = $this->atParams[$a];
				$path = substr($arg, $p+1);

			} else if ($args[$arg] or ($args[$arg] === 0) )  {					// see if there is a hash-param
				return $args[$arg];

			} else if (strpos($arg, '../') === 0 ) {
				$base = $this->contextStack[($this->contextTop > 0) ? $this->contextTop - 1 : 0];
				$path = substr($arg, 3);
			} else if (strpos($arg, './') === 0 ) {
				$base = $localContext;
				$path = substr($arg, 2);
			} else if ( (strpos($arg, 'this/') === 0 ) or (strpos($arg, 'this.') === 0 ) ) {
				$base = $thisContext;
				$path = substr($arg, 5);
			} else {
				$base = $localContext;
				$path = $arg;
			}

			// parse dot notation to access array
			$loc = &$base;
			foreach(explode('.', $path) as $part) {
				if (is_object($loc)) {
					if ( (!$part) or (!isset($loc->$part)) ) {
						$loc = '';						// return empty string if nothing matched
						break;
					}
					$loc = &$loc->$part;
				} else {
					if ( (!$part) or (!isset($loc[$part])) ) {
						$loc = '';						// return empty string if nothing matched
						break;
					}
					$loc = &$loc[$part];
				}
			}
			$result = $loc;
			if ( (is_string($result)) and (!$returnRaw) ) {
				$result = htmlspecialchars($result);			// HTML encode string
			}
		}
		return $result;
	}

	private function mustache($params, $args, $returnRaw = false, $ifBlock = null, $elseBlock = null) {
		$argValues = [];						// arguments
		$hashValues = [];						// name=value arguments
		foreach ($args as $key => $arg) {
			if (is_array($arg)) {
				if (is_integer($key)) {
					$argValues[$key] = $this->mustache($params, $arg, $returnRaw);
				} else {
					$hashValues[$key] = $this->mustache($params, $arg, $returnRaw);
				}
			} else {
				if (is_integer($key)) {
					$argValues[$key] = $this->lookup($params, $args, $arg, $returnRaw);
				} else {
					$hashValues[$key] = $this->lookup($params, $args, $arg, $returnRaw);
				}
			}
		}
		$t = $this->helpers[$args[0]];
		if ($t) {				// helper found
			unset($argValues[0]);					// skip the helper name argument!
			if (is_array($t)) {						// deal with built-in functions
				$result = $t[0]->{$t[1]}($params, $argValues, $hashValues, $ifBlock, $elseBlock);
			} elseif ($t != '') {
				$result = $t($params, $argValues, $hashValues, $ifBlock, $elseBlock);					
			}
			return $result;
		} else {
			return $argValues[0];					// return the value of the (non helper) name
		}
	}

	// DCAPI support functions
	public function do_mapping($input_field, $map) {
		$map_lines = explode("\n", trim($map));
		foreach ($map_lines as $line) {
			preg_match("/(.*)=>(.*)/", $line, $l);
			if ($l) {
				$input_value = $l[1];
				$output_value = $l[2];

				if ($input_value == '') {
					$match = true;											// empty string matches anything
				} else if ($input_value == '//') {
					$match = ($input_field == '') ? true : false;			// match empty string
				} else if (substr($input_value, 0, 1) == '/') {
					$m = [];
					preg_match($input_value, $input_field, $m);				// use regex
					$match = ($m[0]) ? true : false;
				} else {
					$match = ($input_field == $input_value) ? true : false;	// literal match
				}
				if ($match) {
					return $output_value;
				}
			}
		}
		return null;			// no match
	}

	public function expression($expr) {		// Thanks to http://codereview.stackexchange.com/questions/39454/secure-math-expressions-using-php-eval 
	    static $function_map = array(
			'true' => 'true',
			'false' => 'false',
			'and' => 'and',
			'or' => 'or',
			'date' => 'date',
			'gmdate' => 'gmdate',
			'time' => 'time',
			'mktime' => 'mktime',
			'substr' => 'substr',
			'strpos' => 'strpos',
			'floor' => 'floor',
			'ceil' => 'ceil',
			'round' => 'round',  
			'sin' => 'sin',
			'cos' => 'cos',
			'tan' => 'tan',  
			'asin' => 'asin',
			'acos' => 'acos',
			'atan' => 'atan',  
			'abs' => 'abs',
			'log' => 'log',  
			'pi' => 'pi',
			'exp' => 'exp',
			'min' => 'min',
			'max' => 'max',
			'rand' => 'rand',
			'fmod' => 'fmod',
			'sqrt' => 'sqrt',
			'deg2rad' => 'deg2rad',
			'rad2deg' => 'rad2deg',
	    );

	    // Remove any whitespace
	    $x = strtolower(preg_replace('~\s+~', '', $expr));

	    // Empty expression
	    if ($x === '') {
	        return null;
	    }

	    $x = preg_replace(array("~'[^']*'~", '~"[^"]*"~'), '', $x);				// remove all single or double quoted strings from the syntax check

	    // Illegal function
	    $x = preg_replace_callback('~\b[a-z]\w*\b~', function($match) use($function_map) {
	        $function = $match[0];
	        if (!isset($function_map[$function])) {
	            trigger_error("Illegal function '{$match[0]}'", E_USER_ERROR);
	            return '';
	        }
	        return $function_map[$function];
	    }, $x);

	    // Invalid function calls
	    if (preg_match('~[a-z]\w*(?![\(\w])~', $x, $match) > 0) {
	        trigger_error("Invalid function call '{$match[0]}'", E_USER_ERROR);
	        return null;
	    }

	    // Legal characters
	    if (preg_match('~[^-+/%*&|<>!=.()0-9a-z,?:]~', $x, $match) > 0) {
	        trigger_error("Illegal character '{$match[0]}'", E_USER_ERROR);
	        return null;
	    }       

	    return eval("return({$expr});");
	}

}

?>