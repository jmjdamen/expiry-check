<?php
//use credentials and mailsettings from external file
include 'settings.php';
//swift mailer include and configuration
require_once './swiftmailer/lib/swift_required.php';

$transporter = Swift_SmtpTransport::newInstance('smtp.gmail.com', 465, 'ssl')
  ->setUsername($mail_username)
  ->setPassword($mail_password);

$mailer = Swift_Mailer::newInstance($transporter);

//create the mail transport configuration
$transport = Swift_MailTransport::newInstance();

//set the wanted entity state
//state 'testaccepted' or 'prodaccepted'
//dont change type to idp, queries need to be modified for idp use
$type = "'saml20-sp'";
$state = "'testaccepted'";

//set expiry values in days
//amount of days ago the last login was made 
$lastlogin_value = '180';
//amount of days that service registry entries that were created have to be excluded 
$sr_created_value = '60';
//amount of days a SP gets to perform a login and so he can keep his entity in SURFconext
$nextlogin_value = '14';

//set current date
$date = date('d-m-Y');

//get expired entities and entities that can be removed from files
if (file_exists("expired.json")){
$get_expired_check = json_decode(file_get_contents("expired.json"), true);

if (file_exists("remove.json")){
$get_remove_check = json_decode(file_get_contents("remove.json"), true);
    
foreach($get_expired_check as $eidkey => $datevalue){
if (strcmp ($datevalue, $date) == 0) {
print_r($datevalue);
array_push($get_remove_check[$eidkey]="");
unset($get_expired_check[$eidkey]);
}
}    
file_put_contents("remove.json",json_encode($get_remove_check));
file_put_contents("expired.json",json_encode($get_expired_check));
}
}
    
try {
    $conn = new PDO('mysql:host=localhost;dbname=stats', $db_username, $db_password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    print_r("Script running, started at: ");
    print_r(date("m/d/y G.i:s", time())."\n");
    
    // Check for Test SPs that had their last login more than x days ago
    $q_expired = $conn->prepare("
                                SELECT c.id, c.revisionNr, c.name, l.maxstamp  
                                FROM sr.janus__connection c
                                                              
                                INNER JOIN
                                (SELECT spentityid, MAX(loginstamp) as maxstamp
                                FROM stats.log_logins
                                GROUP BY spentityid
                                HAVING MAX(loginstamp) < (now() - interval $lastlogin_value DAY)) l
                                ON c.name = l.spentityid
                                
								INNER JOIN
								(SELECT eid, revisionid, state
								FROM sr.janus__connectionRevision
                                WHERE type LIKE $type
								AND state LIKE $state) r
								ON c.id = r.eid
								AND c.revisionNr = r.revisionid
								
                                GROUP BY c.id
    
                                ");
    
    //execute x days expired check and fill array
    print_r("Executing x days expired\n");
    $q_expired->execute();
    $arr_expired = $q_expired->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_ASSOC);
    
    //print_r($arr_expired);
    print_r(count($arr_expired)."\n");
   
    //check for Test SPs that had their last login more than 0 days ago for comparison with JANUS entities
    $q_stats = $conn->prepare("
								SELECT c.id, c.revisionNr, c.name, l.maxstamp  
                                FROM sr.janus__connection c
                                                              
                                INNER JOIN
                                (SELECT spentityid, MAX(loginstamp) as maxstamp
                                FROM stats.log_logins
                                GROUP BY spentityid
                                HAVING MAX(loginstamp) < (now() - interval '0' DAY)) l
                                ON c.name = l.spentityid
                                
								INNER JOIN
								(SELECT eid, revisionid, state
								FROM sr.janus__connectionRevision
                                WHERE type LIKE $type
								AND state LIKE $state) r
								ON c.id = r.eid
								AND c.revisionNr = r.revisionid
								
                                GROUP BY c.id
                                ");
    
    //execute 0 days expired check and fill array
    sleep(5);
    print_r("Executing 0 days expired\n");
    $q_stats->execute();
    $arr_stats = $q_stats->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_ASSOC);

    print_r(count($arr_stats)."\n");
    
    //check for Test SPs in JANUS that are created more than x days ago
    //otherwise new SP's would receive notifications that they will be removed
    $q_janusentities = $conn->prepare("  
                               	SELECT c.id, c.revisionNr, c.name, c.created
                                FROM sr.janus__connection c
								
								INNER JOIN
								(SELECT eid, revisionid, state
								FROM sr.janus__connectionRevision
                                WHERE type LIKE $type
								AND state LIKE $state) r
								ON c.id = r.eid
								AND c.revisionNr = r.revisionid
								
								WHERE c.created < (now() - interval $sr_created_value DAY)

                                GROUP BY c.id
                                ");
    
    
    //execute JANUS entities > x days check and fill array
    sleep(5);
    print_r("Executing JANUS created > x days\n");
    $q_janusentities->execute();
    $arr_janusentities = $q_janusentities->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_ASSOC);

    //print_r($arr_janusentities);
    print_r(count($arr_janusentities)."\n");
    
    //check for entries that contain a value for the 'coin:gadgetbaseurl' attribute (OAuth) and where ACS Location is empty
    $q_oauth = $conn->prepare("
                                SELECT m.eid, c.revisionNr, m.key, m.value
                                FROM stats.janus__metadata m

                                INNER JOIN
                                (SELECT id, revisionNr
                                FROM sr.janus__connection
                                GROUP BY id) c
                                ON m.eid = c.id
								
								INNER JOIN
								(SELECT m.eid, m.key, m.value
                                FROM stats.janus__metadata m
                                WHERE m.key like 'coin:gadgetbaseurl'
                                AND (m.value IS NOT NULL OR m.value NOT LIKE '' )) g
				ON m.eid = g.eid
				WHERE m.key like 'AssertionConsumerService:0:Location'
				AND m.value LIKE ''

                                AND m.revisionid = c.revisionNr
                                GROUP BY m.eid
                                ");
    
    //execute OAuth check and fill array
    sleep(5);
    print_r("Executing Oauth check\n");
    $q_oauth->execute();
    $arr_oauth = $q_oauth->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_ASSOC);

    //print_r($arr_oauth);
    print_r(count($arr_oauth)."\n");
    
    //*** Compare and fill final array ***
    //compare found JANUS entries to 0 days entries and fill array with JANUS entries that are not in 0 days
    $arr_missing = array_diff_key($arr_janusentities,$arr_stats);
    
    //print_r($arr_missing);
    print_r(count($arr_missing)."\n");
    
    //combine the 180 days expired array with the missing entries from JANUS
    $arr_result = $arr_expired + $arr_missing;
    
    //check arr_result for entities that contain oauth gadgetbase urls and where ACS Location was empty remove them
    $arr_def = array_diff_key($arr_result, $arr_oauth);
        
    //create array $keys, fetch array keys from $arr_def and fill $keys with those values (eid's)
    $keys = array();
    foreach(array_keys($arr_def) as $key_def)
    array_push($keys, $key_def);

    file_put_contents('keys.txt', print_r($keys, true));

    //put all eid's in one string for use in SQL contacts query
    $eids = implode(',',$keys);

    file_put_contents('eids.txt', print_r($eids, true));
    //print_r($eids);
    //print_r("start contact query!\n");


    //execute contacts query with statement that it only returns records where the eid is in $eids
    $q_contacts = $conn->prepare("
                        SELECT * FROM (
                        SELECT * FROM (
                        SELECT	m.eid,
                                    m.revisionid,
                                    e.state,
                                    e.type,
                                    e.entityid,
                                    m.created as lastupdated,
                                    GROUP_CONCAT(IF (m.key = 'name:en',m.value,NULL)) AS 'name:en',
                                    GROUP_CONCAT(IF (m.key = 'contacts:0:emailAddress',m.value,NULL)) AS 'email',
                                    GROUP_CONCAT(IF (m.key = 'contacts:0:contactType',m.value,NULL)) AS 'contactType',
                                    GROUP_CONCAT(IF (m.key = 'contacts:0:givenName',m.value,NULL)) AS 'givenName',
                                    GROUP_CONCAT(IF (m.key = 'contacts:0:surName',m.value,NULL)) AS 'surName'
                                    FROM `stats`.`janus__metadata` m,
                                    (select e1.eid, e1.revisionid, e1.entityid, e1.state, e1.type from `sr`.`janus__connectionRevision` e1,
                                    (SELECT id, revisionNr AS lastrevisionid
                                    FROM `sr`.`janus__connection`
                                    GROUP by id) e2
                                WHERE e1.eid = e2.id
                                AND e1.revisionid = e2.lastrevisionid) e
                            WHERE m.eid = e.eid
                            AND m.revisionid = e.revisionid
                            AND m.key IN ('contacts:0:emailAddress','contacts:0:contactType','contacts:0:givenName','contacts:0:surName')
                            GROUP BY m.eid
                            ORDER BY type, state, `name:en`) k
                            where (contactType = 'technical' AND (email IS NOT NULL AND email != '' ))
                            
                            union
                            
                        SELECT * FROM (
                        SELECT	m.eid,
                                    m.revisionid,
                                    e.state,
                                    e.type,
                                    e.entityid,
                                    m.created as lastupdated,
                                    GROUP_CONCAT(IF (m.key = 'name:en',m.value,NULL)) AS 'name:en',
                                    GROUP_CONCAT(IF (m.key = 'contacts:1:emailAddress',m.value,NULL)) AS 'email',
                                    GROUP_CONCAT(IF (m.key = 'contacts:1:contactType',m.value,NULL)) AS 'contactType',
                                    GROUP_CONCAT(IF (m.key = 'contacts:1:givenName',m.value,NULL)) AS 'givenName',
                                    GROUP_CONCAT(IF (m.key = 'contacts:1:surName',m.value,NULL)) AS 'surName'
                                    FROM `stats`.`janus__metadata` m,
                                    (select e1.eid, e1.revisionid, e1.entityid, e1.state, e1.type from `sr`.`janus__connectionRevision` e1,
                                    (SELECT id, revisionNr AS lastrevisionid
                                    FROM `sr`.`janus__connection`
                                    GROUP by id) e2
                                WHERE e1.eid = e2.id
                                AND e1.revisionid = e2.lastrevisionid) e
                            WHERE m.eid = e.eid
                            AND m.revisionid = e.revisionid
                            AND m.key IN ('contacts:1:emailAddress','contacts:1:contactType','contacts:1:givenName','contacts:1:surName')
                            GROUP BY m.eid
                            ORDER BY type, state, `name:en`) i
                            where (contactType = 'technical' AND (email IS NOT NULL AND email != '' ))
                        
                        union
                        
                        SELECT * FROM (
                        SELECT	m.eid,
                                    m.revisionid,
                                    e.state,
                                    e.type,
                                    e.entityid,
                                    m.created as lastupdated,
                                    GROUP_CONCAT(IF (m.key = 'name:en',m.value,NULL)) AS 'name:en',
                                    GROUP_CONCAT(IF (m.key = 'contacts:2:emailAddress',m.value,NULL)) AS 'email',
                                    GROUP_CONCAT(IF (m.key = 'contacts:2:contactType',m.value,NULL)) AS 'contactType',
                                    GROUP_CONCAT(IF (m.key = 'contacts:2:givenName',m.value,NULL)) AS 'givenName',
                                    GROUP_CONCAT(IF (m.key = 'contacts:2:surName',m.value,NULL)) AS 'surName'
                                    FROM `stats`.`janus__metadata` m,
                                    (	select e1.eid, e1.revisionid, e1.entityid, e1.state, e1.type from sr.janus__connectionRevision e1,
                                    (SELECT id, revisionNr AS lastrevisionid
                                    FROM `sr`.`janus__connection`
                                    GROUP by id) e2
                                WHERE e1.eid = e2.id
                                AND e1.revisionid = e2.lastrevisionid) e
                            WHERE m.eid = e.eid
                            AND m.revisionid = e.revisionid
                            AND m.key IN ('contacts:2:emailAddress','contacts:2:contactType','contacts:2:givenName','contacts:2:surName')
                            GROUP BY m.eid
                            ORDER BY type, state, `name:en`) o
                            where (contactType = 'technical' AND (email IS NOT NULL AND email != '' )) ) a
                            WHERE eid IN ($eids) 
			    GROUP BY eid
                        ");
        
    $q_contacts->execute();
    $arr_contacts = $q_contacts->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_ASSOC);   

    //write arrays to files
    file_put_contents('janus.txt', print_r($arr_janusentities, true));
    file_put_contents('stats.txt', print_r($arr_stats, true));
                                 
    file_put_contents('oauth.txt', print_r($arr_oauth, true));
    file_put_contents('expired.txt', print_r($arr_expired, true));
    file_put_contents('missing.txt', print_r($arr_missing, true));
    file_put_contents('result.txt', print_r($arr_result, true));
    file_put_contents('def.txt', print_r($arr_def, true));
    file_put_contents('contacts.txt', print_r($arr_contacts, true));

    print_r("Total results (expired+missing)-oauth:" . (count($arr_def)) . "\n");
    
    //preparing for mailer
    //create array to keep record to which eid (contacts) have been emailed 
    //check if file with mailed eid's exists, if not dont try to open, create empty array
    if (file_exists("expired.json")){
    $get_expired = json_decode(file_get_contents("expired.json"), true);}
    else { $get_expired = array();}

    if (file_exists("remove.json")){
    $get_remove = json_decode(file_get_contents("remove.json"), true);}
    else { $get_remove = array();}
    //TEST
    file_put_contents("expiredtest.json",json_encode($get_expired), true);

    //iterate through nested arrays
    foreach($arr_contacts as $mainkey => $item){
        
        $eid = $mainkey;
        if(!array_key_exists($eid, $get_expired) && !array_key_exists($eid, $get_remove)){  
      
        foreach($item as $subkey=>$item2){
            foreach($item2 as $key=>$value){
               
            if ($key == "entityid")
               {$id = $value;}
        
            if ($key == "email")
               {$mail = $value;}
            
            if ($key == "givenName")
               {$firstname = $value;}
            
            if ($key == "surName")
               {$lastname = $value;}
    }
     
    }
    
    echo("entityid: ".$id."\n");
    echo("email: ".$mail."\n");
    echo("firstname: ".$firstname."\n");
    echo("lastname: ".$lastname."\n");
    echo("eid: ".$eid."\n");
    echo("\n");

    //set eid with expiration date in array
    $expired_set[$eid] = date('d-m-Y', strtotime($date. ' + ' . $nextlogin_value . ' days'));
  
         
    //create the message
    $message = Swift_Message::newInstance();
    //file is standard configured for testpurposes the next line is the one that emails to contacts
    //$message->setTo(array($mail => $firstname . ' ' . $lastname));   
    $message->setTo(array("jmjdamen@gmail.com" => "Joost Damen"));

    $message->setSubject("Connection expired");

    $message->setBody('Dear, ' . $firstname . ' ' . $lastname .',

According to our administration there is a connection registered on SURFconext in testmodus.
Entityid: ' . $id . '
Your contact details are registered with this connection.
Either your Service Provider has not been used for over ' . $lastlogin_value . ' days with SURFconext.
Or you are registered in SURFconext for over ' . $sr_created_value . ' days and did not have a login yet.
If you want to maintain this connection and want to use this connection in the future:

  * Make sure you perform a login within the next ' . $nextlogin_value . ' days
  * Otherwise this connection will be removed automatically
  * Please do not reply to this email, if you have an urging question about this message please send an email to support@surfconext.nl');

    $message->setFrom(array($mail_from_address => $mail_from_name));

    // Send the email
    $mailer = Swift_Mailer::newInstance($transport);
    $mailer->send($message);
    
        
                                                }
    else{
		if(!array_key_exists($eid, $get_remove)){
            	$datetext = $get_expired[$eid];
		        $expired_set[$eid] = $datetext;}
		}
        
    }

    file_put_contents("expired.json",json_encode($expired_set), true);


    print_r("Script finished, ended at: ");
    print_r(date("m/d/y G.i:s", time())."\n");
        
} 
    catch(PDOException $e) {
    echo 'ERROR: ' . $e->getMessage();
}

?>
