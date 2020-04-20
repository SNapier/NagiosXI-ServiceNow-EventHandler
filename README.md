# NagiosXI-ServiceNow-EventHandler
On-State-Change event handler that will create a service now incident and post the sysid as an ack to the NagiosXi event.

## Handler Usage

### URL
1. ServiceNow URL
Create a develper account on developer.servicenow.com

### Credentials
1. In the handler edit ServiceNow Credentials
  Lines 102-104
    $uname = "snusername";
    $upass = "snpassword";
    $url = 'https://<SNOW-URL>/api/now/table/incident';

2. In the handler edit the NagiosXI MySQL info
  Line 157
    $link = mysqli_connect("<hostname/localhost>", "<dbuser>", "<dbpassword>", "<dbname>");
  Line 186
    $link = mysqli_connect("<hostname/localhost>", "<dbuser>", "<dbpassword>", "<dbname>");

### General Usage
1. Uoload the handler to the "/usr/local/nagios/libexec" directory
2. chmod +x nagiosxi-snow-hander.php
3. Create the command for the handler in the Xi interface
4. Enable needed macros within NagiosXI
5. Create the nagiosxi-snow-handler command in nagiosXI

/usr/bin/php /usr/local/nagios/libexec/nagiosxi-snow-handler.php --handler-type=service --host="$HOSTNAME$" --service="$SERVICEDESC$" --hostaddress="$HOSTADDRESS$" --hoststate=$HOSTSTATE$ --hoststateid=$HOSTSTATEID$ --hosteventid=$HOSTEVENTID$ --hostproblemid=$HOSTPROBLEMID$ --servicestate=$SERVICESTATE$ --servicestateid=$SERVICESTATEID$ --lastservicestate=$LASTSERVICESTATE$ --lastservicestateid=$LASTSERVICESTATEID$ --lastserviceeventid=$LASTSERVICEEVENTID$ --lastserviceproblemid=$LASTSERVICEPROBLEMID$ --servicestatetype=$SERVICESTATETYPE$ --currentattempt=$SERVICEATTEMPT$ --maxattempts=$MAXSERVICEATTEMPTS$ --serviceeventid=$SERVICEEVENTID$ --serviceproblemid=$SERVICEPROBLEMID$ --serviceoutput="$SERVICEOUTPUT$" --longserviceoutput="$LONGSERVICEOUTPUT$" --servicedowntime=$SERVICEDOWNTIME$ --serviceackcomment="$SERVICEACKCOMMENT$""

