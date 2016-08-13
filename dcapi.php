<?php
/**
 * Plugin Name: Data-centric API
 * Plugin URI: http://www.christie.ch/dcapi
 * Description: This plugin provides a cached data-centric view of a Wordpress site enabling Wordpress to be used as a content management system (back-end "model") for a separately implemented user interface (front-end "view" and "controller"). The data is exchanged using JSON structures that are cached in server files and served via a PHP-based API to improve front-end performance
 * Version: 1.2.1
 * Date: 2016-08-13
 * Author: David Christie
 * Author URI: http://www.christie.ch
 * Text Domain: dcapi
 * License: GPL3
 */
/*  Copyright 2015  David Christie  (email : david@christie.ch)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 3, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

require 'src/plugin-update-checker/plugin-update-checker.php';
$MyUpdateChecker = PucFactory::buildUpdateChecker(
    'http://www.christie.ch/dcapi/dcapi.json',
    __FILE__,
    'dcapi'
);

require_once('src/DCAPI/Autoloader.php');
DCAPI\Autoloader::register();

if (!class_exists('DCAPI')) {
	if ( function_exists('acf') ) {
		$a = acf();
		$v = $a->settings['version'];
		$p = strpos($v, '.');
		if (intval(substr($v, 0, $p)) < 5) {
			trigger_error("DCAPI requires at least version 5 of Advanced Custom Fields", E_USER_ERROR);
		}
	} else {
		trigger_error("DCAPI requires Advanced Custom Fields", E_USER_ERROR);
	}

	register_activation_hook( __FILE__, array( 'DCAPI', 'install' ) );

	class DCAPI {
		static function install() {	// NB: do not generate any output here
			if (get_option('DCAPI_GUID')) return;							// only install the first time around
			$restore = new \DCAPI\Backup();
			$restore->restore('Default');				

			// generate V4 GUID for the site (sets the option 'DCAPI_GUID')
			$dc = new \DCAPI\Cache('');
			$dc->generate_GUID(); 				// generate the site GUID
		}

		public function __construct() {
			global $DCAPI_version;
			$DCAPI_version = '1.2.1, 2016-08-12';
			update_option('DCAPI_version', $DCAPI_version, 'yes');

			require_once('src/acf_dcapi.php');					// include the "precompiled" ACF DCAPI fields (blobs)
			add_action( 'plugins_loaded', 'DCAPI_plugin_override' );	

// blob transformation and feed processor default settings
			$this->register_transformation('(just copy)', array($this, 'copy_item'));
			$this->register_transformation('create route', array($this, 'create_route'));
			$this->register_transformation('clean up text', array($this, 'clean_text'));
			$this->register_transformation('handle image', array($this, 'handle_image'));
			$this->register_transformation('handle gallery', array($this, 'process_gallery'));
			$this->register_transformation('handle media image', array($this, 'process_media_image'));
			$this->register_transformation('get term ID list', array($this, 'get_term_list'));
			$this->register_transformation('get term name list', array($this, 'get_term_names'));
			$this->register_transformation('convert terms to names', array($this, 'convert_terms_to_names'));
			$this->register_transformation('meta data', array($this, 'do_meta'));	

			$this->register_feed_filter('standard feed filter', array($this, 'standard_feed_filter') );

// remove unwanted menu items
			add_action('admin_menu', array($this, 'remove_admin_menu_items') );

// adjust some admin screen items for narrow screens
			add_action('admin_head', array($this, 'custom_css') );

// regenerate cache in certain circumstances
			add_action('save_post', array($this, 'regenerate_cache_on_change'), 10, 3 );
			add_action('trash_post', array($this, 'regenerate_cache_on_delete') );
			add_action('acf/save_post', array($this, 'regenerate_cache_on_options'), 20);
			add_action('edit_term', array($this, 'regenerate_cache_on_category'), 10, 3 );

// add custom button to admin menu
			add_action('admin_bar_menu', array($this, 'custom_button'), 99);

// extend field processing to update post_date (and copy from it if required)
			add_filter('wp_insert_post_data', array($this, 'insert_post_data'), 10, 2 );
			// now add filters for updating the times and dates from post_date when editing

// set up DCAPI globals and custom items
			add_action('init', array($this, 'DCAPI_init') );		
			add_action('wp_loaded', array($this, 'DCAPI_load') );		// separated from 'init' - to be sure that custom posts and taxonomies are present

// shortcode handling
			add_filter('no_texturize_shortcodes', array($this, 'shortcodes_to_exempt') );
			add_shortcode('dcapi', array($this, 'shortcode_handler') );

// special column settings
			add_action('admin_init', array($this, 'column_init') );

// register shutdown function
			add_action( 'shutdown', array($this, 'shutdown_callback') );
		}


//----------------------------------------------------------------------------- DCAPI routines

		// Register custom posts and taxonomies and then initialise DCAPI globals
		function DCAPI_init() {
			// Register custom options page 
			if( function_exists('acf_add_options_page') ) {
				\acf_add_options_page(array(
					'page_title' 	=> 'DCAPI configuration',
					'menu_title' 	=> 'DCAPI configuration',
					'menu_slug' 	=> 'dcapiconfig',
					'position'		=> 40,								/* before the blobs page */
					'capability'	=> 'administrator',						/* must be an admin */
					'redirect' 		=> false
				));
			}

			// person
			$labels = array(
				'name'                => _x( 'Blobs', 'Post Type General Name', 'dcapi' ),
				'singular_name'       => _x( 'Blob', 'Post Type Singular Name', 'dcapi' ),
				'menu_name'           => __( 'Blobs', 'dcapi' ),
				'all_items'           => __( 'All blobs', 'dcapi' ),
				'view_item'           => __( 'View blob', 'dcapi' ),
				'add_new_item'        => __( 'Add new blob', 'dcapi' ),
				'add_new'             => __( 'New blob', 'dcapi' ),
				'edit_item'           => __( 'Edit blob', 'dcapi' ),
				'update_item'         => __( 'Update blob', 'dcapi' ),
				'search_items'        => __( 'Search', 'dcapi' ),
				'not_found'           => __( 'Not found', 'dcapi' ),
				'not_found_in_trash'  => __( 'Not in the trash', 'dcapi' ),
			);
			$args = array(
				'label'               => __( 'Blob', 'dcapi' ),
				'description'         => __( 'A DCAPI blob', 'dcapi' ),
				'labels'              => $labels,
				'supports'            => array( 'page-attributes', 'title', 'editor', 'thumbnail' ),
				'taxonomies'          => array( 'category' ),
				'hierarchical'        => false,
				'public'              => true,
				'show_ui'             => true,
				'menu_icon'           => 'dashicons-images-alt',
				'show_in_menu'        => true,
				'show_in_nav_menus'   => true,
				'show_in_admin_bar'   => true,
				'menu_position'       => 41,
				'can_export'          => true,
				'has_archive'         => true,
				'exclude_from_search' => false,
				'publicly_queryable'  => true,
				'capability_type'     => 'post',
			);
			register_post_type( 'blob', $args );

			function dcapi_set_custom_post_types_admin_order($wp_query) {
				// Get the post type from the query
				$postType = $wp_query->query['post_type'];

				if ($postType == 'blob') {
					$wp_query->set('orderby', 'menu_order');
					$wp_query->set('order', 'ASC');
				}
			}
			add_filter('pre_get_posts', 'dcapi_set_custom_post_types_admin_order');
		}

		// On wp_load: initialise DCAPI globals
		function DCAPI_load() {

			// initialising the DCAPI globals must happen at end of Wordpress initialisation (else ACF may not be fully set up)
			global $DCAPI_blob_config;							// cache one global with the blob config data in it
			$DCAPI_blob_config = new \DCAPI\Config();
			global $DCAPI_index;								// cache one global with the index data in it
			$DCAPI_index = new \DCAPI\Index();	
			global $DCAPI_items;								// cache arrays of cached items indexed by prefix
			$DCAPI_items = [];
			foreach ($DCAPI_blob_config['blobs'] as $b) {
				if (!$b['blobType'] != 'search') {
					if (!$DCAPI_items[$b['prefix']]) $DCAPI_items[$b['prefix']] = new \DCAPI\ItemCache('itemCache', $b['prefix'], true);
				}
			}
			global $DCAPI_fields;								// global array of fields and handlebars templates per blob
			$DCAPI_fields = new \DCAPI\ItemCache('fieldsCache');	// store it in a special items cache subdirectory

			foreach ($DCAPI_blob_config['postTypeMap'] as $t => $b) {
				// get the ACF field references if any
				$acf_date_field = $DCAPI_blob_config['blobs'][ $b ]['acf_date_field'];
				$update_acf_date = $DCAPI_blob_config['blobs'][ $b ]['update_acf_date'];
				$acf_time_field = $DCAPI_blob_config['blobs'][ $b ]['acf_time_field'];
				$update_acf_time = $DCAPI_blob_config['blobs'][ $b ]['update_acf_time'];
				if ($acf_date_field) {											// only do anything if the ACF Date/Time field reference is present
					if ( ($acf_date_field) and ($update_acf_date) ) {
						add_filter("acf/load_value/key=$acf_date_field", array($this, 'load_post_date_ACF_date'), 10, 3);
					}
					if ( ($acf_time_field) and ($update_acf_time) ) {
						add_filter("acf/load_value/key=$acf_time_field", array($this, 'load_post_date_ACF_time'), 10, 3);
					}
				}
			}

// extend ACF field processing of blob fields	
			add_filter('acf/load_field/key=field_570e02af219e1', array($this, 'acf_load_output_type_choices') );
			add_filter('acf/load_field/key=field_570b9899d2485', array($this, 'acf_load_output_to_choices') );
			add_filter('acf/load_field/key=field_56c62e6b1265a', array($this, 'acf_load_term_choices') );
			add_filter('acf/load_field/key=field_56c62e98cbfa0', array($this, 'acf_load_term_choices') );
			add_filter('acf/load_field/key=field_553d41c218398', array($this, 'acf_load_type_choices') );
			add_filter('acf/load_field/key=field_553fd83c93f12', array($this, 'acf_load_taxonomy_choices') );
			add_filter('acf/load_field/key=field_553bf53b50eb8', array($this, 'acf_load_transformation_choices') );
			add_filter('acf/load_field/key=field_554383639218a', array($this, 'acf_load_feed_filter_choices') );
			add_filter('acf/load_field/key=field_56c62e95cbf9f', array($this, 'acf_load_revealrole_choices') );
			add_filter('acf/load_field/key=field_562d42c080a7a', array($this, 'acf_load_terms_field_choices') );
			add_filter('acf/load_field/key=field_562d436e80a7b', array($this, 'acf_load_terms_field_choices') );			
			add_filter('acf/load_field/key=field_554380eacd40b', array($this, 'acf_load_feed_blob_choices') );			
			add_filter('acf/load_field/key=field_5543de13b2844', array($this, 'acf_load_repeating_field_choices') );
			add_filter('acf/load_field/key=field_576e8b5ca08ea', array($this, 'acf_load_term_choices') );					// target term

			add_filter('acf/validate_value/key=field_56c62e6b12659', array($this, 'acf_validate_source_path'), 10, 4);		// check source path
			add_filter('acf/load_field/key=field_571d1f590d16f', array($this, 'acf_load_source_blob_choices') );			// source blob		
			add_filter('acf/load_field/key=field_576e8ae5a08e9', array($this, 'acf_load_source_term_choices') );			// source term		
		}

		// shutdown function
		function shutdown_callback() {
			// do following code in the background
			global $DCAPI_blob_config;
			if (!$DCAPI_blob_config['cronUpdate']) {
				$dc = new \DCAPI\Cache('');
				if (!$dc->is_regen_queue_empty()) {
					$bl = new \DCAPI\Blob('');	
					$bl->regen_all([ 'regen', 'do' ], true);						// regenerate all queued items in the background 
				}
			}
		}
			
		// remove unwanted admin menu items
		function remove_admin_menu_items() {
			if ( ! current_user_can('administrator') ) {
				$remove_menu_items = array(__('Blobs')); 
			} else {
				return;
			}
			global $menu;
			end ($menu);
			while (prev($menu)){
				$item = explode(' ',$menu[key($menu)][0]);
				if(in_array($item[0] != NULL?$item[0]:"" , $remove_menu_items)){
				unset($menu[key($menu)]);}
				}
			}

		// adjust some admin screen items for narrow screens and override spacing on ACF edit form for media in links list
		function custom_css() {

echo '<style type="text/css">

.column-new-date { width: 15%; }
.acf-file-uploader .file-info {
	padding: 10px;
	margin-left: 130px;		/* was 69px */
}

@media only screen and ( ((min-device-width : 320px) and (max-device-width : 568px))	/* iPhone 5/5s */ 
				  or ((min-device-width : 375px) and (max-device-width : 667px)) )	/* iPhone 6 */
{
	.widefat a, .widefat td, .widefat td ol, .widefat td p, .widefat td ul {
		font-size: 11px;
	}
	input[type=checkbox], input[type=radio] {
		height: 12px;
		width: 12px;
	}
	input[type=radio]:checked:before {
		width: 6px;
    	height: 6px;
    	margin: 4px;
	}
	#wpbody select,
	.acf-input-wrap input,
	.wp-admin select
	{
		height: 24px;
    	font-size: 11px;
	}
	.widefat tfoot td input[type=checkbox]:before, .widefat th input[type=checkbox]:before, 
	.widefat thead td input[type=checkbox]:before, input[type=checkbox]:checked:before {
		font: 400 20px/1 dashicons;
    	margin: -4px -5px;
	}
	.postbox .inside, .stuffbox .inside,
	#poststuff .stuffbox>h3, #poststuff h2, #poststuff h3.hndle,
	.misc-pub-section>a,
	ul.acf-radio-list li, ul.acf-checkbox-list li,
	.acf-field .acf-label p,
	.acf-field .acf-label label,
	div.acf-label label,
	.acf-field input[type="text"], .acf-field input[type="password"], .acf-field input[type="number"], 
	.acf-field input[type="search"], .acf-field input[type="email"], .acf-field input[type="url"], 
	.acf-field textarea, .acf-field select,
	input, textarea
	{
		font-size: 11px;
	}
	.misc-pub-section {
		padding: 5px 5px;
	}
}

</style>';
		}

/*
 *		Add in shortcode processing
 *
 *			[dcapi] ... [/dcapi]		- transforms the block between the shortcodes using handlebars (freestanding format [dcapi ...] not processed)
 *
 */
		// Register DCAPI shortcode to be exempted from WP texturize
		function shortcodes_to_exempt( $shortcodes ) {
    		$shortcodes[] = 'dcapi';
			return $shortcodes;
		}

		function shortcode_handler($atts, $content, $tag) {		// [dcapi] ... [/dcapi] or [dcapi items] ... [/dcapi] or [dcapi context="items"] ... [/dcapi] - iterate through feed items (use {{item.xxx}})
																//	[dcapi blob] ... [/dcapi] or [dcapi context="blob"] ... [/dcapi] - call once with blob as context (use {{blob.xxx}})
																//	[dcapi feed] ... [/dcapi] or [dcapi context="feed"] ... [/dcapi] - call once with feed as context (use {{feed.xxx}})
			global $DCAPI_blob_config;
			global $post;
			$blobIndex = $DCAPI_blob_config['postTypeMap'][$post->post_type];
			$prefix = $DCAPI_blob_config['blobs'][$blobIndex]['prefix'];
			$request = $prefix . '/' . $post->ID;
			$blob = new \DCAPI\Blob("$request");				// regenerate the file and process any other query parameters
			$handlebars = new \DCAPI\Handlebars();

			$o = '';											// gather up output
			if ($content) {			// feed context (if any)
				$handlebars->compile($content);
				if ( (!$atts) or ($atts[0] == 'items') or ($atts['context'] == 'items') ) {
					foreach ($blob['feed']['items'] as $item) {
						$o .= $handlebars->process([ 'request' => $request, 'item' => $item, ]);		// run the handlebars template with blob feed item as context
					}
				} else if ( ($atts[0] == 'blob') or ($atts['context'] == 'blob') ) {
					$o = $handlebars->process([ 'request' => $request, 'blob' => $blob->container, ]);	// run the handlebars template with blob as context
				} else if ( ($atts[0] == 'feed') or ($atts['context'] == 'feed') ) {
					$o = $handlebars->process([ 'request' => $request, 'feed' => $blob['feed'], ]);	// run the handlebars template with blob feed as context
				}
			}	
			return $o;				
		}

		// utility conversion routine
		public function translate_datetime($inp, $fmt) {	// $inp is a date/time in string format. If entirely numeric, then incoming Unix timestamp is assumed
			if (!$fmt) {									// $fmt is assumed to be a PHP date function string
				return $inp;								// prefixing D, l, F or M formatting with '@' translates the abbreviation into German!
			}
			if (!is_string($inp)) return '';

			date_default_timezone_set(get_option('timezone_string'));
			$inp = trim($inp);

			// try Unix timestamp (full string must be just numeric)
			preg_match("/^\d+$/", $inp, $t);
			if ( ($t) and ($t !== '') ) {
				// a timestamp (Unix time in GMT timezone)
				$x = intval($inp);
			} else {

				// try Wordpress/ISO date_format() 	"yyyy-mm-dd hh:mm:ss"
				preg_match("/^((\d{4})-(\d{1,2})-(\d{1,2}))?( |T|t)?((\d{1,2}):(\d{2})(:(\d{2}))?)?/", $inp, $m);		// parse the time
				if ($m[0]) {
					$year = ($m[2]) ? $m[2] : 0;
					$month = ($m[3]) ? $m[3] : 0;
					$day = ($m[4]) ? $m[4] : 0;
					$hour = ($m[7]) ? $m[7] : 0;
					$minute = ($m[8]) ? $m[8] : 0;
					$second = ($m[10]) ? $m[10] : 0;
					$x = mktime($hour, $minute, $second, $month, $day, $year);
				} else {
					// try European date_format() 	"dd.mm.yyyy hh:mm:ss"
					preg_match("/^((\d{1,2})\.(\d{1,2})\.(\d{4}))?( |T|t)?((\d{1,2}):(\d{2})(:(\d{2}))?)?/", $inp, $m);		// parse the time
					if ($m[0]) {
						$year = ($m[4]) ? $m[4] : 0;
						$month = ($m[3]) ? $m[3] : 0;
						$day = ($m[2]) ? $m[2] : 0;
						$hour = ($m[7]) ? $m[7] : 0;
						$minute = ($m[8]) ? $m[8] : 0;
						$second = ($m[10]) ? $m[10] : 0;
						$x = mktime($hour, $minute, $second, $month, $day, $year);
					} else {
						// try US date_format() 	"mm/dd/yyyy hh:mm:ss"
						preg_match("/^((\d{1,2})\/(\d{1,2})\/(\d{4}))?( |T|t)?((\d{1,2}):(\d{2})(:(\d{2}))?)?/", $inp, $m);		// parse the time
						if ($m[0]) {
							$year = ($m[4]) ? $m[4] : 0;
							$month = ($m[2]) ? $m[2] : 0;
							$day = ($m[3]) ? $m[3] : 0;
							$hour = ($m[7]) ? $m[7] : 0;
							$minute = ($m[8]) ? $m[8] : 0;
							$second = ($m[10]) ? $m[10] : 0;
							$x = mktime($hour, $minute, $second, $month, $day, $year);
						} else {
							// try "funny" WP date_format() 	"yyyymmdd hh:mm:ss"
							preg_match("/^((\d{4})(\d{2})(\d{2}))?( |T|t)?((\d{1,2}):(\d{2})(:(\d{2}))?)?/", $inp, $m);			// parse the time
							if ($m[0]) {
								$year = ($m[2]) ? $m[2] : 0;
								$month = ($m[3]) ? $m[3] : 0;
								$day = ($m[4]) ? $m[4] : 0;
								$hour = ($m[7]) ? $m[7] : 0;
								$minute = ($m[8]) ? $m[8] : 0;
								$second = ($m[10]) ? $m[10] : 0;
								$x = mktime($hour, $minute, $second, $month, $day, $year);
							} else {
								return '';				// if no datetime field then return empty value 
							}
						}
					}
				}
			}
			if ($fmt == 'U') {
				return intval(date($fmt, $x));			// if timestamp then return as integer
			} else {
				$r = date($fmt, $x);					// if not timestamp perform additional mapping if any
				$r = str_replace(array(
					'@Monday', '@Tuesday', '@Wednesday', '@Thursday', '@Friday', '@Saturday', '@Sunday',			// allow "@l" to return German day of week
					'@Mon', '@Tue', '@Wed', '@Thu', '@Fri', '@Sat', '@Sun',											// allow "@D" to return German day of week
					'@January', '@February', '@March', '@April', '@May', '@June', 
						'@July', '@August', '@September', '@October', '@November', '@December',						// allow "@F" to return German month
					'@Jan', '@Feb', '@Mar', '@Apr', '@May', '@Jun', '@Jul', '@Aug', '@Sep', '@Oct', '@Nov', '@Dec',	// allow "@M" to return German month
					), array(
					'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag',
					'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So',
					'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember', 
					'Jan', 'Feb', 'Mrz', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez', 
					), $r);	
				return $r;
			}
		}

		private function get_array_item($m, $input_field) {			// $m starts with '->'
			if ( ($m) and is_array($input_field) ) {
				return $input_field[substr($m, 5)];					// skip '-&gt;' at start of array index
			} else {
				return $input_field;
			}
		}

/*
 *		Set up special columns on the Back End
 */
		function column_init() {
			global $DCAPI_blob_config;

			if ( ($DCAPI_blob_config['backend']['date_status_column_title']) or ($DCAPI_blob_config['backend']['info_column_title']) ) {
				add_filter('manage_posts_columns', array($this, 'columns_head') );
				add_action('manage_posts_custom_column', array($this, 'columns_content'), 10, 2);
				add_filter('manage_edit-post_sortable_columns', array($this, 'column_register_sortable') );
				add_filter('request', array($this, 'column_orderby') );

				add_filter('manage_pages_columns', array($this, 'columns_head') );
				add_action('manage_pages_custom_column', array($this, 'columns_content'), 10, 2);
				add_filter('manage_edit-page_sortable_columns', array($this, 'column_register_sortable') );
				add_filter('request', array($this, 'column_orderby') );
			}
		}

		// put info on the manage pages page
		function columns_head($columns) {
			global $DCAPI_blob_config;

			if ($DCAPI_blob_config['backend']['date_status_column_title']) {
				unset( $columns['date'] );
				$columns['DCAPI_date_status'] = $DCAPI_blob_config['backend']['date_status_column_title'];				
			}
			if ($DCAPI_blob_config['backend']['info_column_title']) {
				$columns['DCAPI_info'] = $DCAPI_blob_config['backend']['info_column_title'];				
			}
			return $columns;
		}
		function columns_content($column, $post_ID) {
			global $DCAPI_blob_config;
			global $DCAPI_items;
			$post = get_post($post_ID);

			switch ( $column ) {
			case 'DCAPI_date_status' :
				if ($DCAPI_blob_config['backend']['date_status_column_title']) {
					if ($post->post_type == 'blob') {
						echo $post->post_modified . '&nbsp; <em>' . $post->post_status . '</em>';
					} else {
						$b = $DCAPI_blob_config['blobs'][ $DCAPI_blob_config['postTypeMap'][$post->post_type] ];		
						$item = $DCAPI_items[$b['prefix']][$post_ID];
						echo $item['backend']['date_status'];					
					}
				}
				break;

			case 'DCAPI_info' :
				if ($DCAPI_blob_config['backend']['info_column_title']) {
					if ($post->post_type == 'blob') {
						$blobIndex = $DCAPI_blob_config['IDMap'][$post->ID];
						$bt = $DCAPI_blob_config['blobs'][$blobIndex]['blobType'];
						if ($bt == 'clone') {
							$sp = $DCAPI_blob_config['blobs'][$blobIndex]['sourcePath'];
							$sb = $DCAPI_blob_config['blobs'][$blobIndex]['sourceBlob'];
							$st = $DCAPI_blob_config['blobs'][$blobIndex]['sourceTerm'];
							$tt = $DCAPI_blob_config['blobs'][$blobIndex]['targetTerm'];
							echo '<b>' . $bt . '</b> | ' . $sp . ' (' . $sb . ', ' . $st .  ') | ' . $tt . '<br />';
						} else {
							$pt = $DCAPI_blob_config['blobs'][$blobIndex]['postType'];
							$tx = $DCAPI_blob_config['blobs'][$blobIndex]['taxonomy'];
							echo '<b>' . $bt . '</b> | ' . $pt . ( ($tx) ? ' | ' . $tx : '' ) . '<br />';
						}
						$p = $DCAPI_blob_config['blobs'][$blobIndex]['prefix'];
						$s = $DCAPI_blob_config['blobs'][$blobIndex]['style'];
						$f = $DCAPI_blob_config['blobs'][$blobIndex]['fixedID'];
						echo '<em>' . $p;
						if ($f == null) {
							if ($s) echo '/:' . $s;
						} else {
							echo '/' . $f;
						}
						$g = $DCAPI_blob_config['blobs'][$blobIndex]['generateRoute'];
						if ($g) {
							$p = $DCAPI_blob_config['blobs'][$blobIndex]['routePrefix'];
							$s = $DCAPI_blob_config['blobs'][$blobIndex]['routeStyle'];
							echo '<br />' . ( ($p) ? '/' . $p : '') . ( ($s) ? '/:' . $s : '' );
						}
						echo '</em>';
					} else {
						$b = $DCAPI_blob_config['blobs'][ $DCAPI_blob_config['postTypeMap'][$post->post_type] ];		
						$item = $DCAPI_items[$b['prefix']][$post_ID];
						echo $item['backend']['info'];	
					}				
				}
				break;

			}
		}
		function column_register_sortable( $columns ) {
			global $DCAPI_blob_config;

			if ($DCAPI_blob_config['backend']['date_status_column_title']) {
		    	$columns['DCAPI_date_status'] = 'DCAPI_date_status';
		    }
		    return $columns;
		}
		function column_orderby( $vars ) {
			global $DCAPI_blob_config;

			if ($DCAPI_blob_config['backend']['date_status_column_title']) {
			    if ( isset( $vars['orderby'] ) && 'DCAPI_date_status' == $vars['orderby'] ) {
			        $vars = array_merge( $vars, array(
			            'orderby' => 'date'
			        ) );
			    }
			}
		    return $vars;
		}

/*
 *		Routine for synchronising the post_date with specified ACF fields
 */
		function insert_post_data( $data , $postarr ) {
			global $DCAPI_blob_config;

			$blob_index = $DCAPI_blob_config['postTypeMap'][$data['post_type']];
			$acf_date_field = $DCAPI_blob_config['blobs'][ $blob_index ]['acf_date_field'];
			$acf_time_field = $DCAPI_blob_config['blobs'][ $blob_index ]['acf_time_field'];

			if ($acf_date_field) {											// only do anything if ACF Date/Time field reference is present
				$post_start_date = trim($postarr['acf'][$acf_date_field]);	// get the new date/time NB: must be in correct format (ts, yyyymmdd, dd.mm.yyyy yyyy-mm-dd, mm/dd/yyyy)
				$post_start_time = trim($postarr['acf'][$acf_time_field]);	// get the new time NB: must be in correct format (hh:mm, hh:mm:ss)

				if ($post_start_date) {
					$date_time = $post_start_date .  ( ($acf_time_field) ? ( ($post_start_time) ? ' ' . $post_start_time : ' 00:00:00' ) : '');		// get a date/time string
					$new_date = $this->translate_datetime($date_time, 'Y-m-d H:i:s');						//  local time

					if ($new_date) {
						$data['post_date'] = $new_date;
						$data['post_date_gmt'] = get_gmt_from_date($new_date);					
					}
				}
			}
		    return $data;
		}

		// handle updating of ACF date/time from post_date field
		function load_post_date_ACF_date($value, $post_id, $field) {
			global $DCAPI_blob_config;

			$post = get_post($post_id);
			$blob_index = $DCAPI_blob_config['postTypeMap'][$post->post_type];
			$date_format = trim($DCAPI_blob_config['blobs'][ $blob_index ]['update_acf_date']);
			if ( ($date_format) and ($post->post_status != 'auto-draft') ) {
			    $r = $this->translate_datetime($post->post_date, $date_format);
			    return ($r) ? $r : $value;
			}
		}

		function load_post_date_ACF_time($value, $post_id, $field) {
			global $DCAPI_blob_config;

			$post = get_post($post_id);
			$blob_index = $DCAPI_blob_config['postTypeMap'][$post->post_type];
			$time_format = trim($DCAPI_blob_config['blobs'][ $blob_index ]['update_acf_time']);
			if ( ($time_format) and ($post->post_status != 'auto-draft') ) {
			    $r =  $this->translate_datetime($post->post_date, $time_format);	
			    return ($r) ? $r : $value;
			}
		}

/*
 *		Regenerate the cache when saving pages and posts!
 */
		function redo_taxonomy_pages($term_list) {				// given an array of taxonomy IDs, regenerate home page and affected pages
			global $DCAPI_blob_config;
			global $DCAPI_index;
			global $DCAPI_items;

			$pageBlob = $DCAPI_blob_config['blobTypeMap']['page'];
			$pagePrefix = $DCAPI_blob_config['blobs'][$pageBlob]['prefix'];

			if ($DCAPI_blob_config['blobTypeMap']['home']) {
				$homeBlob = $DCAPI_blob_config['blobTypeMap']['home'];
				$fixedID = $DCAPI_blob_config['blobs'][$homeBlob]['fixedID'];		// NB: has same prefix as the pages
			}

			$pages = [];	
			if ($term_list) {
				$terms = [];
				foreach ($term_list as $term_id) {
					$terms = array_merge($terms, [ $term_id ], $DCAPI_index['termInfo'][$term_id]['parentTerms']);		// collect all relevant term_ids
				}
				$relevantTerms = [];
				foreach ($terms as $t) {
					if (!in_array($t, $relevantTerms)) $relevantTerms[] = $t;
				}
				$itemList = $DCAPI_items[$pagePrefix]->get_all();					// only look at pages
				foreach ($itemList as $id => $entry) {
					if ($id == 'meta') continue;

					$a = array_intersect($relevantTerms, $entry[\DCAPI\FEED_TERMS]);
					if (count($a) > 0) $pages[] = $id;
				}
			}
			if ( ($fixedID) and (!in_array($fixedID, $pages)) ) $pages[] = $fixedID;			// always update the home page (if any)

			$x = new \DCAPI\Cache('');
			foreach ($pages as $page) {
				$x->queue_for_regen($pagePrefix, $page);	// force regeneration of relevant page and related feed caches next time they are referenced, by clearing the cache file
			}
		}

		function redo_changed_item($post_id) {
			global $DCAPI_blob_config;
			global $DCAPI_index;
			global $DCAPI_fields;

			$post = get_post($post_id);
			if ($post->post_type == 'blob') {
				date_default_timezone_set('UTC');
				update_option('DCAPI_blob_lastModified', date('Y-m-d H:i:s'), 'yes');			// record last modification date/time

				// force rebuild of handlebars templates and field processing
				$DCAPI_fields->unset_all();
				$DCAPI_blob_config->regen(); 					// regenerate the blob cache
				$DCAPI_index->regen(); 							// regenerate the index cache

			} else if ($post->post_type == 'page') {					// page change
				date_default_timezone_set('UTC');
				update_option('DCAPI_page_lastModified', date('Y-m-d H:i:s'), 'yes');			// record last modification date/time

				$blobIndex = $DCAPI_blob_config['postTypeMap'][$post->post_type];
				$prefix = $DCAPI_blob_config['blobs'][$blobIndex]['prefix'];
				$x = new \DCAPI\Blob("$prefix/$post->ID?regen");								// regenerate the item itself
				unset($x);
				$x = new \DCAPI\Cache('');
				$x->queue_for_regen('', \DCAPI\INDEXFILE); 		// queue the index for regeneration
				
			} else {											// "post" change
				date_default_timezone_set('UTC');
				update_option('DCAPI_post_lastModified', date('Y-m-d H:i:s'), 'yes');			// record last modification date/time

				$blobIndex = $DCAPI_blob_config['postTypeMap'][$post->post_type];
				$prefix = $DCAPI_blob_config['blobs'][$blobIndex]['prefix'];
				$x = new \DCAPI\Blob("$prefix/$post->ID?regen");					// regenerate the item itself
				unset($x); 
				$x = new \DCAPI\Cache('');
				$x->queue_for_regen('', \DCAPI\INDEXFILE); 		// queue the index for regeneration
				$t = get_object_taxonomies($post->post_type);
				$term_list = wp_get_object_terms( $post->ID, $t, array('fields' => 'ids'));
				$this->redo_taxonomy_pages($term_list);			// regenerate affected pages
			}
			return;
		}

		function regenerate_cache_on_change( $post_id, $post, $update ) {
			$this->redo_changed_item($post_id);
		}

		function regenerate_cache_on_delete($post_id) {
			$this->redo_changed_item($post_id);
		}

		function regenerate_cache_on_options($post_id) {
			global $DCAPI_blob_config;		// cache one global with the blob config data in it
			if ($post_id == 'options') {								// update to the front page or other settings
				date_default_timezone_set('UTC');
				update_option('DCAPI_config_lastModified', date('Y-m-d H:i:s'), 'yes');			// record last modification date/time

				$DCAPI_blob_config->regen();							// regenerate the blob data for good measure
				foreach ($DCAPI_blob_config['blobs'] as $blob) {		// regenerate only those blobs with postType 'option'
					if ( ($blob['isCached']) and ($blob['postType'] == 'option') ) {
						$route = ( ($blob['cachedir']) ? $blob['cachedir'].'/' : '' ) . $blob['cachename'];
						$x = new \DCAPI\Blob("$route?regen");
					}
				}
			} else {
				return;    									// otherwise just return
			}
		}

		function regenerate_cache_on_category( $term_id, $tt_id, $taxonomy ){
			global $DCAPI_index;
			date_default_timezone_set('UTC');
			update_option('DCAPI_term_lastModified', date('Y-m-d H:i:s'), 'yes');			// record last modification date/time

			$DCAPI_index->regen(); 							// regenerate the index cache
			$this->redo_taxonomy_pages( [ $term_id ] );				// regenerate affected pages
		}

		// add custom button to admin menu
		function custom_button($wp_admin_bar){
			global $DCAPI_blob_config;
			$SN = $DCAPI_blob_config['siteName'];
			$args = array(
				'id' => 'custom-button',
				'title' => "Regenerate $SN cache",
				'href' => get_option('siteurl') . '/wp-content/plugins/dcapi/&#94;regen',	// replace caret '^' with HTML entity code '&#94;' else it doesn't work
				'meta' => array( 'class' => 'custom-button-class', ),
			);
			$wp_admin_bar->add_node($args);
		}

//
// blob processing
		function acf_load_output_type_choices( $field ) {
			global $DCAPI_blob_config;
		    // reset choices
		    $field['choices'] = [									// always allow these settings
		    	'normal' => 'normal',
		    	'hidden' => 'hidden',
		    	];
			$post_id = (int) get_the_ID();
			$blobType = ($post_id) ? $DCAPI_blob_config['blobs'][ $DCAPI_blob_config['IDMap'][$post_id] ]['blobType'] : 'option';
			if ( (!$post_id) or ($blobType == 'post') or ($blobType == 'clone') ) {
				$field['choices']['repeat'] = 'repeat';
			}
			if (!$post_id) {										// DCAPI config fields only used by ^index processing
				$field['choices']['postInfo'] = 'postInfo';
				$field['choices']['termInfo'] = 'termInfo';
			}
			$field['choices']['template'] = 'template';		
			if ((!$post_id) or ($blobType == 'page') or ($blobType == 'post') ) {	// back end fields only used for DCAPI configuration, posts and pages
				$field['choices']['back end'] = 'back end';
			}
			if ( ($post_id) and ($blobType == 'clone') ) {								// clone fields only used for clone blobs
				$field['choices']['clone'] = 'clone';
			}

		    // return the field
		    return $field;  
		}

		function acf_load_output_to_choices( $field ) {
			global $DCAPI_blob_config;
		    // reset choices
		    $field['choices'] = array('blob' => 'blob');			// always allow 'blob'
			$post_id = (int) get_the_ID();
			$blobType = ($post_id) ? $DCAPI_blob_config['blobs'][ $DCAPI_blob_config['IDMap'][$post_id] ]['blobType'] : 'option';
			if ( ($blobType == 'option') or ($blobType == 'page') or ($blobType == 'post') or ($blobType == 'clone') ) {
				$field['choices']['feed'] = 'feed';
			}

		    // return the field
		    return $field;  
		}

		function acf_load_revealrole_choices( $field ) {
		    // reset choices

		    $field['choices'] = [];
		    $roles = new WP_Roles();
		    $names = $roles->get_names();

			foreach ($names as $name) {
				if ($name != 'Administrator') {
					$field['choices'][ $name ] = $name;					
				}
		    }
		    // return the field
		    return $field;  
		}

		function acf_load_term_choices( $field ) {							// list local terms
		    // reset choices
			global $DCAPI_index;

		    $field['choices'] = [];
			foreach ($DCAPI_index['termInfo'] as $term => $tval) {
				$field['choices'][ $tval['longName'] ] = $tval['longName'];
		    }
		    ksort($field['choices']);
		    // return the field
		    return $field;  
		}

		function acf_validate_source_path ($valid, $value, $field, $input){
			if (!$valid) { return $valid; }									// bail early if value is already invalid		
			if ($value == '') { $valid = true; return $valid; }				// empty path is OK => local site

			// try to load source ^index
			try {
				$file_data = @file_get_contents($value . \DCAPI\INDEXFILE);	// ignore the warning message that sometimes occurs
				$source_index = json_decode($file_data, true);				// get the file contents
				if (!$source_index['termInfo']) $valid = 'Problem with source DCAPI site';		// check if termInfo present
			} catch (Exception $e) {
				$valid = 'Could not access source DCAPI site';
			}		
				
			// return
			return $valid;			
		}

		function acf_load_source_blob_choices( $field ) {
		    // reset choices
			$post_id = (int) get_the_ID();
			$source_path = get_field('source_path', $post_id);
			$source_blob = false;
			if ($source_path) {
				try {
					$file_data = @file_get_contents($source_path . \DCAPI\CONFIGFILE);	// ignore the warning message that sometimes occurs
					$source_blob = json_decode($file_data, true);			// return the file contents			
				} catch (Exception $e) {
					$source_blob = false;
				}
			} else {														// empty string -> local
				global $DCAPI_blob_config;
				$source_blob = $DCAPI_blob_config;
			}

			if (!$source_blob) {
		    	$field['choices']['(bad source path)'] = '(bad source path)';
			} else {
			    $flds = [];
			    foreach ($source_blob['blobs'] as $blob) {
			    	$p = $blob['prefix'];
			    	if ($blob['blobType'] == 'post') {
			    		$flds[$p] = $p; 					// allow any post blob (prefix)
			    	}
			    }
				sort($flds);

			    $field['choices'] = [];
				foreach ($flds as $f) {
					$field['choices'][ $f ] = $f;
			    }
			}
		    // return the field
		    return $field;  
		}

		function acf_load_source_term_choices( $field ) {					// list remote terms
		    // reset choices
			$post_id = (int) get_the_ID();
			$source_path = get_field('source_path', $post_id);
			$source_index = false;
			if ($source_path) {
				try {
					$file_data = @file_get_contents($source_path . \DCAPI\INDEXFILE);	// ignore the warning message that sometimes occurs
					$source_index = json_decode($file_data, true);			// return the file contents			
				} catch (Exception $e) {
					$source_index = false;
				}
			} else {														// empty string -> local
				global $DCAPI_index;
				$source_index = $DCAPI_index;
			}
		    $field['choices'] = [];
			if (!$source_index) {
		    	$field['choices']['(bad source path)'] = '(bad source path)';
			} else {
				if ($source_index['termInfo']) foreach ($source_index['termInfo'] as $term => $tval) {
					$field['choices'][ $tval['longName'] ] = $tval['longName'];
			    }
			    ksort($field['choices']);			
			}
		    // return the field
		    return $field;  
		}

		function acf_load_terms_field_choices( $field ) {
		    // reset choices
			global $DCAPI_blob_config;
			$post_id = (int) get_the_ID();

		    $flds = [];
		    $flds['(anything)'] = '(anything)';

			$blobIndex = $DCAPI_blob_config['IDMap'][$post_id];
			$fld = $DCAPI_blob_config['blobs'][$blobIndex]['fields']['standard'];		// get fields info for current blob
			if ($fld) {
				foreach ($fld as $value) {
					$o = $value['output_field'];
					if ( ($o) and ($value['output_type'] == 'normal') ) {
						$flds[$o] = $o; 	// allow any output fields
					}					
				}
				sort($flds);
			}
			
		    $field['choices'] = [];
			foreach ($flds as $f) {
				$field['choices'][ $f ] = $f;
		    }
		    // return the field
		    return $field;  
		}

		function acf_load_feed_blob_choices( $field ) {
		    // reset choices
			global $DCAPI_blob_config;
			$post_id = (int) get_the_ID();

		    $flds = [];
			$blobIndex = $DCAPI_blob_config['IDMap'][$post_id];
			$blobType = $DCAPI_blob_config['blobs'][$blobIndex]['blobType'];		// get current blob type

			if ( ($blobType == 'page') or ($blobType == 'home') ) {
			    $flds['(param/posts)'] = '(param/posts)';
			    foreach ($DCAPI_blob_config['blobs'] as $blob) {
			    	$p = $blob['prefix'];
			    	if ( ($blob['blobType'] == 'post') or ($blob['blobType'] == 'clone') ) {
			    		$flds[$p] = $p; 					// allow any post blob (prefix)
			    	}
			    }
			} else if ($blobType == 'search') {
		   		$flds['(search)'] = '(search)';				
			}
			sort($flds);

		    $field['choices'] = [];
			foreach ($flds as $f) {
				$field['choices'][ $f ] = $f;
		    }
		    // return the field
		    return $field;  
		}

		function acf_load_repeating_field_choices( $field ) {
		    // reset choices
			global $DCAPI_blob_config;
			$post_id = (int) get_the_ID();

		    $flds = [];
			$blobIndex = $DCAPI_blob_config['IDMap'][$post_id];
			$fld = $DCAPI_blob_config['blobs'][$blobIndex]['fields']['standard'];		// get fields info for current blob
			if ($fld) {
				foreach ($fld as $value) {
					$o = $value['output_field'];
					if ( ($o) and ($value['output_type'] == 'normal') ) {
						$flds[$o] = $o; 	// allow any output fields
					}					
				}
				sort($flds);
			}
			
		    $field['choices'] = [];
			foreach ($flds as $f) {
				$field['choices'][ $f ] = $f;
		    }
		    // return the field
		    return $field;  
		}

		function acf_load_type_choices( $field ) {
		    // reset choices
		    $field['choices'] = array('option' => 'option');			// allow for 'option'
		    $types = get_post_types();
		    foreach ($types as $type) {
		    	if (!in_array($type, array('revision', 'nav_menu_item', 'acf-field-group', 'acf-field', 'blob'))) {
		   		 	$field['choices'][ $type ] = $type;
		    	}
		    }
		    // return the field
		    return $field;  
		}

		function acf_load_taxonomy_choices( $field ) {
		    // reset choices
		    $field['choices'] = [];
		    $types = get_taxonomies();
		    foreach ($types as $type) {
		    	if (!in_array($type, array('post_tag', 'nav_menu', 'link_category', 'post_format'))) {
		   		 	$field['choices'][ $type ] = $type;
		    	}
		    }
		    // return the field
		    return $field;  
		}

		function acf_load_transformation_choices( $field ) {
			global $DCAPI_transform;		// transformation function map

		    // reset choices
		    $field['choices'] = [];
		    $temp = $DCAPI_transform;
		    ksort($temp);

			foreach ($temp as $k => $v) {
	            // append to choices
	            $field['choices'][ $k ] = $k;		            
	        }
		    // return the field
		    return $field;  
		}

		function acf_load_feed_filter_choices( $field ) {
			global $DCAPI_feed_filter;		// feed filter map

		    // reset choices
		    $field['choices'] = [];

			if (!empty($DCAPI_feed_filter)) {
			    $temp = $DCAPI_feed_filter;
			    ksort($temp);

				foreach ($temp as $k => $v) {
		            // append to choices
		            $field['choices'][ $k ] = $k;		            
		        }
			}

		    // return the field
		    return $field;  
		}

//
// blob action/filter registration
		function register_transformation($name, $function) {
			global $DCAPI_transform;		// transformation function map
			if ( (!is_object($function[0])) or (get_class($function[0]) != 'DCAPI') ) {
				$DCAPI_transform["$name*"] = array( 'name' => "$name*", 'function' => $function); 
			} else {
				$DCAPI_transform[$name] = array( 'name' => $name, 'function' => $function);			
			}
		}

		function register_feed_filter($name, $function) {
			global $DCAPI_feed_filter;		// feed filter map
			$DCAPI_feed_filter[$name] = array( 'name' => $name, 'function' => $function);
		}

		function generate_route($postID) {
			global $DCAPI_index;
			if ($postID) {
				return $DCAPI_index['postInfo'][$postID]['route'];
			} else {
				return $DCAPI_index['postInfo'][0]['route'];
			}
		}

// built-in blob functions
		function copy_item($blob, $post, $transformed_value, &$out) {
			if ( (!empty($transformed_value)) or ($transformed_value === false) ) {
				return $transformed_value;			// NB: does not overwrite the output unless there is an input value
			}
		}

		function create_route($blob, $post, $transformed_value, &$out) {
			$postID = (int) $transformed_value;
			return $this->generate_route($postID);
		}

		function clean_text($blob, $post, $transformed_value, &$out) {
			if (is_array($transformed_value)) {
				$x = '';
				foreach ($transformed_value as $v) {
					$x .= $v;
				}
				$transformed_value = $x;
			}
			if ($transformed_value) {
				$x = htmlspecialchars_decode($transformed_value);											// just in case!
				$x = preg_replace("/<p>(.*)<\/p>/", "$1", $x);												// remove single <p></p> wrapping if any
				$x = preg_replace("/(\r)?\n/", "<br />", preg_replace("/^(.*)(\r)?\n$/", "$1", $x));		// remove trailing newline (if any) and convert embedded newlines into <br />
				$x = preg_replace('/<(td|tr|span) ([^\>]*)?>/', "<$1>", $x);								// remove formatting from <td>, <tr> and <span> (Microsoft junk)
				$x = preg_replace("/(\[dcapi.*\].*\[\/dcapi\])|(\[dcapi.*\])/", "", $x);					// remove DCAPI shortcodes
				return $x;
			}
		}

		function get_term_list($blob, $post, $transformed_value, &$out) {		// return a list of term ID
			$response = [];
			if ( (!empty($transformed_value)) or ($transformed_value === false) ) {
				$terms = get_the_terms( $post->ID, $transformed_value );			// if there is an input value, it is assumed to be the taxonomy
			} else {
				$terms = get_the_terms( $post->ID, 'category' );
			}
			foreach ($terms as $term) {
				$response[] = $term->term_id;
			}
			return $response;
		}

		function get_term_names($blob, $post, $transformed_value, &$out) {
			$category = $transformed_value;								// string copied in by field mapping where name is the top level category to look for
			$sep = '';

			if ($category) {
				$terms = get_the_terms( $post->ID, $category );			 	// if there is an input value, it is assumed to be the taxonomy
			} else {
				$terms = get_the_terms( $post->ID, 'category' );
			}
			if (!$terms) return;

			foreach ($terms as $term) {
				if ($category) {
					if ($term->term_id) {
						$par = get_category_parents( $term->term_id, false, '|');
						if ($par) {
							$pars = explode('|', $par);
							if ($pars[0] == $category) {
								$response .= $sep . $term->name;			// list all matching term names from the category group specified
								$sep = ' | ';
							}					
						}
					}
				} else {
					$response .= $sep . $term->name;			// list all term names
					$sep = ' | ';
				}
			}
			return ($response == $sep) ? null : $response;
		}

		function convert_terms_to_names($blob, $post, $transformed_value, &$out) {		// convert a list of term IDs to names
			$i = $transformed_value;														// array of term ids
			if ( ($i) and (is_array($i)) ) {
				$result = [];
				$taxonomies = get_taxonomies();
				$taxList = [];
				foreach ($taxonomies as $tax) {
					$taxList[] = $tax;
				}
				$termList = get_terms(array( 'taxonomy' => $taxList, 'hide_empty' => false, ) );
				$taxIndex = [];
				foreach ($termList as $value) {
					$taxIndex[$value->term_id] = $value->name;			// build map of term names
				}
				foreach ($i as $value) {
					$result[] = $taxIndex[$value];
				}
				return $result;
			}
		}

		function handle_image($blob, $post, $transformed_value, &$out) {		
			if (is_array($transformed_value)) {
				return $this->do_image($transformed_value);

			} elseif (is_numeric($transformed_value)) {										// otherwise, needs to receive the post ID as input
				$media_id = get_post_thumbnail_id($transformed_value);
				if ($media_id == '') return null;					// there is no featured image

				$url = wp_get_attachment_url($media_id);
				$thumb = wp_get_attachment_thumb_url($media_id);
				$mimeType = get_post_mime_type($media_id);
				return array(
					'ID' => $media_id,
					'type' => 'attachment',
					'mimeType' => $mimeType,
					'url' => $this->append_timestamp($url),			// append modification timestamp to force a refresh
					'thumbnailUrl' => $thumb,
					);
			}
		}

		function process_gallery($blob, $post, $transformed_value, &$out) {		
			if (is_array($transformed_value)) {
				$o = [];
				foreach ($transformed_value as $image) {
					$o[] = $this->handle_image($image);
				}
				return $o;
			}
		}

		function process_media_image($blob, $post, $transformed_value, &$out) {
			if (!$transformed_value) return;
			if (is_array($transformed_value)) {				// assume it is an image object
				if (empty($transformed_value['sizes'])) {
					return [
						'ID' => $transformed_value['ID'],
						'type' => 'attachment',
						'mimeType' => $transformed_value['mime_type'],
						'url' => $this->append_timestamp($transformed_value['url']),	// append modification timestamp to force a refresh
						];
				} else {
					return [
						'ID' => $transformed_value['ID'],
						'type' => 'attachment',
						'mimeType' => $transformed_value['mime_type'],
						'url' => $this->append_timestamp($transformed_value['url']),	// append modification timestamp to force a refresh
						'thumbnailUrl' => $transformed_value['sizes']['thumbnail'],
						];
				}
			} else if (is_numeric($transformed_value)) {
				$media_id = $transformed_value;
				$meta = wp_get_attachment_metadata($media_id);
				$url = wp_get_attachment_url($media_id);
				$mimeType = get_post_mime_type($media_id);

				if (empty($meta['sizes'])) {
					return [
						'ID' => $media_id,
						'type' => 'attachment',
						'mimeType' => $mimeType,
						'url' => $this->append_timestamp($url),			// append modification timestamp to force a refresh
						];
				} else {
					return [
						'ID' => $media_id,
						'type' => 'attachment',
						'mimeType' => $mimeType,
						'url' => $this->append_timestamp($url),			// append modification timestamp to force a refresh
						'thumbnailUrl' => $url . '-' . $meta['sizes']['thumbnail']['width'] . 'x' . $meta['sizes']['thumbnail']['height'] . '.png',
						];
				}
			}
		}

		function do_image($image) {							// deal with returned image array
			return [
				'ID' => $image['ID'],
				'type' => ($image['type'] == 'image') ? 'attachment' : $image['type'],
				'mimeType' => $image['mime_type'],	
				'url' => $this->append_timestamp($image['url']),			// append modification timestamp to force a refresh
				'thumbnailUrl' => $image['sizes']['thumbnail'],
				];
		}

		function append_timestamp($url) {
			$d = explode('plugins/', realpath(__DIR__));
			$u = explode('wp-content/', $url);
			$fn = $d[0] . $u[1];
			$modifiedTime = filemtime($fn);			// get modification time for file (suppressed warning for files over 2 GB!)
			return $url . "?" . $modifiedTime;							// append modification time timestamp to force a refresh
		}

		function standard_feed_filter($out, $order, $orderby, $item) {		// return sort key for the items, or null if item should be dropped
			$ID = $item['ID'];
			if ( ($orderby == 'date') or ($orderby == 'param/date') ) {
				date_default_timezone_set (get_option('timezone_string'));
				$today = date("Y-m-d");					// format of post_date
				$item_date = ($orderby == 'date') ? $item['post_date'] : $item['param']['date'] ;
				if (($order != 'DESC') and ($item_date >= $today)) {			// also cover "(as retrieved)"
					return $item_date . '_' . $ID;
				} elseif (($order == 'DESC') and ($item_date <= "$today 23:59")) {
					return $item_date . '_' . $ID;
				}
			} elseif ($orderby == 'title') {
				return $item['title'] . '_' . $ID;
			} elseif ( $orderby == 'param/order') {
				return $item['param']['order'] . '_' . $ID;
			} elseif ($orderby == 'menu_order') {
				$menu_order = $item['menu_order'];
				$t = 1000000000 + $menu_order;
				return substr($t, 1) . '_' . $ID;
			} elseif ($orderby == 'relevance') {
				$relevance = $item['relevance'];
				$t = 1000000000 + $relevance;
				return substr($t, 1) . '_' . $ID;
			}
			return null;
		}
	}
}
global $DCAPI;
$DCAPI = new DCAPI();
