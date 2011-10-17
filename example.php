<?php

require_once( 'sasua_canteens.php' );

//
// web applications
//
$_GET['z'] = 'santiago';
$_GET['t'] = 'day';
$_GET['f'] = 'json';
$sas = new SASUA_Canteens_Web();


echo "\n\n\n";


//
// console applications
//
$sas = new SASUA_Canteens();
//echo $sas->get( 'santiago', 'day', 'xml' );
echo $sas->get( 'santiago', 'week', 'xml' );

?>
