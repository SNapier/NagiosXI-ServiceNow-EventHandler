# NagiosXI-ServiceNow-EventHandler
On-State-Change event handler that will create a ServiceNow incident and post the resulting SysID as a sticky acknowledgement to the NagiosXi Service event.


## Context
I wanted a method that coupld be applied either on-demand or globaly to NagiosXI that would allow for me to use ServiceNow as the Incident Management platform for service checks while allowing me to keep the Host Notification and escalation within the umbrella of NagiosXI.


## Criteria 
This method needed to include logic for basic filtering that could be applied as a part of the alarm gorvernance strategy to be applied globaly.

This method needed to be independent of the Notification methods in NagiosXI to reduce toil in contact management and foster least priviledge access practices.

This method would provide for On State Change logics to automatically determine the event type to forward to ServiceNow.

This methos would provide a deduplication key to be forwarded to ServiceNow as part of the alarm governance strategy.

This method would use the ServiceNow Glide API to create incidents within my chosen ServiceNow instance.

This method would temporarily store the ServiceNow Incient SysID to provide future updates to ServiceNow incidents.

This method would provide for escalation, demotion and auto-resolution of ServiceNow incidents.

## NagiosXI Service Naming Convention
Within my Nagios deployments I implement a naming convention consisting of four fields sepperated with double tacks.

`<os>--<class>--<category>--<descriptor>`

I have used this convention within the handler, service names not following this standard will not produce the expected results.


###This convention can be extended to provide for severity base event handling by adding the severity to forward to the naming convention and adding the needed code for processing within the Alert, Escalate, Downgrade, Duplicate functions.

`<os>--<class>--<category>--<forwardingseverity>--<descriptor>` 

Within the handler I am able to parse these values to easily add judgement calls and more complex routing logic.


## Implementing the NagiosXI-Servicenow-EventHandler
This worked for me and is intended as an example for use within a development environment only.

This handler does not provide for any secrets management.

The "Then a miracle happens" step is implied.


### Your ServiceNow URL
1. ServiceNow Instance URL
The handler uses the ServiceNow Glide API to create the incident. I reccomend that users create an account on https://developer.servicenow.com/ to begin integration testing/development.

### Supply ServiceNow Credentials
1. In the handler edit ServiceNow Credentials
  Replace all occurrences of
    `
    $uname = "<snowusername>";
    $upass = "<snowpassword>";
    $url = 'https://<snowurl>/api/now/table/incident';
    `

### Your NagiosXI MySQL Credentials
1. In the handler all mysql credential as those of the default NagiosXI install. Update all instances for your environment. 
    `$link = mysqli_connect("<hostname/localhost>", "root", "nagiosxi", "nagios");`


### NagiosXI Handler Prep
1. Upload the handler to the "/usr/local/nagios/libexec" directory
2. chmod +x nagiosxi-snow-hander.php
3. chown the script to "nagios:nagios" 
4. Create the command for the handler in the NagiosXI interface
5. Enable needed macros within NagiosXI
6. Create the nagiosxi-snow-handler command in NagiosXI

/usr/bin/php /usr/local/nagios/libexec/nagiosxi-snow-handler.php --handler-type=service --host="$HOSTNAME$" --service="$SERVICEDESC$" --hostaddress="$HOSTADDRESS$" --hoststate=$HOSTSTATE$ --hoststateid=$HOSTSTATEID$ --hosteventid=$HOSTEVENTID$ --hostproblemid=$HOSTPROBLEMID$ --servicestate=$SERVICESTATE$ --servicestateid=$SERVICESTATEID$ --lastservicestate=$LASTSERVICESTATE$ --lastservicestateid=$LASTSERVICESTATEID$ --lastserviceeventid=$LASTSERVICEEVENTID$ --lastserviceproblemid=$LASTSERVICEPROBLEMID$ --servicestatetype=$SERVICESTATETYPE$ --currentattempt=$SERVICEATTEMPT$ --maxattempts=$MAXSERVICEATTEMPTS$ --serviceeventid=$SERVICEEVENTID$ --serviceproblemid=$SERVICEPROBLEMID$ --serviceoutput="$SERVICEOUTPUT$" --longserviceoutput="$LONGSERVICEOUTPUT$" --servicedowntime=$SERVICEDOWNTIME$

### NagiosXI Handler Deployment Methods
There are two basic strategies for using this event handler as part of your event routing pipe-line.

#### Global
This is accomplished by editing the nagios.cfg entry for the global service event handler (aka the default service handler). 
Please take note;

NOT FOR BEGINNERS!

THIS IS A GLOBAL CHANGE AND APPLIES TO ALL SERVICE CHECKS (THIS MEANS LOGS TOO)! 


1. Comment out the existing entry under GLOBAL EVENT HANDLERS
#global_service_event_handler=xi_service_event_handler

2. Add a new line right below the original with new handler name
global_service_event_handler=nagiosxi-snow-handler

#### On-demand
Just follow the directions in the Nagios Doumentation
https://assets.nagios.com/downloads/nagiosxi/docs/Introduction-To-Event-Handlers-in-Nagios-XI.pdf

