# SURFconext SP Expiry Check

### In the settings.php file you need to set these values

| //db access
| //the user needs access on the 'stats' and 'sr' database
| $db_username = "";
| $db_password = "";

| //username and password for swiftmailer
| $mail_username = '';
| $mail_password = '';

| //TO address and name for swiftmailer, for example service desk mailaddress
| //you can also uncomment a line in expiry.php to mail to each contact automatically
| $mail_to_address = "";
| $mail_to_name = "";

| //from address and name for swiftmailer
| $mail_from_address = "mail@replacethisexample.com";
| $mail_from_name = "replace thisisanexample";

--------------------------------------------------------------------------------------

### The following values can be changed in expiry.php

| Set the correct outgoing mailserver configuration for Swift Mailer in this line:
| $transporter = Swift_SmtpTransport::newInstance('outgoing.mail.com', 465, 'ssl')
| More info about Swift Mailer: www.swiftmailer.org

| //set the wanted entity state
| //state 'testaccepted' or 'prodaccepted'
| //dont change type to idp, queries need to be modified for idp use
| $type = "'saml20-sp'";
| $state = "'testaccepted'";

| //set expiry values in days
| //amount of days ago the last login was made 
| $lastlogin_value = '180';
| //amount of days that service registry entries that were created have to be excluded 
| $sr_created_value = '60';
| //amount of days a SP gets to perform a login and so he can keep his entity in SURFconext
| $nextlogin_value = '14';

--------------------------------------------------------------------------------------

### expired.json

| In the expired.json file the entities that are marked as 'expired' are written.
| An email has been sent to the given TO mailaddress as configured in settings.php.
| As mentioned earlier there is an option to use the mailaddress of the contact of each SP.

| DEFAULT
| If you prefer to mail the details of expired entities to the service desk use this line with settings from settings.php
| //the TO mailaddress and name are set for testpurposes, for example the service desk address in the settings file
| $message->setTo(array($mail_to_address => $mail_to_name));

| OPTION
| This line needs to be uncommented in the code:
| //file is standard configured for testpurposes the next line is the one that emails to contacts
| //$message->setTo(array($mail => $firstname . ' ' . $lastname));

--------------------------------------------------------------------------------------
### remove.json

| The remove.json contains entities that had been marked as 'expired' and didn't had a login after the notification.
| You can remove them from service registry (manual) or use the API to remove them automatically.

