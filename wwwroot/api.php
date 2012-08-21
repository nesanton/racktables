<?php

// TODO: look into using global $sic, which has merged GET and POST parameters, instead of $_REQUEST['blah'].
//       Apparently it may handle UTF-8 arguments better. Created in transformRequestData().

// TODO: split json into response and metadata components. Metadata might include: error messages, success 
//       messages like items added, deleted, or modified, etc.

ob_start();
require_once 'inc/pre-init.php';
try {
	switch ( $_REQUEST['method'] )
	{

        // get overall 8021q status
        //    UI equivalent: /index.php?page=8021q
        //    UI handler: renderVLANIPLinks()
        case 'get_8021q':
		require_once 'inc/init.php';

                // get data for all VLAN domains: ID, name, VLAN count, switch count, ipv4net count, port count
                $vdlist = getVLANDomainStats();

                // TODO: also get deploy queues and switch templates?

                sendAPIResponse($vdlist);
                break;


        // get overall IPv4 space
        //    UI equivalent: /index.php?page=ipv4space
        //    UI handler: renderIPSpace() (realm == 'ipv4net')
        case 'get_ipv4space':
                require_once 'inc/init.php';

                $ipv4space = listCells ('ipv4net');

                // TODO: probably add hierarchy?
                // TODO: also show capacity for each network
                // TODO: also show router for each network

                sendAPIResponse($ipv4space);
                break;
        

        // get overall rackspace status
        //    UI equivalent: /index.php?page=rackspace
        //    UI handler: renderRackspace()
        case 'get_rackspace':
		require_once 'inc/init.php';

                // taken straight from interface.php::renderRackspace()
                $found_racks = array();
                $rows = array();
                $rackCount = 0;
                foreach (getAllRows() as $row_id => $rowInfo)
                {
                        $rackList = listCells ('rack', $row_id);
                        $found_racks = array_merge ($found_racks, $rackList);
                        $rows[] = array (
                                         'location_id'   => $rowInfo['location_id'],
                                         'location_name' => $rowInfo['location_name'],
                                         'row_id'        => $row_id,
                                         'row_name'      => $rowInfo['name'],
                                         'racks'         => $rackList
                                         );
                        $rackCount += count($rackList);
                }

                sendAPIResponse($rows);
                break;


        // get info for a rack
        //    UI equivalent: /index.php?page=rack&rack_id=967
        //    UI handler: renderRackPage()
        case 'get_rack':
                require_once 'inc/init.php';

                assertUIntArg ("rack_id", TRUE);

                $rackData = spotEntity ('rack', $_REQUEST['rack_id']);
                amplifyCell ($rackData);

                sendAPIResponse( $rackData );
                break;


        // get info for a single IP address
        //    UI equivalent: /index.php?page=ipaddress&hl_object_id=911&ip=10.200.1.66
        //    UI handler: renderIPAddress()
        case 'get_ipaddress':
                require_once 'inc/init.php';

                assertStringArg ("ip", TRUE);

                // basic IP address info
                $address = getIPAddress (ip_parse ( $_REQUEST['ip'] ));

                // TODO: add some / all of the following data
                // virtual services 
                //  ! empty $address['vslist']
                //      foreach $address['vslist'] as $vs_id
                //         $blah = spotEntity ('ipv4vs', $vs_id)
                // RS pools
                // allocations
                // departing NAT rules
                // arriving NAT rules

                sendAPIResponse($address);
                break;


        // get one object
        //    UI equivalent: /index.php?page=object&object_id=909
        //    UI handler: renderObject()
	case 'get_object':
		require_once 'inc/init.php';

                assertUIntArg ("object_id", TRUE);

                $info = spotEntity ('object', $_REQUEST['object_id']);
                amplifyCell ($info);

                // optionally get attributes
                if (isset ($_REQUEST['include_attrs']))
                {

                        // return the attributes in an array keyed on 'name', unless otherwise requested
                        $key_attrs_on = 'name';
                        if (isset ($_REQUEST['key_attrs_on']))
                                $key_attrs_on = $_REQUEST['key_attrs_on'];

                        $attrs = array();
                        foreach (getAttrValues ( $_REQUEST['object_id'] ) as $record)
                        {
                          // check that the key exists for this record. we'll assume the default 'name' is always ok
                          if (! isset ($record[ $key_attrs_on ]))
                          {
                                  throw new InvalidRequestArgException ('key_attrs_on',
                                                                        $_REQUEST['key_attrs_on'],
                                                                        'requested keying value not set for all attributes' );
                          }

                          // include only attributes with value set, unless requested via include_unset_attrs param
                          // TODO: include_unset_attrs=0 currently shows the attributes...not intuitive
                          if ( strlen ( $record['value'] ) or isset( $_REQUEST['include_unset_attrs'] ) )
                                  $attrs[ $record[ $key_attrs_on ] ] = $record;
                        }

                        $info['attrs'] = $attrs;
                }

                // TODO: remove ip_bin data from response, or somehow encode in UTF-8 -safe format
                //       note that get_ipaddress doesn't error, even though ip_bin key is present

                sendAPIResponse($info);
		break;


        // get the location of an object
        //    UI equivalent: /index.php?page=object&tab=rackspace&object_id=1013
        //    UI handler: renderRackSpaceForObject()
	case 'get_object_allocation':
		require_once 'inc/init.php';

                assertUIntArg ("object_id", TRUE);

                // get physical allocations
                $racksData = getResidentRacksData ($_REQUEST['object_id']);

                // get zero-U allocations
                $zeroURacks = array();

                $objectParents = getEntityRelatives('parents', 'object', $_REQUEST['object_id']);
                foreach ($objectParents as $parentData)
                  if ($parentData['entity_type'] == 'rack')
                    array_push($zeroURacks, $parentData['entity_id']);

                // TODO: possibly just pull out the atoms the server is in?
                sendAPIResponse( array( 'racks' => $racksData, 'zerou_racks' => $zeroURacks ) );

                break;


        // add one object
        //    UI equivalent: submitting form at /index.php?page=depot&tab=addmore
        //    UI handler: addMultipleObjects()
        case 'add_object':
		require_once 'inc/init.php';

                // only the Type ID is required at creation -- everything else can be set later
                assertUIntArg ("object_type_id", TRUE);
                $object_type_id = $_REQUEST['object_type_id'];


                // virtual objects don't have labels or asset tags
                if (isset ($_REQUEST['virtual_objects']))
                {
                        $_REQUEST["object_label"] = '';
                        $_REQUEST["object_asset_no"] = '';
                }

                $object_name     = isset ( $_REQUEST['object_name'] )     ? $_REQUEST['object_name']     : '';
                $object_label    = isset ( $_REQUEST['object_label'] )    ? $_REQUEST['object_label']    : '';
                $object_asset_no = isset ( $_REQUEST['object_asset_no'] ) ? $_REQUEST['object_asset_no'] : '';
                $taglist         = isset ( $_REQUEST['taglist'] )         ? $_REQUEST['taglist']         : array();

                $object_id = commitAddObject
                (
                        $object_name,
                        $object_label,
                        $object_type_id,
                        $object_asset_no,
                        $taglist
                );

                // redirect to the get_object URL for the new object
                redirectUser($_SERVER['SCRIPT_NAME'] . "?method=get_object&object_id=$object_id");
		break;


        // edit an existing object
        //    UI equivalent: submitting form at /index.php?page=object&tab=edit&object_id=911
        //    UI handler: updateObject()
        case 'edit_object':
		require_once 'inc/init.php';

                // check required args
                genericAssertion ('object_id', 'uint0');
                genericAssertion ('object_name', 'string0');
                genericAssertion ('object_label', 'string0');
                genericAssertion ('object_asset_no', 'string0');
                genericAssertion ('object_comment', 'string0');
                genericAssertion ('object_type_id', 'uint'); // TODO: really required for API?

                $object_id = $_REQUEST['object_id'];

                // make this transactional, so we can double check the whole upate before committing at the end
                global $dbxlink, $sic;
                $dbxlink->beginTransaction();

                // TODO: may need to wrap this in a try/catch block to redirect to API exception response
                commitUpdateObject
                (
                        $object_id,
                        $_REQUEST['object_name'],
                        $_REQUEST['object_label'],
                        isCheckSet ('object_has_problems', 'yesno'), // not really a checkbox, but easier than writing it myself
                        $_REQUEST['object_asset_no'],
                        $_REQUEST['object_comment']
                );

                // update optional attributes

                // get the valid / old values for the object
                $oldvalues = getAttrValues ($object_id);

                // look for values to be updated.
                //   note: in UI, a "num_attrs" input is used to loop and search for update fields
                foreach ( $_REQUEST as $name => $value )
                {
                        error_log( "considering parameter: $name => $value" );

                        if ( preg_match( '/^attr_(\d+)$/', $name, $matches ) )
                        {
                                $attr_id = $matches[1];
                                error_log( "parameter $name is an object attribute, ID: $attr_id" );

                                // make sure the attribute actually exists in the object
                                if (! array_key_exists ($attr_id, $oldvalues))
                                        throw new InvalidRequestArgException ('attr_id', $attr_id, 'malformed request');

                                // convert date arguments
                                if ('date' == $oldvalues[$attr_id]['type']) {
                                        error_log( "check date field: $attr_id => $value" );
                                        assertDateArg ( $name, TRUE);
                                        if ($value != '')
                                                $value = strtotime ($value);
                                }

                                // Delete attribute and move on, when the field is empty or if the field
                                // type is a dictionary and it is the "--NOT SET--" value of 0.
                                if ($value == '' || ($oldvalues[$attr_id]['type'] == 'dict' && $value == 0))
                                {
                                        error_log( "delete empty attribute ID $attr_id" );
                                        commitUpdateAttrValue ($object_id, $attr_id);
                                        continue;
                                }

                                assertStringArg ( $name );

                                // normalize dict values
                                switch ($oldvalues[$attr_id]['type'])
                                {
                                        case 'uint':
                                        case 'float':
                                        case 'string':
                                        case 'date':
                                                $oldvalue = $oldvalues[$attr_id]['value'];
                                                break;
                                        case 'dict':
                                                $oldvalue = $oldvalues[$attr_id]['key'];
                                                break;
                                        default:
                                }

                                // skip noops
                                if ($value === $oldvalue)
                                        continue;

                                // finally update our value
                                error_log( "update attribute ID $attr_id from $oldvalue to $value" );
                                commitUpdateAttrValue ($object_id, $attr_id, $value);
                        }
                }

                // see if we also need to update the object type
                $object = spotEntity ('object', $object_id);

                if ($sic['object_type_id'] != $object['objtype_id'])
                {
                        error_log( "object type id for object $object_id will be changed from " . $object['objtype_id'] . ' to ' . $sic['object_type_id'] );

                        // check that the two types are compatible
                        if (! array_key_exists ($sic['object_type_id'], getObjectTypeChangeOptions ($object_id)))
                                throw new InvalidRequestArgException ('new type_id', $sic['object_type_id'], 'incompatible with requested attribute values');

                        usePreparedUpdateBlade ('RackObject', array ('objtype_id' => $sic['object_type_id']), array ('id' => $object_id));
                }

                // Invalidate thumb cache of all racks objects could occupy.
                foreach (getResidentRacksData ($object_id, FALSE) as $rack_id)
                        usePreparedDeleteBlade ('RackThumbnail', array ('rack_id' => $rack_id));

                // ok, now we're good
                $dbxlink->commit();

                // redirect to the get_object URL for the edited object
                redirectUser( $_SERVER['SCRIPT_NAME'] . "?method=get_object&object_id=$object_id" );
                break;


        // delete an object
        //    UI equivalent: /index.php?module=redirect&op=deleteObject&page=depot&tab=addmore&object_id=993
        //                   (typically a link from edit object page)
        //    UI handler: deleteObject()
        case 'delete_object':
		require_once 'inc/init.php';

                assertUIntArg ('object_id');

                // determine racks the object is in
                $racklist = getResidentRacksData ($_REQUEST['object_id'], FALSE);
                commitDeleteObject ($_REQUEST['object_id']);

                foreach ($racklist as $rack_id)
                        usePreparedDeleteBlade ('RackThumbnail', array ('rack_id' => $rack_id));

                // redirect to the depot method
                redirectUser( $_SERVER['SCRIPT_NAME'] . "?method=get_depot" );
                break;


        // get all objects
        //    UI equivalent: /index.php?page=depot&tab=default
        //    UI handler: renderDepot()
        case 'get_depot':
		require_once 'inc/init.php';

                $objects = listCells ('object');

                // TODO: get full mount info, like rowID, using getMountInfo()

                sendAPIResponse($objects);
                break;


        // get dictionary chapter
        //    UI equivalent: /index.php?page=chapter&chapter_no=1
        //    UI handler: renderChapter()
        case 'get_chapter':
                require_once 'inc/init.php';

                assertUIntArg ('chapter_no', TRUE);

                $words = readChapter ( $_REQUEST['chapter_no'], 'a');

                // TODO: add refcount and attributes data to enable filtered lookups? getChapterRefc() and getChapterAttributes()
                
                sendAPIResponse($words);
                break;



        // <<DESCRIPTION>>
        //    UI equivalent: /index.php?page=
        //    UI handler: ()
        //case '':
	//	require_once 'inc/init.php';
        //        header ('Content-Type: application/json; charset=UTF-8');
        //        echo json_encode();
        //        break;


	default:
		throw new InvalidRequestArgException ('method', $_REQUEST['method']);
	}
	ob_end_flush();
}
catch (Exception $e)
{
        error_log('exception handled by API');

	ob_end_clean();

        // TODO: add custom error display and possibly exceptions for API
	printAPIException ($e);
}


// standard format for the http response
function sendAPIResponse ( $data, $metadata = NULL, $http_code = 200, $errors = NULL )
{
        $http_body = array( 'response' => $data );

        // add metadata if present
        if ( isset( $metadata ) )
        {
                $http_body[ 'metadata' ] = $metadata;
        }

        // add errors if present
        if ( isset( $errors ) )
        {
                $http_body[ 'errors' ] = $errors;
        }

        header ('Content-Type: application/json; charset=UTF-8', FALSE, $http_code);
        echo json_encode( $http_body );
        exit;
}


function printAPIException ($e)
{
        if ($e instanceof RackTablesError)

                switch ( get_class($e) )
                {
                case 'RackTablesError':
                        // TODO check RT error constant to see if i'ts an auth problem
                        sendAPIResponse(NULL,NULL,500,array($e->getMessage()));
                        break;

                case 'RTDatabaseError':
                        sendAPIResponse(NULL,NULL,500,array($e->getMessage()));
                        break;

                case 'RackCodeError':
                        sendAPIResponse(NULL,NULL,500,array($e->getMessage()));
                        break;

                case 'RTPermissionDenied':
                        sendAPIResponse(NULL,NULL,403,array($e->getMessage()));
                        break;

                case 'EntityNotFoundException':
                        sendAPIResponse(NULL,NULL,404,array($e->getMessage()));
                        break;

                case 'RTGatewayError':
                        sendAPIResponse(NULL,NULL,400,array($e->getMessage()));
                        break;

                case 'InvalidArgException':
                        sendAPIResponse(NULL,NULL,400,array($e->getMessage()));
                        break;

                case 'InvalidRequestArgException':
                        sendAPIResponse(NULL,NULL,400,array($e->getMessage()));
                        break;

                default:
                        sendAPIResponse(NULL,NULL,500,array('unhandled RackTablesError -based exception: '.$e->getMessage));
                        break;

                }

        elseif ($e instanceof PDOException)
                //printPDOException($e);
                sendAPIResponse(NULL,NULL,500,array('PDO exception: ' . $e->getMessage()));
        else
                //printGenericException($e);
                sendAPIResponse(NULL,NULL,500,array('unhandled exception: ' . $e->getMessage()));
}

?>
