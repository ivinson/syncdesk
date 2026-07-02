<?php
define('USERSPICE_ACTIVE_LOGGING', false);
$noPHPInfo = false;
require_once 'classes/class.autoloader.php';




ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
if (Server::get('HTTPS') !== '' && Server::get('HTTPS') !== 'off') {
	ini_set('session.cookie_secure', 1);
}
session_start();
// disables the feature that prevents the updater from installing languages you didn't have before the update
// $disable_language_purge = true;


$abs_us_root=Server::get('DOCUMENT_ROOT');

$self_path=explode("/", Server::get('PHP_SELF'));

$self_path_length=count($self_path);
$file_found=FALSE;

for($i = 1; $i < $self_path_length; $i++){
	array_splice($self_path, $self_path_length-$i, $i);
	$us_url_root=implode("/",$self_path)."/";

	if (file_exists($abs_us_root.$us_url_root.'z_us_root.php')){
		$file_found=TRUE;
		break;
	}else{
		$file_found=FALSE;
	}
}

require_once $abs_us_root.$us_url_root.'users/helpers/helpers.php';

// Set config
$GLOBALS['config'] = array(
	'mysql'      => array(
	//options for PDO connection overrides
	// 'collation'    => 'utf8mb4_general_ci',	
	'force_utc_mysql' => false,
	'charset'      => 'utf8mb4',		
'host'         => '193.203.175.155',
'username'     => 'u118198581_syncdesk',
'password'     => 'zgZB^T3!+Dw9',
'db'           => 'u118198581_syncdesk',
'port'         => '3306',
),
'remember'        => array(
  'cookie_name'   => 'zabT3riZygPwq4eJyNQ9',
  'cookie_expiry' => 604800  //One week, feel free to make it longer
),
'session' => array(
  'session_name' => 'cffAbZaL5MshHLYaYb1G',
  'token_name' => 'token',
)
);

//If you changed your UserSpice or UserCake database prefix
//put it here.
$db_table_prefix = "uc_";  //Old database prefix

//adding more ids to this array allows people to access everything, whether offline or not. Use caution.
$master_account = [1];

$currentPage = currentPage();

//Check to see if user has a remember me cookie
if(Cookie::exists(Config::get('remember/cookie_name')) && !Session::exists(Config::get('session/session_name'))){
	$hash = Cookie::get(Config::get('remember/cookie_name'));
	$hashCheck = DB::getInstance()->query("SELECT * FROM users_session WHERE hash = ? AND uagent = ?",array($hash,Session::uagent_no_version()));

	if ($hashCheck->count()) {
		$user = new User($hashCheck->first()->user_id);
		$inst = Config::get('session/session_name');
        $_SESSION[$inst . '_login_method'] = "cookie";
        $user->login();

	}
}

//Check to see that user is logged in on a temporary password
$user = new User();

//Check to see that user is verified
if($user->isLoggedIn()){
	$verifySkipPages = ['verify.php', 'logout.php', 'verify_thankyou.php', 'verify_resend.php'];
	if($user->data()->email_verified == 0 && !in_array($currentPage, $verifySkipPages)){
		Redirect::to($us_url_root.'users/verify.php');
	}
}


$userspice_nonce = base64_encode(random_bytes(16));
// Forces SSL verification in cURL requests to UserSpice API
// Will most likely break on localhost or self-signed certificates
define('EXTRA_CURL_SECURITY', false); 
require_once $abs_us_root.$us_url_root."users/includes/loader.php";
$timezone_string = 'America/Sao_Paulo';
date_default_timezone_set($timezone_string);
