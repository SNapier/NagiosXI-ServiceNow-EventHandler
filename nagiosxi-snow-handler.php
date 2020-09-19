#!/usr/bin/php -q
<?php
// Nagios to Service Now Glide API Incident Creation
// Version 0.0.20
// Created by Sam Napier

define('CFG_ONLY', 1);
define('DEBUG_OUT', 1);

// DO THE WORK
handle_state_change();

function handle_state_change(){

        // Define the global argument to be used for the incident
        global $argv;

        $meta = parse_argv($argv);

        if(DEBUG_OUT){
                echo print_r($meta, TRUE);
                $level = 'INFO';
                $msg = "EVENT-HANDLER-DEBUG: Handler debugging is enabled. This may cause large file sizes.";
                logit($meta, $level, $msg);
        }

        //We only want to forward events for monitored hosts
        //This is a basic exclusion list for hosts with alerts to be discarded
        $discard_hosts = array('localhost', 'HOSTNAME', 'hostname');
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

                        $objid = objid($meta);
                        $meta['obj_id'] = $objid;

                        if(DEBUG_OUT){
                                echo print_r($meta['serviceinfo'], TRUE);
                        }

                        //API
                        $inc = create_incident($meta);
                        $sysid = $inc->result->sys_id;

                        if(!$inc){
                                //FAILED TO INSERT INCIDENT
                                $level = 'ERROR';
                                $msg = 'EVENT-HANDLER-ERROR: WE FAILED TO GENERATE AN INCIDENT ON BEHALF OF '.$meta['host'].'. RECORD_ID='.$meta['serviceeventid'].'-'.$meta['serviceproblemid'].' SERVICE='.$meta['service'];
                                logit($meta, $level, $msg);
                                exit();
                        }else{
                                //INCIDENT CREATED
                                //GRAB THE SYS_ID FROM THE JSON RETURNED BY SNOW AND INSERT IT INTO THE META ARRAY
                                $meta['sys_id'] = $sysid;

                                echo print_r($meta, TRUE);
                                $ack = ack_sys_id($meta);
                                if($ack){
                                        $level = 'SUCCESS';
                                        $msg = 'EVENT-HANDLER-SUCCESS: WE GENERATED AN INCIDENT ON BEHALF OF '.$meta['host'].'. RECORD_ID='.$meta['serviceeventid'].'-'.$meta['serviceproblemid'].' SERVICE='.$meta['service'].' - INCIDENT_ID';
                                        logit($meta, $level, $msg);
                                        exit();
                                }else{
                                        $level = 'ERROR';
                                        $msg = 'EVENT-HANDLER-FAILED: WE FAILED TO ACK INCIDENT ON BEHALF OF '.$meta['host'].'. RECORD_ID='.$meta['serviceeventid'].'-'.$meta['serviceproblemid'].' SERVICE='.$meta['service'].' - INCIDENT_ID';
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
        shell_exec('echo "['.$ptime.']; '.$level.'; '.$msg.'" >> /tmp/php_errors.log');

}

// Create a Service Now incident with the triggering event data
function create_incident($meta){
    $uname = "snusername";
    $upass = "snpassword";
    $url = 'https://<SNOW-URL>/api/now/table/incident';

        $data = array(
                                        "cmdb_ci" => $meta['host'],
                                        "urgency" => "2",
                                        "impact" => "2",
                                        "caller_id" => "nagiosack@example.com",
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

// Pull the ObJectID for the Event
function objid($meta){
        // Edit to be the credentials for the local nagios MySQL DB
        $link = mysqli_connect("<hostname/localhost>", "<dbuser>", "<dbpassword>", "<dbname>");

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
                        $objid = "XXXXX";
                }
        } else{
                echo "ERROR: Could not able to execute $sql. "
                . mysqli_error($link);
        }

        mysqli_close($link);
        return $objid;
}

//ACK the triggering event with the Service Now SysID
function ack_sys_id($meta){
       // Edit to be the credentials for the local nagios MySQL DB
       $link = mysqli_connect("<hostname/localhost>", "<dbuser>", "<dbpassword>", "<dbname>");

        if($link === false){
                die("ERROR: Could not connect. "
                . mysqli_connect_error());
        }

        $etime = date('Y-m-d H:i:s');
        $etime_day = gettimeofday();
        $sql="INSERT INTO nagios_acknowledgements (acknowledgement_id, instance_id, entry_time, entry_time_usec, acknowledgement_type, object_id, state, author_name, comment_data, is_sticky, persistent_comment, notify_contacts) VALUES ("", 1, '".$etime."', '".$etime_day['usec']."', 1, ".$meta['obj_id'].", ".$meta['servicestateid'].", 'Nagios Administrator', '".$meta['sys_id']."', 1, 0, 0)";
        if($res = mysqli_query($link, $sql)){
                $ret = true;
        } else{
                echo "ERROR: Could not able to execute $sql. ". mysqli_error($link). "\n \n";
                $ret = false;
        }

        mysqli_close($link);
        return $ret;
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
