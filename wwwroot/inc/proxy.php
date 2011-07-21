<?php
/*
*
*  This file is a library of web proxy functions for RackTables.
*
*/

function proxyRequest ($type)
{
	$ret = array();

	$session = curl_init();
	$ckfile = tempnam ("/tmp", "CURLCOOKIE");

	// Initial options up here so a specific type can override them
	curl_setopt ($session, CURLOPT_FOLLOWLOCATION, FALSE); 
	curl_setopt ($session, CURLOPT_TIMEOUT, 10);
	curl_setopt ($session, CURLOPT_RETURNTRANSFER, TRUE);

	// Cookie handling
	curl_setopt ($session, CURLOPT_COOKIEFILE, $ckfile);
	curl_setopt ($session, CURLOPT_COOKIEJAR, $ckfile);

	switch ($type)
	{
		case 'cactigraph':
			$cacti_url = getConfigVar('CACTI_URL');
			$cacti_user = getConfigVar('CACTI_USERNAME');
			$cacti_pass = getConfigVar('CACTI_USERPASS');
			assertUIntArg ('graph_id');
			$graph_id = $_REQUEST['graph_id'];
			$url = $cacti_url . "graph_image.php?action=view&local_graph_id=" . $graph_id;
			$postvars = "action=login&login_username=" . $cacti_user . "&login_password=" . $cacti_pass;

			curl_setopt ($session, CURLOPT_URL, $url);
			curl_exec ($session);	// Initial request to set the cookies

			curl_setopt ($session, CURLOPT_POST, TRUE);
			curl_setopt ($session, CURLOPT_POSTFIELDS, $postvars);

			curl_exec ($session);	// POST Login
			break;

		default:
			curl_close ($session);
			unlink ($ckfile);
			return NULL;
	}

	// Make the request
	$ret['contents'] = curl_exec ($session);
	$ret['type'] = curl_getinfo ($session, CURLINFO_CONTENT_TYPE);
	$ret['size'] = curl_getinfo ($session, CURLINFO_CONTENT_LENGTH_DOWNLOAD);

	curl_close ($session);
	unlink ($ckfile);

	return $ret;
}

?>
