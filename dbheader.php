<?php
/*                        ''~``
                         ( o o )
 +------------------.oooO--(_)--Oooo.--------------------+
 |                        CoreBB                         |
 |        Developed by -Prismatic- / HannsGruber         |
 |                Copyright (c) 2005 - 2026              |
 |                  All Rights Reserved.                 |
 |                    .oooO                              |
 |                    (   )   Oooo.                      |
 +---------------------\ (----(   )----------------------+
                        \_)    ) /
                              (_/

 +-------------------------------------------------------+
 |  dbheader.php  - Database header.                     |
 +-------------------------------------------------------+*/

//Security Measure.
if ( !defined('IN_BOARDS') )
{	
	$IP = $_SERVER['REMOTE_ADDR']; 
 	if (strstr($IP, ', ')) {
    	$ips = explode(', ', $IP);
    	$IP = $ips[0];
 	}
	$self = $_SERVER['PHP_SELF'];
	$arr = explode("/", $self);
	$num = count($arr);
	$currentfile = $arr[$num - 1];
	
	$now = date('l dS \of F Y h:i:s A');
	$filename = 'invalidaccess.txt';
	$fp = fopen($filename, "a");
	$string = "$now - Hacking attempt from: $IP - File Attempted Access: $currentfile\n";
	$write = fputs($fp, $string);
	fclose($fp);
	
	echo "<b>Hacking attempt from:<b> $IP<br><b>Action Logged</b";
	die();
	exit;
}


/* INCLUDE THE CONFIGURATION FILE */
include_once __DIR__ . '/config.php';
require_once(__DIR__ . '/lib/helpers/db.php');

/* BOARD IS SET TO LOCK DOWN MODE */
if($BoardLockdown != 0){
	header("Location: boardsdown.html");
}


/* CONNECT TO THE DATABASE */
$link = db_connect($MySQL_Host, $MySQL_User, $MySQL_Pass, $MySQL_Database);

/* UNABLE TO CONNECT TO HOST */
if (!$link) {
	header("Location: /err/msg/Unable%20to%20connect%20to%20the%20specified%20mysql%20host/");
}

// The configured database is selected during connection.
if (!$link) {
	header("Location: /err/msg/Cant%20select%20the%20database/");
}
?>
