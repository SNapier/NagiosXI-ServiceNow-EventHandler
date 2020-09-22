#!/usr/bin/php -q
<?php
// Nagios to Service Now Table API Incident Creation
// Version 2.0.0
// Created by Sam Napier

/*
USES NAGIOS EXTERNAL COMMAND #34 FOR ACKNOWLEDGEMENT
FULL EVENT LOGIC
PROVIDES FOR ESCALATOION AND DEMOTION OF INCIDENTS
PROVIDES FOR AUTO CLOSURE OF EVENTS
DISCARDS UNKNOWN EVENTSTATE
JUDGEMENT CALLS
*/

define('CFG_ONLY', 1);
define('DEBUG_OUT', 0)

// DO THE WORK
handle_state_change();

function handle_state_change(){

	// Define the global argument to be used for the incident
	global $argv;
	$meta = parse_argv($argv);
	
	//We only want to forward events for monitored hosts
	//This is a basic exclusion list for hosts with alerts to be discarded
	$discard_hosts = array('HOSTNAME', 'hostname');
	if(isset($meta['host']) && in_array($meta['host'], $discard_hosts)){
		$level = 'INFO';
		$msg = 'EVENT-HANDLER-DISCARD: HOST "'.$meta['host'].' IS IN DISCARD HOST LIST"';
		logit($meta, $level, $msg);
		exit();
	}else{
		//Hostname is valid so we parse the service name
		// If the service name is not present then we will exit with error
		if(!isset($meta['service'])){
			$level = 'ERROR';
			$msg = 'EVENT-HANDLER-ERROR: THE SERVICE NAME IS MISSING"';
			logit($meta, $level, $msg);
			exit();	
		}else{
			$meta['seviceinfo'] = array();
			list($os, $class, $category, $desc) = explode("--", $meta['service']);
			$meta['serviceinfo']['os'] = strtolower($os);
			$meta['serviceinfo']['class'] = strtolower($class);
			$meta['serviceinfo']['category'] = strtolower($category);
			$meta['serviceinfo']['desc'] = strtolower($desc);
			
			//Get the Nagios Object ID
			$objid = objid($meta);
			$meta['obj_id'] = $objid;
			
			echo print_r($meta['serviceinfo'], TRUE);
			
			// incident judgement calls
			//SERVICE STATE TYPE FILTER
			$servicestatetype = $meta['servicestatetype'];
			$is_softstate = is_soft_state($servicestatetype);
			if($is_softstate){
				$level = 'INFO';
				$msg = 'EVENT-HANDLER-DISCARD: SERVICE STATE TYPE IS SOFT'.$meta['host'].'. RECORD_ID='.$meta['serviceeventid'].'-'.$meta['serviceproblemid'].' SERVICE='.$meta['service'];
				logit($meta, $level, $msg);
				exit();
			}
                        
                        //UNKNOWN STATE FILTER
                        $servicestateid = $meta["servicestateid"];
			if($servicestateid == "3"){
				$level = 'INFO';
				$msg = 'EVENT-HANDLER-DISCARD: SERVICE STATE IS UNKNOWN '.$meta['host'].'. RECORD_ID='.$meta['serviceeventid'].'-'.$meta['serviceproblemid'].' SERVICE='.$meta['service'];
				logit($meta, $level, $msg);
				exit();
			}

			//LAST STATE TYPE
			//CLEARING INCIDENTS WILL CLOSE THE SNOW INC IF KNOWN
			$servicestate = $meta['servicestate'];
			$servicestateid = $meta['servicestateid'];
            		$lastservicestate = $meta['lastservicestate'];
            		$inc_type = last_state_service($servicestate, $lastservicestate);    
                        
                            
				if($inc_type == "clear"){
                                        $meta['serviceinfo']['state'] = "resolved";
					$meta['serviceinfo']['severity'] = $servicestate;
                                        $comment = get_last_comment($meta);
                                        $resolved = resolve_incident($meta);
                                        
                                        //Log and exit
					$level = 'INFO';
					$msg = 'EVENT-HANDLER-RESOLVE: RESOLVE EVENT '.$meta['host'].'. RECORD_ID='.$meta['serviceeventid'].'-'.$meta['serviceproblemid'].' SERVICE='.$meta['service'].' ACK='.$comment;
					logit($meta, $level, $msg);
					exit();
                }
				elseif($inc_type == "alert"){
					$meta['serviceinfo']['state'] = 'new';
				        $meta['serviceinfo']['severity'] = $servicestate;
					$meta['serviceinfo']['impact'] = $servicestateid;
					$inc = create_incident($meta);
				}
				elseif($inc_type == "escalate"){
					$meta['serviceinfo']['state'] = 'escalate';
				        $meta['serviceinfo']['severity'] = $servicestate;
					$meta['serviceinfo']['impact'] = $servicestateid;
					$inc = escalate_incident($meta);
				}
				elseif($inc_type == "downgrade"){
					$meta['serviceinfo']['state'] = 'downgrade';
				        $meta['serviceinfo']['severity'] = $servicestate;
					$meta['serviceinfo']['impact'] = $servicestateid;
					$inc = downgrade_incident($meta);
				}
				elseif($inc_type == "duplicate"){
					$meta['serviceinfo']['state'] = 'duplicate';
					$meta['serviceinfo']['severity'] = $servicestate;
					
					//Log and exit
					$level = 'ERROR';
					$msg = 'EVENT-HANDLER-DISCARD: DUPLICATE EVENT '.$meta['host'].'. RECORD_ID='.$meta['serviceeventid'].'-'.$meta['serviceproblemid'].' SERVICE='.$meta['service'].' COMMENT='.$meta['serviceackcomment'];
					logit($meta, $level, $msg);
					exit();
				}
				else{
					$meta['serviceinfo']['state'] = 'catchall';
					$meta['serviceinfo']['severity'] = $servicestate;
					
					//Log and exit
					$level = 'ERROR';
					$msg = 'EVENT-HANDLER-DISCARD: CATCHALL '.$meta['host'].'. RECORD_ID='.$meta['serviceeventid'].'-'.$meta['serviceproblemid'].' SERVICE='.$meta['service'].' COMMENT='.$meta['serviceackcomment'];
					logit($meta, $level, $msg);
					exit();	
				}
			
			
			if(!$inc){
				//FAILED TO INSERT INCIDENT
				$level = 'ERROR';
				$msg = 'EVENT-HANDLER-ERROR: WE FAILED TO GENERATE AN INCIDENT ON BEHALF OF '.$meta['host'].'. RECORD_ID='.$meta['serviceeventid'].'-'.$meta['serviceproblemid'].' SERVICE='.$meta['service'];
				logit($meta, $level, $msg);
				exit();
			}else{
				//INCIDENT CREATED
				$sysid = $inc->result->sys_id;
				
				//GRAB THE SYS_ID FROM THE JSON RETURNED BY SNOW AND INSERT IT INTO THE META ARRAY	
				$meta['sys_id'] = $sysid;	
				
				echo print_r($meta, TRUE);
				
				//$ack = ack_sys_id($meta);
                $ack = extAck($meta);
                
				if($ack){
					$level = 'SUCCESS';
					$msg = 'EVENT-HANDLER-SUCCESS: WE GENERATED AN INCIDENT ON BEHALF OF '.$meta['host'].'. RECORD_ID='.$meta['serviceeventid'].'-'.$meta['serviceproblemid'].' SERVICE='.$meta['service'].' - INCIDENT_ID='.$sysid;
					logit($meta, $level, $msg);
					exit();
				}else{
					$level = 'ERROR';
					$msg = 'EVENT-HANDLER-FAILED: WE FAILED TO ACK INCIDENT ON BEHALF OF '.$meta['host'].'. RECORD_ID='.$meta['serviceeventid'].'-'.$meta['serviceproblemid'].' SERVICE='.$meta['service'].' - INCIDENT_ID=NULL';
					logit($meta, $level, $msg);
					exit();
				}
			}
			
		}
	}

}

//Logging is to be sent directly to the PHP error log
function logit($meta, $level, $msg){
	$ptime_frmt = 'Y-m-d H:i:s';
	$ptime = date($ptime_frmt);
	shell_exec('echo "['.$ptime.']; '.$level.'; '.$msg.'" >> /tmp/snow-handler.log');
	//return TRUE;
	
}

function create_incident($meta){
	
	$uname = "<snowusername>";
	$upass = "<snowpassword>";
    $url = 'https://<snowurl>/api/now/table/incident';
	
	if($meta['servicestateid'] != ""){
		if($meta['servicestateid'] == "1"){
			//Warning
			$urgency = "2";
			$impact = "1";
		}elseif($meta['servicestateid'] == "2"){
			//Critical
			$urgency = "1";
			$impact = "1";
		}else{
			//Unknown
			$urgency = "3";
			$impact = "2";
		}
	}else{
		$urgency = "3";
		$impact = "3";
	}
	
	$data = array(
		"cmdb_ci" => $meta['host'],
		"urgency" => $urgency,
		"impact" => $impact,
		"caller_id" => "admin@example.com",
		"short_description" => $meta['service'],
		"description" => $meta['serviceoutput'],
		"parent_incident" => ""
	);
	if($data){
		echo print_r($data, TRUE);
	}
	
	$raw_json = '{"cmdb_ci":"'.$data['cmdb_ci'].'","short_description":"'.$data['short_description'].'","description":"'.$data['description'].'","urgency":"'.$data['urgency'].'","impact":"'.$data['impact'].'","caller_id":"'.$data['caller_id'].'", "parent_incident":"'.$data['parent_incident'].'"}';
			
	//THE CURL COMMAND
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_URL, $url);
    
        //THE PUT REQUEST
        //curl_setopt($ch, CURLOPT_POST, "1");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $raw_json);
    
        //JSON
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Accept: application/json'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
    
        //USE THE KEYS TO AUTHENTICATE
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $uname.":".$upass);
    
        //EXECUTE THE CURL COMMAND
        $result = curl_exec($ch);
        curl_close($ch);
    
        if($result){
    	        $apidata = json_decode($result);
        }
	
	if(DEBUG_OUT){
		echo print_r($apidata, TRUE);
	}
    
        return $apidata;
}

//ESCALATE
function escalate_incident($meta){
	
	$uname = "<snowusername>";
	$upass = "<snowpassword>";
        $sysID = get_last_comment($meta);
        $url = 'https://<snowurl>/api/now/table/incident/'.$sysID;
	
	$urgency = "1";
	$impact = "1";
	
	$data = array(
		"cmdb_ci" => $meta['host'],
		"urgency" => $urgency,
		"impact" => $impact,
                "caller_id" => "admin@example.com",
                "short_description" => $meta['service'],
		"description" => $meta['serviceoutput'],
		"work_notes" => "Incident has been elevated. Please review ASAP!",
		"parent_incident" => "TODO"
	);
	
	$raw_json = '{"cmdb_ci":"'.$data['cmdb_ci'].'","short_description":"'.$data['short_description'].'","description":"'.$data['description'].'","urgency":"'.$data['urgency'].'","impact":"'.$data['impact'].'","caller_id":"'.$data['caller_id'].'", "parent_incident":"'.$data['parent_incident'].'", "work_notes":"'.$data['work_notes'].'"}';
			
	//THE CURL COMMAND
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_URL, $url);
    
        //THE PUT REQUEST
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $raw_json);
    
        //JSON
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Accept: application/json'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
    
        //USE THE KEYS TO AUTHENTICATE
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $uname.":".$upass);
    
        //EXECUTE THE CURL COMMAND
        $result = curl_exec($ch);
        curl_close($ch);
    
        $closedata = json_decode($result);
    
        return $closedata;
}

//Downgrade incident 
function downgrade_incident($meta){
	
	$uname = "<snowusername>";
	$upass = "<snowpassword>";
        $sysID = get_last_comment($meta);
        $url = 'https://<snowurl>/api/now/table/incident/'.$sysID;
	
	$urgency = "2";
	$impact = "1";
	
	$data = array(
		"cmdb_ci" => $meta['host'],
		"urgency" => $urgency,
		"impact" => $impact,
		"caller_id" => "admin@example.com",
	        "short_description" => $meta['service'],
		"description" => $meta['serviceoutput'],
		"work_notes" => "Incident has been downgraded.",
		"parent_incident" => "TODO"
	);
	
	$raw_json = '{"cmdb_ci":"'.$data['cmdb_ci'].'","short_description":"'.$data['short_description'].'","description":"'.$data['description'].'","urgency":"'.$data['urgency'].'","impact":"'.$data['impact'].'","caller_id":"'.$data['caller_id'].'", "parent_incident":"'.$data['parent_incident'].'", "work_notes":"'.$data['work_notes'].'"}';
			
	//THE CURL COMMAND
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_URL, $url);
    
        //THE PUT REQUEST
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $raw_json);
    
        //JSON
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Accept: application/json'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
    
        //USE THE KEYS TO AUTHENTICATE
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $uname.":".$upass);
    
        //EXECUTE THE CURL COMMAND
        $result = curl_exec($ch);
        curl_close($ch);
    
        $closedata = json_decode($result);
    
        return $closedata;
}

function resolve_incident($meta){
	
	$uname = "<snowusername>";
        $upass = "<snowpassword>";
        $sysID = get_last_comment($meta);
        $url = 'https://<snowurl>/api/now/table/incident/'.$sysID;
	
	$data = array(
	        "incident_state" => "6",
                "resolution_code" => "closed/resolved by caller",
                "resolution_notes" => "nagios monitoring"
        );
	
	$raw_json = '{"incident_state":"'.$data['incident_state'].'","close_code":"closed/resolved by caller", "close_notes":"Self Healing Incident", "comments":"Closed by NagiosXI"}';
			
	//THE CURL COMMAND
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_URL, $url);
    
        //THE PUT REQUEST
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $raw_json);
        
        //JSON
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Accept: application/json'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
        
        //USE THE KEYS TO AUTHENTICATE
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $uname.":".$upass);
        
        //EXECUTE THE CURL COMMAND
        $result = curl_exec($ch);
        curl_close($ch);
        
        $closedata = json_decode($result);
        
        return $closedata;
}

function objid($meta){
	
	$link = mysqli_connect("localhost", "root", "nagiosxi", "nagios"); 

	if($link === false){ 
                die("ERROR: Could not connect. " 
                . mysqli_connect_error()); 
	} 

	$sql = "SELECT object_id FROM nagios_objects WHERE name1='".$meta['host']."' AND name2='".$meta['service']."'"; 
	if($res = mysqli_query($link, $sql)){ 
		if(mysqli_num_rows($res) > 0){ 
			while($row = mysqli_fetch_array($res)){ 
				$objid = $row['object_id'];
			} 
			mysqli_free_result($res); 
		} else{ 
                        //this is not good 
                        $objid = "XXXXX"; 
		} 
	} else{ 
		echo "ERROR: Could not able to execute $sql. " 
		. mysqli_error($link); 
	} 

	mysqli_close($link); 
	return $objid;
}

function get_last_comment($meta){
        $objectid = objid($meta);
        $link = mysqli_connect("localhost", "root", "nagiosxi", "nagios"); 

	if($link === false){ 
		die("ERROR: Could not connect. " 
		. mysqli_connect_error()); 
	} 

	$sql = "SELECT comment_data, entry_time FROM nagios_acknowledgements WHERE object_id='".$objectid."' ORDER BY entry_time DESC LIMIT 1";
    
        if($res = mysqli_query($link, $sql)){ 
		if(mysqli_num_rows($res) > 0){ 
			while($row = mysqli_fetch_array($res)){ 
				$sysid = $row['comment_data'];
			} 
			mysqli_free_result($res); 
		} else{ 
			$sysid = "XXXXX"; 
		} 
	} else{ 
		echo "ERROR: Could not able to execute $sql. ". mysqli_error($link); 
	} 

	mysqli_close($link); 
	return $sysid;
}

function ack_sys_id($meta){
	$link = mysqli_connect("localhost", "root", "nagiosxi", "nagios"); 

	if($link === false){ 
		die("ERROR: Could not connect. " 
					. mysqli_connect_error()); 
	} 

	$etime = date('Y-m-d H:i:s');
	$etime_day = gettimeofday();
	$sql="INSERT INTO nagios_acknowledgements (instance_id, entry_time, entry_time_usec, acknowledgement_type, object_id, state, author_name, comment_data, is_sticky, persistent_comment, notify_contacts) VALUES (1, '".$etime."', '".$etime_day['usec']."', 1, ".$meta['obj_id'].", ".$meta['servicestateid'].", 'Nagios Administrator', '".$meta['sys_id']."', 1, 0, 0)"; 
	if($res = mysqli_query($link, $sql)){ 
		$ret = true;
	} else{ 
		echo "ERROR: Could not able to execute $sql. ". mysqli_error($link). "\n \n";
		$ret = false;							 
	} 

	mysqli_close($link);
	return $ret; 
}

function extAck($meta){
        $myHostname = $meta['host'];
        $myServicename = $meta['service'];
        $myAuthor = "Nagios Administrator";
        $mySysid = $meta['sys_id'];

        //USE THE EXTERNAL COMMAND OR IT DOESN;T WORK!
        $cmd = shell_exec('/usr/local/nagios/libexec/nagack.sh "'.$myHostname.'" "'.$myServicename.'" "'.$myAuthor.'" "'.$mySysid.'"');
        echo $cmd;
        return TRUE;
}



/**
 * Determines if the event is in a SOFT or HARD State. Event Handler will drop ALL 
 * states that are SOFT. The event will not be handled until the STATE is listed as
 * HARD (All retrys exhausted).
 */
function is_soft_state($servicestatetype){
        if(strtoupper($servicestatetype) == 'SOFT'){
                $forward = TRUE;
        }else{
                $forward = FALSE;
        }
		return $forward;
}

/**
 * Compare the current service state to the last service state in Nagios to determine
 * if we should raise an event
 */
 
function last_state_service($servicestate, $lastservicestate){
        switch($servicestate){
        #SERVICE STATE IS NOW OK
        case "OK":
                #WHAT WAS THE LAST SERVICE STATE
                switch($lastservicestate){
                case "OK":
		        #LAST STATE WAS OK
		        #WE DO NOT TICKET FOR OK/OK, DROP
                        $forward = "duplicate";
                        break;
                case "WARNING":
                        #LAST STATE WAS WARNING
                        #WE SHOULD SEND TICKET FFOR THIS EVENT
                        $forward = "clear";
                        break;
                case "CRITICAL":
                        #LAST STATE WAS CRITICAL
                        #WE SHOULD SEND A TICKET FOR THIS EVENT
                        $forward = "clear";
                        break;
                case "UNKNOWN":
                        #LAST STATE WAS UNKNOWN
                        #WE SHOULD SEND A TICKET FOR THIS EVENT
                        $forward = "clear";
                        break;
                }
        break;
        #Service check is now critical
        case "CRITICAL":
                #WHAT WAS THE LAST SERVICE STATE
                switch($lastservicestate){
                        case "OK":
                        #LAST STATE WAS OK
                        $forward = "alert";
                        break;
                case "WARNING":
                        #LAST STATE WAS WARNING
                        $forward = "escalate";
                        break;
                case "CRITICAL":
                        #LAST STATE WAS CRITICAL
                        $forward = "alert";
                        break;
                case "UNKNOWN":
                        #LAST STATE WAS UNKNOWN
                        $forward = "alert";
                        break;
                }
        break;
        
        #SERVICE STATE IS NOW WARNING
        case "WARNING":
                #WHAT WAS THE LAST SERVICE STATE
                switch($lastservicestate){
                case "OK":
		        #LAST STATE WAS OK
                        $forward = "alert";
                        break;
                case "WARNING":
                        #LAST STATE WAS WARNING
                        $forward = "duplicate";
                        break;
                case "CRITICAL":
                        #LAST STATE WAS CRITICAL
                        $forward = "downgrade";
                        break;
                case "UNKNOWN":
                        #LAST STATE WAS UNKNOWN
                        $forward = "alert";
                        break;
                }
        break;
        
        #SERVICE STATE IS NOW OK
        case "UNKNOWN":
                #WHAT WAS THE LAST SERVICE STATE
                switch($lastservicestate){
                case "OK":
		        #LAST STATE WAS OK
                        $forward = "alert";
                        break;
                case "WARNING":
                        #LAST STATE WAS WARNING
                        $forward = "alert";
                        break;
                case "CRITICAL":
                        #LAST STATE WAS CRITICAL
                        $forward = "alert";
                        break;
                case "UNKNOWN":
                        #LAST STATE WAS UNKNOWN
                        $forward = "duplicate";
                        break;
                }
        break;
        }
        
        return $forward;
}

// Event Handler Data Helper Functions
// parse the arguments, pulled from utilsx.inc.php 7/18/2016
function parse_argv($argv)
{
    array_shift($argv);
    $out = array();
    foreach ($argv as $arg) {

        if (substr($arg, 0, 2) == '--') {
            $eq = strpos($arg, '=');
            if ($eq === false) {
                $key = substr($arg, 2);
                $out[$key] = isset($out[$key]) ? $out[$key] : true;
            } else {
                $key = substr($arg, 2, $eq - 2);
                $out[$key] = substr($arg, $eq + 1);
            }
        } else if (substr($arg, 0, 1) == '-') {
            if (substr($arg, 2, 1) == '=') {
                $key = substr($arg, 1, 1);
                $out[$key] = substr($arg, 3);
            } else {
                $chars = str_split(substr($arg, 1));
                foreach ($chars as $char) {
                    $key = $char;
                    $out[$key] = isset($out[$key]) ? $out[$key] : true;
                }
            }
        } else {
            $out[] = $arg;
        }
    }

    return $out;
}
