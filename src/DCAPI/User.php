<?php
/*
 *		Routines for handling users
 */

namespace DCAPI;

class User {

	public function current_user($user = null) {
		global $DCAPI_blob_config;							// one global cached with the blob config data in it
		$revealTo = $DCAPI_blob_config['revealTo'];

		if (!$user) $user = wp_get_current_user();	

		if (is_user_logged_in()) {
			global $wp_roles;
			$user_roles = $user->roles;
			$roles = [];
			$reveal = false;
			foreach ($user_roles as $user_role) {
				$r = $wp_roles->role_names[$user_role];
				if (in_array($r, $revealTo)) $reveal = true;
				$roles[] = $r;
			}
			return array(
				'login' => $user->data->user_login,
				'roles' => $roles,
				'reveal' => $reveal,
				);
		} else {
			return array(
				'login' => null,
				);
		}
	}

	public function login($user_login = null, $user_password = null) {
		global $wp_roles;
		wp_logout();
		$user = null;

		if ( ($user_login) and ($user_password) ) {
			$creds = [];
			$creds['user_login'] = $user_login;
			$creds['user_password'] = $user_password;
			$creds['remember'] = true;
			$user = wp_signon( $creds, false );
		}
	}
}

?>