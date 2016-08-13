<?php
/**
 * DCAPI API V1.2.1
 *	(2016-08-13)
 *
 *	Routes:
 *		internal routes have the form 'prefix/ID' or 'prefix'
 *		- internal route 'prefix/^feed' returns the feed items for that particular blob type (if any)
 *		external routes start with '/'
 *	 	- there are special external routes:
 *  		'/^home' points to the home page (if there is no home or home-page blob, then it points to the Wordpress start page)
 *			'/^search/s' carries out search for string s (regardless of internal or external route for searching)
 *			'/' is treated the same as '^home'
 *		wildcard routes (referring to multiple entries) have the form 'prefix/*' or '*' (internal routes only)
 *	
 *	Query options:
 *		?regen to force regeneration of the cache of one or more individual items (for wildcard routes the regeneration action is queued)
 *			?clear to clear cache (wildcard routes)
 *			?do to carry out regeneration inline until timeout, else queued (wildcard routes)
 *			?force to force setting for regeneration, else only missing or stale files are done (wildcard routes)
 *		- without ?regen just shows status (wildcard routes)
 *			?quiet to suppress "up to date" and "file missing" with ?clear (wildcard routes)
 *		?touch to get the last update time of an individual item
 *		?format=xxx to set the output format (xxx= json [default], jsonpp [pretty printed JSON], xls [XML Spreadsheet 2003], csv [semicolon separated], tsv [tab separated] or xml)
 *			alternatively append ".json" etc. to the end of the route
 *
 *	Action verbs defined:	
 * 		^backup/^restore to perform blob data backup and restore
 * 		^blob to retrieve the base blob data
 *		^cache to list status of all cache items - alternative to .../dcapi/*
 *		^clear to clear the whole cache - alternative to .../dcapi/*?clear;quiet
 *		^cron for use in a regular cron job (same functionality as ^regen, but only runs if it has to)
 *		^index to retrieve the full post/page index
 *		^info to get name of site, Wordpress, DCAPI and ACF versions etc.
 * 		^log to write out the last 20 lines of the log file (^log/nn lists the last nn lines)
 * 		^login/logout to log into Wordpress (this affects which items and fields are hidden)
 *		^regen to regenerate all missing or stale items in the cache - alternative to .../dcapi/*?regen;do (the same action that takes place normally in the background at teh end of each request)
 *		^touch to get last cache update timestamp or a given item (not wildcard routes)
 *		^user to return current logged in Wordpress user (if any)
 */

require_once('../src/DCAPI/Autoloader.php');
DCAPI\Autoloader::register();
clearstatcache();

// useful functions
function set_wordpress_context() {
	$parsed_uri = explode( 'wp-content', $_SERVER['SCRIPT_FILENAME'] );		// load Wordpress and carry on
	require_once( $parsed_uri[0] . 'wp-load.php' );
	require_once( ABSPATH . 'wp-admin/includes/file.php' );
}

function write_out_row($xls_format, $tsv_separator, $row) {						// deal with messy Excel format
	if ($xls_format) {
		echo '<Row>';
		foreach ($row as $cell) {
			echo '<Cell><Data ss:Type="String">';
			echo $cell;
			echo '</Data></Cell>';
		}
		echo '</Row>';
	} else {
		echo implode($tsv_separator, $row) . "\n";				// write out the row			
	}
}

function output_and_exit($parsed, $response, $format) {					// write out results and exit
	header('Access-Control-Allow-Origin: *');
	header('Cache-Control: max-age=0, must-revalidate'); 					// always check for a change, rely on ETag (automatic cron job runs regularly) - leave out public as unnecessary
	header('Vary: Accept-Encoding');

	if (isset($response['error'])) {
		$response['request'] = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
	}
	switch ($format) {
		case 'xls':
		case 'csv':
		case 'tsv':
			$tsv_charset = 'utf-8';	
			$tsv_separator = ($format != 'csv') ? "\t" : ";";				// NB: tab unless ".csv"
			$xls_format = ($format == 'xls') ? true : false;				// if true, then output in XML Spreadsheet 2003 format!

			if ($xls_format) {
				header("Content-Type: application/vnd.ms-excel; charset=$tsv_charset");				
				$BOM = chr(0xEF) . chr(0xBB) . chr(0xBF);
				echo $BOM;													// output "BOM"
				echo '<?xml version="1.0" encoding="UTF-8"?>';
				echo '<?mso-application progid="Excel.Sheet"?>';
				echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:c="urn:schemas-microsoft-com:office:component:spreadsheet" xmlns:html="http://www.w3.org/TR/REC-html40" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" xmlns:x2="http://schemas.microsoft.com/office/excel/2003/xml" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
				echo '<OfficeDocumentSettings xmlns="urn:schemas-microsoft-com:office:office"></OfficeDocumentSettings>';
				echo '<ss:Worksheet ss:Name="DCAPI Export">';
				echo '<Table>';
			} else {
				header("Content-Type: text/tab-separated-values; charset=$tsv_charset");
				$BOM = chr(0xEF) . chr(0xBB) . chr(0xBF);
				echo $BOM;													// output "BOM"
			}

			$request = $parsed['request'];
			$dc = new \DCAPI\Cache();
			$fn = $dc->construct_filename('', \DCAPI\CONFIGFILE);
			$blobConfig = json_decode(@file_get_contents($fn), true);		// short cut to read ^blob file (saves loading full Wordpress unnecessarily)

			$p = strpos($request, '/');	
			if ($p) {
				$r = substr($request, 0, $p+1);								// look for match of prefix with trailing '/'
			}
			$blobIndex = (isset($blobConfig['routingMap'][$request])) ? $blobConfig['routingMap'][$request] : null;		// try to find the route directly
			if ($blobIndex === null) {										// not an explicit route such as 'page/0'
				if ($p) {
					$blobIndex = (isset($blobConfig['routingMap'][$r])) ? $blobConfig['routingMap'][$r] : null;			// try to find the route prefix		
				}
			}

			$fields = null;
			if ($blobIndex) {
				$fields = $blobConfig['blobs'][$blobIndex]['exportFields'];
				if ($fields) {
					if (count($fields) == 0) $fields = null;
				}
			} else {
				if ($xls_format) {
					echo '<Row><Cell><Data ss:Type="String">XLS/CSV/TSV not valid for this blob type!</Data></Cell></Row></Table><x:WorksheetOptions/></ss:Worksheet></Workbook>';
				} else {
					echo "XLS/CSV/TSV not valid for this blob type!";				// output one single "error" cell
				}
				break;
			}
			if (!$fields) {
				if ($xls_format) {
					echo '<Row><Cell><Data ss:Type="String">no output columns defined!</Data></Cell></Row></Table><x:WorksheetOptions/></ss:Worksheet></Workbook>';
				} else {
					echo "no output columns defined!";							// output one single "error" cell
				}
				break;
			}

			// build handlebars transformation code table into copy of export fields
			$template = new \DCAPI\Handlebars();

			foreach ($fields as $fk => $field) {							// note that partial templates do not apply for CSV/TSV output
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

			// now carry out the processing of the fields - first, set up the context data
			date_default_timezone_set($blobConfig['localTimezoneString']);

			$context = [
				'blob' => $response,										// blob level
				'feed' => $response['feed'], 								// feed level
				'item' => [],												// item level (empty for now) 
				'out' => [],												// output array 
				];

			$heading = [];
			foreach ($fields as $field) {
				$heading[] = $field['column_name'];
			}
			write_out_row($xls_format, $tsv_separator, $heading);							// write out the headings	

			if (count($response['feed']['items']) < 1) {					// feed is empty
				$row = [];
				foreach ($fields as $fk => $field) {
					$o = $field['column_name'];
					$output_value = $template->run($field['code'], $context);		// run the handlebars template
					$output_value = html_entity_decode ($output_value);		// sanitise output value
					$output_value = str_replace($tsv_separator, " ", $output_value);
					$context['out'][$o] = $output_value;
					$row[] = $output_value;
				}
				write_out_row($xls_format, $tsv_separator, $row);							// write out the (single) row
			} else {
				foreach ($response['feed']['items'] as $item) {
					$context['item'] = $item;
					$context['out']= [];

					$row = [];
					foreach ($fields as $fk => $field) {
						$o = $field['column_name'];
						$output_value = $template->run($field['code'], $context);	// run the handlebars template
						$output_value = html_entity_decode ($output_value);		// sanitise output value
						$output_value = str_replace($tsv_separator, " ", $output_value);
						$context['out'][$o] = $output_value;
						$row[] = $output_value;
					}
					write_out_row($xls_format, $tsv_separator, $row);							// write out the row
				}
			}
			if ($xls_format) {
				echo '</Table><x:WorksheetOptions/></ss:Worksheet></Workbook>';
			}
			break;

		case 'xml':
			header('Content-Type: text/xml; charset=utf-8');
			$x = new Array2XML;
			$xml = Array2XML::createXML('DCAPI', $response);
			echo $xml->saveXML();
			break;

		case 'jsonpp':
			header('Content-Type: application/json; charset=utf-8');
			// jsonpp - Pretty print JSON data ( Thanks to: https://github.com/ryanuber/projects/blob/master/PHP/JSON/jsonpp.php )
			$json = json_encode($response, JSON_UNESCAPED_UNICODE);		// The JSON data, pre-encoded
			$istr = '    ';												// The indentation string (four blanks)

		    $result = '';
			for($p=$q=$i=0; isset($json[$p]); $p++) {
				$json[$p] == '"' && ($p>0 ? $json[$p-1] : '') != '\\' && $q=!$q;
				if (!$q && strchr(" \t\n\r", $json[$p])){
					continue;
				}
				if (strchr('}]', $json[$p]) && !$q && $i--) {
					strchr('{[', $json[$p-1]) || $result .= "\n".str_repeat($istr, $i);
				}
				$result .= $json[$p];
				if (strchr(',{[', $json[$p]) && !$q) {
					$i += strchr('{[', $json[$p]) === FALSE ? 0 : 1;
					strchr('}]', $json[$p+1]) || $result .= "\n".str_repeat($istr, $i);
				}
			}
			echo $result;				
			break;
		
		default:
		case 'json':														// default output content type
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode($response, JSON_UNESCAPED_UNICODE);
			break;	
	}

	// flush out buffers and finish response to user
	ignore_user_abort(true);
	if (!headers_sent()) {
		header('Connection: close');
		header('Content-Length: '.ob_get_length());
	}
	ob_end_flush();
	ob_flush();
	flush();
	exit;
}

function parse_request(&$request, &$options) {
	if (!$request) $request = '^info';
	if ($request[0] == '.') $request = '^info' . $request;
	preg_match("/^ (\^[-\w^*]+[\/]?)? ([-\w*\/]+)? ([\^]([^\.]+))? ([\.]([-\w]+))? $/x", $request, $m);

	$input = $request;											// save original request
	if ( ($request == '/') or ( ($m[6]) and (substr($request, 0, 1) == '/') ) ) {
		$request = '/'.\DCAPI\HOMEROUTE;									// allow empty external route to imply '^home'
		preg_match("/^ (\^[-\w^*]+[\/]?)? ([-\w*\/]+)? ([\^]([^\.]+))? ([\.]([-\w]+))? $/x", $request, $m);
	}
	$action = $m[1];
	$route = $m[2];
	if ( ($route == '/') or (substr($route, -1) == '/') ) {		// allow an external route to start with '/^' (as in '/^home') or deal with 'location/^feed' style (where there is no ID part)
		if ($m[4]) {
			$route .= '^' . $m[4];								
		}
	} else {
		$repKey = $m[4];										// else it is a repeating key part
		$pos = strrpos($request, '^');
		if ($pos > 0) $request = substr($request, 0, $pos);		// strip repeat key part from end of request
	}
	$params = explode('/', $route);
	$format = $m[6];
	if ($format) {
		$pos = strrpos($request, '.');
		if ($pos > 0) $request = substr($request, 0, $pos);		// strip file extension from end of request
	}

	$newOptions = [];
	foreach ($options as $option) {								// look for queries of format "format=xxx" (NB. '.xxx' notation is overridden)
		if (strpos($option, 'format=') === 0) {
			$format = substr($option, 7);
		} else if ($option) {
			$newOptions[] = $option;
		}
	}
	$options = $newOptions;										// cleaned up options string
	if ( ($action == \DCAPI\CONFIGFILE) or ($action == \DCAPI\INDEXFILE) ) {		// special cases
		$route = $action;
		$params = [ $action ];
		$request = $action;
		$action = '';
	}
	$format = strtolower($format);
	if (!$format) {
		$format = 'json';										// default format is JSON
	};
	$return = [
		'input' => $input,			// original input
		'request' => $request,		// adjusted request
		'action' => $action,		// action keyword if any (with trailing '/' if any)
		'route' => $route,			// full route
		'params' => $params,		// first part of route (without '/')
		'repKey' => $repKey,		// without leading '^'
		'format' => $format,		// without leading '.'
	];
	return $return;
}
/* ----------------------------------------------------------------------------- */

// main code starts here
$logged_in = '';									// empty string => noone is logged in
$user_hash = '';
if (count($_COOKIE)) {
	foreach ($_COOKIE as $key => $val) {
		if (substr($key, 0, 19) === "wordpress_logged_in") {	// cookie starts with login name of user before  '|'
			$c = explode('|', $val);
			$logged_in = $c[0];
			$user_hash = '-'.md5($logged_in);		// use to "salt" ETags
			break;
		}
	}
}
$utils = new \DCAPI\Utils();

$parsed_url = parse_url($_SERVER['REQUEST_URI']);
preg_match('/\/dcapi\/(.*)$/', $parsed_url['path'], $x);
$request = urldecode($x[1]);												// just the request part, sanitized

$query = ($parsed_url['query']) ? '?' . $parsed_url['query'] : '';			// the initial query part
$init_request = $request . $query;
$options = explode(';', str_replace('&', ';', $parsed_url['query']));						// replace & with ; as query separator (NB: could lead to issues)
$parsed = parse_request($request, $options);										// updates $request
$repKey = $parsed['repKey'];
$format = $parsed['format'];
$query = ($options) ? '?' . implode(';', $options) : '';					// the updated query part

// Use GZIP compression if Accept-Encoding indicates this and signal JSON output in UTF-8 encoding
$compressed = false;
if (isset($_SERVER['HTTP_ACCEPT_ENCODING'])) {
	preg_match("/gzip/", $_SERVER['HTTP_ACCEPT_ENCODING'], $test);
	if ($test[0]) {
		if (extension_loaded("zlib") && (ini_get("output_handler") != "ob_gzhandler")) {
			ini_set("zlib.output_compression", 1);
			$compressed = true;
		}
	}
}
$response = [];

if ( ($parsed['action'] == '') and ($parsed['route'][0] == '/') ) {			// an external route
	$blobCache = new \DCAPI\Cache('');
	$index = $blobCache->read_cache_file('', \DCAPI\INDEXFILE);				// try to read the index file directly first (to avoid the Wordpress load time)
	if (!index) {
		set_wordpress_context();
		$index = new \DCAPI\Index();							// get the index (using the full DCAPI access method)
	}
	$route = $parsed['route'];
	$searchRoute = '/' . \DCAPI\SEARCHROUTE . '/';
	if (substr($route, 0, strlen($searchRoute)) == $searchRoute) {					// handle special case of search route (the routes end with '/')
		$pos = strpos($route, '/', 1);					
		$routeStart = substr($route, 0, $pos+1);
		$match = $index['extRoutes'][$routeStart];
		$request = $match . substr($route, $pos+1);				// found search prefix
	} else {
		// translate an external route to an internal route
		$request = $index['extRoutes'][$route];
		if (!$request) {
			$response = array( 'error' => "route '$route' not matched", );
			output_and_exit($parsed, $response, $format);
		}		
	}
	$parsed['request'] = $request;
}

$file = realpath(__DIR__ . '/../cache/blobCache') . '/' . $request . '.json';		// derived filename (absolute path)

if ( ($parsed['action'] == \DCAPI\LOGFILE) or ($parsed['action'] == \DCAPI\LOGFILE.'/') ){
	set_wordpress_context();
	$dc = new \DCAPI\Cache('');							// regenerate all queued regenerations
	$lines = ($parsed['route']) ? intval($parsed['route']) : 20;
	$log = $dc->list_log($lines);
	output_and_exit($parsed, $log, $format);
}
if ($parsed['action'] == '^cron') {
	set_wordpress_context();
	$time = date('Y-m-d H:i:s');						// UTC
	$dc = new \DCAPI\Cache('');
	if (!$dc->is_regen_queue_empty()) {
		$m = "cron: carrying out queued actions ($time)";
		$bl = new \DCAPI\Blob('');	
		$bl->regen_all([ 'regen', 'do' ], true);		// regenerate all queued items in the background
		update_option('DCAPI_cron', "$time: updates performed", 'yes');
	} else {
		$m = "cron: no action needed ($time)";
		update_option('DCAPI_cron', "$time: no updates performed", 'yes');
	}
	echo $m;
	$dc->log_message($m);
	exit;
}
if ($parsed['action'] == '^regen') {
	set_wordpress_context();
	$bl = new \DCAPI\Blob('');
	$bl->regen_all([ 'regen', 'do' ]);
	output_and_exit($parsed, $bl->container, $format);			// regenerate all queued regenerations
}
if ($parsed['action'] == '^cache') {
	set_wordpress_context();
	$bl = new \DCAPI\Blob('');							// list status of cache
	$bl->regen_all([ ]);
	output_and_exit($parsed, $bl->container, $format);
}
if ($parsed['action'] == '^clear') {
	set_wordpress_context();
	$bl = new \DCAPI\Blob('');							// clear the whole cache (and don't announce missing files that were just deleted!)
	$bl->regen_all([ 'clear', 'quiet' ]);
	output_and_exit($parsed, $bl->container, $format);
}
if (in_array('touch', $options)) {
	$last_modified_time = (file_exists(realpath($file))) ? filemtime($file) : 4102444799; 			// beware: filemtime has problems for files over 2GB
	output_and_exit($parsed, array('timestamp' => $last_modified_time), $format);		// if no file, then returns timestamp of 2099-12-31 23:59:59
}
if ($parsed['action'] == '^info')  {
	set_wordpress_context();
	$bc = new \DCAPI\Config();
	$lastModified = $utils->getLastModified();
	unset($lastModified['latestBlob']);
	unset($lastModified['latestIndex']);
	$lastCron = get_option('DCAPI_cron');

	$bi = new \DCAPI\Index();
	output_and_exit($parsed, [
		'Site name' => $bc['siteName'],
		'GUID' => $bc['GUID'],
		'DCAPI url' => $bc['DCAPIUrl'],
		'DCAPI version' => $bc['DCAPIVersion'],
		'Wordpress version' => $bc['WordpressVersion'],
		'PHP version' => $bc['PHPVersion'],
		'ACF version' => $bc['ACFVersion'],
		'Search processor' => $bc['searchType'],
		'Timeout limit' => $bc['timeoutLimit'] . ' s',
		'Last modification times (UTC)' => $lastModified,
		'Last cron run (UTC)' => $lastCron,
	], $format);
}

if ( ($parsed['action'] == '^backup') or ($parsed['action'] == '^backup/') or ($parsed['action'] == '^restore') or ($parsed['action'] == '^restore/') ) {
	$pos = strpos($request, '/');
	if ( ($pos == false) or ($request == '^backup/') or ($request == '^restore/') ) {
		$backup = new \DCAPI\Backup();
		output_and_exit($parsed, $backup->get_status(), $format);
	} else if ($parsed['action'] == '^restore/') {
		$restore = new \DCAPI\Backup();
		$restore->restore($parsed['route']);
		output_and_exit($parsed, $restore->get_status(), $format);
	} else {
		$backup = new \DCAPI\Backup($parsed['route']);
		if ($backup->get_data()) {
			output_and_exit($parsed, $backup->get_data(), $format);
		} else {
			output_and_exit($parsed, $backup->get_status(), $format);
		}
	}
}
if ( ($parsed['action'] == '^user') or ($parsed['action'] == '^logout') or ($parsed['action'] == '^login/') )  {		// assume ^login/user/pwd
	set_wordpress_context();
	$user = new \DCAPI\User();
	if ($parsed['action'] == '^user') {
		$response = $user->current_user();
	} else if ($parsed['action'] == '^logout') {
		$user->login();					// logs out current user if any
		$response = array ( 'logout performed' => true );			// NB: need to reload page to get new setting!
	} else {
		$str = substr($request, 7);
		$pos = strpos($str, '/');
		if ($pos !== false) {
			$log = substr($str, 0, $pos);
			$pwd = substr($str, $pos+1);			
			$user->login($log, $pwd);		
		} else {
			$user->login();				// log out if poor syntax
		}
		$response = array( 'login performed' => true );				// NB: need to reload page to get new setting!
	}
	output_and_exit($parsed, $response, $format);
}

$data = @file_get_contents($file);							// try to read the file

if ( ($query != '') or ($data === false) ) {
	set_wordpress_context();

	unset($data);
	$response = new \DCAPI\Blob("$request$query");			// regenerate the file and process any other query parameters

	if ( ($response['meta']['cacheTimestamp']) and (strpos($request, '*') === false) ) {		// ['meta']['cacheTimestamp'] set means that there is a file
		unset($response);									// just carry on, ignore returned data - a file should have been generated - return the file to set the ETag header
	} else {
		$utils->handle_hidden_and_repeat($response->container, $repKey);
		output_and_exit($parsed, $response->container, $format);
	}
}

if (file_exists(realpath($file))) {
	$last_modified_time = filemtime($file); 				// beware: filemtime has problems for files over 2GB
	if ($compressed) {
		$etag = md5_file($file).$user_hash.'-'.$format.'-gzip'; 		// salt the ETag with user if logged in
	} else {
		$etag = md5_file($file).$user_hash.'-'.$format; 
	}
	header('Last-Modified: '.gmdate("D, d M Y H:i:s", $last_modified_time).' GMT'); 
	header('Etag: "' . $etag . '"'); 
	if (@strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) == $last_modified_time || trim($_SERVER['HTTP_IF_NONE_MATCH']) == $etag) { 
		header('Cache-Control: max-age=0, must-revalidate'); 					// always check for a change, rely on ETag (automatic cron job runs regularly) - leave out public as unnecessary
		http_response_code(304);							// not modified
	    exit; 
	}
	if ( 0 == filesize( $file ) ) {							// if file is empty (only true for touch file)
		$response = array('touchTimestamp' => $last_modified_time);
		output_and_exit($parsed, $response, $format);
	} else {
		if (!isset($data)) $data = @file_get_contents($file);		// read the newly cached file
		$response = json_decode($data, true);

		// deal with hidden items
		$utils->handle_hidden_and_repeat($response, $repKey);
		output_and_exit($parsed, $response, $format);									// write out the blob results in JSON format
	}
}

$response = array( 'error' => "could not parse DCAPI request '$init_request'", );
output_and_exit($parsed, $response, $format);		// write out the blob results in JSON format	

?>