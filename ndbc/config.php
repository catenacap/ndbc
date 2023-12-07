<?php

 
require_once('/home/xxx/public_html/m_db_conf.inc');

define('DB_SERVER','xxx'); 
define('DB_USERNAME','xxx'); 
define('DB_PASSWORD', M_DB_PASSWORD); 
define('DB_NAME','xxx');

$db = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

if($db->connect_errno) {
	echo "Failed to connect to MySQL: (" . $db->connect_errno . ") " . $db->connect_error;
}

?>