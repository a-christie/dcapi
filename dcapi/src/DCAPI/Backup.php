<?php
/*
 *		Routines for backup and restore of central DCAPI 
 */

namespace DCAPI;

class Backup implements \ArrayAccess {
	private $status = [];	
	private $container = [];

	public function __construct($ref = '') {				// $ref is the backup reference (filename in directory backup/..) based on current time and date
															//		set $ref to an existing reference and that file is loaded
		if (!$ref) {
			// load Wordpress and carry on
			$parse_uri = explode( 'wp-content', $_SERVER['SCRIPT_FILENAME'] );							// normally this works
			if (!$parse_uri[1]) $parse_uri = explode( 'wp-admin', $_SERVER['SCRIPT_FILENAME'] );		// this is needed when installing
			require_once( $parse_uri[0] . 'wp-load.php' );
			require_once( ABSPATH . 'wp-admin/includes/file.php' );	

			global $DCAPI_blob_config;
			$DCAPIVersion = $DCAPI_blob_config['DCAPIVersion'];
			$siteName = $DCAPI_blob_config['siteName'];

			$backup = [];

			// first get the options fields from "DCAPI configuration" options group
			$args = array(
				'post_type'   => 'acf-field-group',
				'post_status' => 'publish',
				'numberposts' => -1
			);

			$acf_posts = get_posts($args);
			foreach ($acf_posts as $post) {
				if ( ($post->post_title == 'DCAPI configuration') or ($post->post_title == 'Blob fields') ) {  
					$group_id = $post->ID;
					$field_group = acf_get_field_group($group_id);
					$fg_key = $field_group['key'];
					$fields = acf_get_fields($fg_key);

					foreach ($fields as $f) {
						$key = $f['key'];
						$name = $f['name'];
						$type = $f['type'];
						if ( ($name) and ($type != 'tab') ) {
							if ($type == 'repeater') {
								$backup['option']["$name|$key"] = [];

								$subf = $f['sub_fields'];
								while (\have_rows($key, 'option')) {									
									$row = \the_row();
									$r = [];
									foreach ($subf as $sk =>$sv) {
										$r[$sv['name']] = $row[$sv['key']];
									}
									$backup['option']["$name|$key"][] = $r;
								}
							} else {							
								$value = \get_field($key, 'option');
								$backup['option']["$name|$key"] = $value;							// use ID 0 for the Blob config data
							}
						}
					}
				}
			}

			// then get the various blobs
			$args = array(
				'post_type'   => 'blob',
				'sort_order'  => 'ASC',
				'sort_column' => 'menu_order',
				'post_status' => 'publish',
				'numberposts' => -1
			);
			$blob_posts = get_posts($args);
			foreach ($blob_posts as $blob) {
				$backup[$blob->ID] = [];	
				$backup[$blob->ID]['(Wordpress)'] = array(
					'ID' => $blob->ID,
					'post_title' => $blob->post_title,
					'post_content' => $blob->post_content,
					'menu_order' => $blob->menu_order,
					'post_type' => $blob->post_type,
					);
				$fields = \get_fields($blob->ID);

				foreach ($fields as $name => $value) {
					$obj = \get_field_object($name, $blob->ID, array());
					$key = $obj['key'];

					$backup[$blob->ID]["$name|$key"] = $this->clean_up_value($value);
				}
			}
			date_default_timezone_set(get_option('timezone_string'));
			$file_timestamp = date('Y-m-d_H-i-s');

			if (function_exists('acf')) {
				$a = acf();
				$n = $a->settings['name'];
				$v = $a->settings['version'];
				$ACFVersion = "$n, $v";
			}

			$meta = array(
				'self'=> plugins_url("/dcapi/^backup/$file_timestamp"),
				'cacheDateTime' => date('Y-m-d H:i:s', time()),
				'copyright'=> '\u00A9 ' . date('Y', time()) . ', ' . get_option('blogname') . '. Authorized use only',
				'GUID' => get_option('DCAPI_GUID'),
				'DCAPIVersion' => $DCAPIVersion,
				'ACF_version' => $ACFVersion,
				'siteName' => $siteName,
				);

			$data = array(
				'meta' => $meta,
				'backup' => $backup,
				);
			$dc = new \DCAPI\Cache('backup');
			$dc->write_cache_file('', $file_timestamp, $data);
			$this->container = $data;

			$this->status = array( 'backup' => "data backed up to '../dcapi/^backup/$file_timestamp'" );

		} else {
			$dc = new \DCAPI\Cache('backup');
			$this->container = $dc->read_cache_file('', $ref);
			if (!$this->container) {
				$this->status = array( 'error' => "could not find backup file '../dcapi/^backup/$ref'" );		
			}
		}
	}

	private function clean_up_value($value) {
		if (is_array($value)) {
			$new_value = [];
			foreach ($value as $k => $v) {
				$new_value[$k] = $this->clean_up_value($v);
			}
			return $new_value;
		} else if ( (is_integer($value)) or ($value === intval($value)) ) {					// return integer values if matched
			return intval($value);
		} else if (is_string($value)) {
			if ( (substr($value, 0, 3) == '<p>') and (substr($value, -5) == "</p>\n") ) {	// clean up multi-line strings
				$value = str_replace('<p>', '', $value);
				$value = str_replace("</p>\n", '', $value);
				$value = str_replace("<br />\n", "\n", $value);
			}
			return $value;
		} else {
			return $value;
		}
	}

	public function restore($ref) {				// construct the object with empty string to start with - that makes a new backup as a side effect
		$dc = new \DCAPI\Cache('backup');
		$data = $dc->read_cache_file('', $ref);		// this is the data to restore
		if (!$data) {
			$this->status = array( 'error' => "restore not possible – '../dcapi/^backup/$ref' not found" );		
			return;
		}

		$p = strpos($this->container['meta']['DCAPIVersion'], ',');
		$s = substr($this->container['meta']['DCAPIVersion'], 0, $p);
		$q = strpos($data['meta']['DCAPIVersion'], ',');
		$t = substr($data['meta']['DCAPIVersion'], 0, $q);

		if ($s != $t) {
			$this->status = array( 'error' => "incompatible versions, current version '$s', stored backup version '$t'" );		
			return;
		}

		// first move all current blobs to the trash
		$args = array(
			'post_type'   => 'blob',
			'post_status' => 'publish',
			'numberposts' => -1
		);
		$blob_posts = get_posts($args);
		foreach ($blob_posts as $blob) {
			wp_trash_post($blob->ID);
		}

		$post_id_map = [];

		// now create the new blobs, based on the data in the restore file
		foreach ($data['backup'] as $b => $blob) {
			if ($b != 'option') {
				$my_post = array(
				  'post_title'    => $blob['(Wordpress)']['post_title'],
				  'post_type'   => $blob['(Wordpress)']['post_type'],
				  'post_content'   => $blob['(Wordpress)']['post_content'],
				  'menu_order'   => $blob['(Wordpress)']['menu_order'],
				  'post_status'   => 'publish',
				);		
				$post_id = wp_insert_post( $my_post );				// Insert the post into the database and retrieve the post ID
				$post_id_map[intval($b)] = intval($post_id);						// keep mappings for source_blob..
			}
		}

		foreach ($data['backup'] as $b => $blob) {
			if ($b != '0') {
				$post_id = $post_id_map[$b];	// new post ID
				foreach ($blob as $name_key => $value) {
					if ($name_key != '(Wordpress)') {						// deal with the ACF fields
						$pos = strpos($name_key, '|');
						$name = substr($name_key, 0 , $pos);
						$key = substr($name_key, $pos+1);
						if (is_array($value)) {
							$field = \get_field($key, $post_id);
							foreach ($value as $line => $row) {
								$field[$line] = $row;
							}
							update_field($key, $field, $post_id);
						} else {
							update_field($key, $value, $post_id);							
						}
					}
				}			
			}
		}

		// set the options from the backup
		foreach ($data['backup']['option'] as $name_key => $value) {
			$pos = strpos($name_key, '|');
			$name = substr($name_key, 0 , $pos);
			$key = substr($name_key, $pos+1);
			if (is_array($value)) {
				$field = \get_field($key, 'option');
				$field = [];							// remove old values
				foreach ($value as $row) {
					$r = [];
					foreach ($row as $rk => $rv) {
						$r[$rk] = $rv;
					}	
					$field[] = $r;	
				}
				update_field($key, $field, 'option');
			} else {
				update_field($key, $value, 'option');
			}
		}

		// regenerate ^blob and ^index
		global $DCAPI_blob_config;
		$DCAPI_blob_config = new \DCAPI\Config(['regen']);
		global $DCAPI_index;
		$DCAPI_index = new \DCAPI\Index(['regen']);

		$this->status = array_merge($this->status, array( 'restore' => "data restored from '../dcapi/^backup/$ref'" ));
		return $this->status;
	}

	public function get_status() {
		return $this->status;
	}

	public function get_data() {
		return $this->container;
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