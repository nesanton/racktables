<?php
/*
*
*  This file is a library of web proxy functions for RackTables.
*
*/

function proxyRequest ($type)
{
	$ret = array();

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

			$session = curl_init();

			// Initial options up here so a specific type can override them
			curl_setopt ($session, CURLOPT_FOLLOWLOCATION, FALSE); 
			curl_setopt ($session, CURLOPT_TIMEOUT, 10);
			curl_setopt ($session, CURLOPT_RETURNTRANSFER, TRUE);

			curl_setopt ($session, CURLOPT_HEADER, TRUE);
			curl_setopt ($session, CURLOPT_URL, $url);
			$headers = curl_exec ($session);	// Initial request to set the cookies

			// Get the cookies from the headers
			preg_match('/Set-Cookie: ([^;]*)/i', $headers, $cookies);
			array_shift($cookies);  // Remove 'Set-Cookie: ...' value			
			$cookie_header = implode(";", $cookies);

			curl_setopt ($session, CURLOPT_COOKIE, $cookie_header);
			curl_setopt ($session, CURLOPT_HEADER, FALSE);
			curl_setopt ($session, CURLOPT_POST, TRUE);
			curl_setopt ($session, CURLOPT_POSTFIELDS, $postvars);

			curl_exec ($session);	// POST Login

			// Make the request
			$ret['contents'] = curl_exec ($session);
			$ret['type'] = "text";
			$ret['type'] = curl_getinfo ($session, CURLINFO_CONTENT_TYPE);
			$ret['size'] = curl_getinfo ($session, CURLINFO_CONTENT_LENGTH_DOWNLOAD);

			curl_close ($session);
			break;

		default:
			return NULL;
	}

	return $ret;
}

?>
