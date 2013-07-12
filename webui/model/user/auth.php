<?php

class ModelUserAuth extends Model {

   public function checkLogin($username = '', $password = '') {
      $ok = 0;

      if($username == '' || $password == '') { return 0; }

      if(ENABLE_LDAP_AUTH == 1) {
         $ok = $this->checkLoginAgainstLDAP($username, $password);
         if($ok == 1) { return $ok; }
      }

      if(ENABLE_IMAP_AUTH == 1) {
         require 'Zend/Mail/Protocol/Imap.php';
         $ok = $this->checkLoginAgainstIMAP($username, $password);
         if($ok == 1) { return $ok; }
      }


      // fallback local auth

      $query = $this->db->query("SELECT u.username, u.uid, u.realname, u.dn, u.password, u.isadmin, u.domain FROM " . TABLE_USER . " u, " . TABLE_EMAIL . " e WHERE e.email=? AND e.uid=u.uid", array($username));

      if(!isset($query->row['password'])) { return 0; }

      $pass = crypt($password, $query->row['password']);

      if($pass == $query->row['password']){
         $ok = 1;

         AUDIT(ACTION_LOGIN, $username, '', '', 'successful auth against user table');
      }
      else {
         AUDIT(ACTION_LOGIN_FAILED, $username, '', '', 'failed auth against user table');
      }

      if($ok == 0 && strlen($query->row['dn']) > 3) {
         $ok = $this->checkLoginAgainstFallbackLDAP($query->row, $password);
      }


      if($ok == 1) {
         $_SESSION['username'] = $query->row['username'];
         $_SESSION['uid'] = $query->row['uid'];
         $_SESSION['admin_user'] = $query->row['isadmin'];
         $_SESSION['email'] = $username;
         $_SESSION['domain'] = $query->row['domain'];
         $_SESSION['realname'] = $query->row['realname'];

         $_SESSION['auditdomains'] = $this->model_user_user->get_users_all_domains($query->row['uid']);
         $_SESSION['emails'] = $this->model_user_user->get_users_all_email_addresses($query->row['uid']);
         $_SESSION['folders'] = $this->model_folder_folder->get_all_folder_ids($query->row['uid']);
         $_SESSION['extra_folders'] = $this->model_folder_folder->get_all_extra_folder_ids($query->row['uid']);

         return 1;
      }

      return 0;
   }


   private function checkLoginAgainstLDAP($username = '', $password = '') {

      $ldap_host = LDAP_HOST;
      $ldap_base_dn = LDAP_BASE_DN;
      $ldap_helper_dn = LDAP_HELPER_DN;
      $ldap_helper_password = LDAP_HELPER_PASSWORD;

      $ldap_mail_attr = LDAP_MAIL_ATTR;
      $ldap_account_objectclass = LDAP_ACCOUNT_OBJECTCLASS;
      $ldap_distributionlist_attr = LDAP_DISTRIBUTIONLIST_ATTR;
      $ldap_distributionlist_objectclass = LDAP_DISTRIBUTIONLIST_OBJECTCLASS;

      if(ENABLE_SAAS == 1) {
         $a = $this->model_saas_ldap->get_ldap_params_by_email($username);

         $ldap_type = $a[0];
         $ldap_host = $a[1];
         $ldap_base_dn = $a[2];
         $ldap_helper_dn = $a[3];
         $ldap_helper_password = $a[4];

         switch ($ldap_type) {

            case 'AD':
                       $ldap_mail_attr = 'mail';
                       $ldap_account_objectclass = 'user';
                       $ldap_distributionlist_attr = 'member';
                       $ldap_distributionlist_objectclass = 'group';
                       break;

            case 'zimbra':
                       $ldap_mail_attr = 'mail';
                       $ldap_account_objectclass = 'zimbraAccount';
                       $ldap_distributionlist_attr = 'zimbraMailForwardingAddress';
                       $ldap_distributionlist_objectclass = 'zimbraDistributionList';
                       break;

            case 'iredmail':
                       $ldap_mail_attr = 'mail';
                       $ldap_account_objectclass = 'mailUser';
                       $ldap_distributionlist_attr = 'memberOfGroup';
                       $ldap_distributionlist_objectclass = 'mailList';
                       break;

            case 'lotus':
                       $ldap_mail_attr = 'mail';
                       $ldap_account_objectclass = 'dominoPerson';
                       $ldap_distributionlist_attr = 'mail';
                       $ldap_distributionlist_objectclass = 'dominoGroup';
                       break;

            
         }
      }

      $ldap = new LDAP($ldap_host, $ldap_helper_dn, $ldap_helper_password);

      if($ldap->is_bind_ok()) {

         $query = $ldap->query($ldap_base_dn, "(&(objectClass=$ldap_account_objectclass)($ldap_mail_attr=$username))", array());

         if(isset($query->row['dn']) && $query->row['dn']) {
            $a = $query->row;

            $ldap_auth = new LDAP($ldap_host, $a['dn'], $password);

            if(ENABLE_SYSLOG == 1) { syslog(LOG_INFO, "ldap auth against '" . $ldap_host . "', dn: '" . $a['dn'] . "', result: " . $ldap_auth->is_bind_ok()); }

            if($ldap_auth->is_bind_ok()) {

               $query = $ldap->query($ldap_base_dn, "(|(&(objectClass=$ldap_account_objectclass)($ldap_mail_attr=$username))(&(objectClass=$ldap_distributionlist_objectclass)($ldap_distributionlist_attr=$username)" . ")(&(objectClass=$ldap_distributionlist_objectclass)($ldap_distributionlist_attr=" . stripslashes($a['dn']) . ")))", array("mail", "mailalternateaddress", "proxyaddresses", $ldap_distributionlist_attr));

               $is_auditor = $this->check_ldap_membership($query->rows);

               $emails = $this->get_email_array_from_ldap_attr($query->rows);

               $this->add_session_vars($a['cn'], $username, $emails, $is_auditor);

               AUDIT(ACTION_LOGIN, $username, '', '', 'successful auth against LDAP');

               return 1;
            }
            else {
               AUDIT(ACTION_LOGIN_FAILED, $username, '', '', 'failed auth against LDAP');
            }
         }
      }
      else if(ENABLE_SYSLOG == 1) {
         syslog(LOG_INFO, "cannot bind to '" . $ldap_host . "' as '" . $ldap_helper_dn . "'");
      }

      return 0;
   }


   private function check_ldap_membership($e = array()) {
      if(LDAP_AUDITOR_MEMBER_DN == '') { return 0; }

      foreach($e as $a) {
         foreach (array("memberof") as $memberattr) {
            if(isset($a[$memberattr])) {

               if(isset($a[$memberattr]['count'])) {
                  for($i = 0; $i < $a[$memberattr]['count']; $i++) {
                     if($a[$memberattr][$i] == LDAP_AUDITOR_MEMBER_DN) {
                        return 1;
                     }
                  }
               }
               else {
                  if($a[$memberattr] == LDAP_AUDITOR_MEMBER_DN) {
                     return 1;
                  }
               }
            }
         }
      }

      return 0;
   }


   private function get_email_array_from_ldap_attr($e = array()) {
      $data = array();

      foreach($e as $a) {
         //foreach (array("mail", "mailalternateaddress", "proxyaddresses", LDAP_MAIL_ATTR, LDAP_DISTRIBUTIONLIST_ATTR) as $mailattr) {
            if(isset($a[$mailattr])) {

               if(isset($a[$mailattr]['count'])) {
                  for($i = 0; $i < $a[$mailattr]['count']; $i++) {
                     if(preg_match("/^smtp\:/i", $a[$mailattr][$i]) || strchr($a[$mailattr][$i], '@') ) {
                        $email = strtolower(preg_replace("/^smtp\:/i", "", $a[$mailattr][$i]));
                        if(!in_array($email, $data) && strchr($email, '@') && substr($email, 0, 4) != 'sip:') { array_push($data, $email); }
                     }
                  }
               }
               else {
                  $email = strtolower(preg_replace("/^smtp\:/i", "", $a[$mailattr]));
                  if(!in_array($email, $data) && strchr($email, '@') && substr($email, 0, 4) != 'sip:') { array_push($data, $email); }
               }
            }
         //}
      }

      return $data;
   }


   private function add_session_vars($name = '', $email = '', $emails = array(), $is_auditor = 0) {
      $a = explode("@", $email);

      $uid = $this->model_user_user->get_uid_by_email($email);
      if($uid < 1) {
         $uid = $this->model_user_user->get_next_uid(TABLE_EMAIL);
         $query = $this->db->query("INSERT INTO " . TABLE_EMAIL . " (uid, email) VALUES(?,?)", array($uid, $email));
      }

      $_SESSION['username'] = $name;
      $_SESSION['uid'] = $uid;

      if($is_auditor == 1) {
         $_SESSION['admin_user'] = 2;
      } else {
         $_SESSION['admin_user'] = 0;
      }

      $_SESSION['email'] = $email;
      $_SESSION['domain'] = $a[1];
      $_SESSION['realname'] = $name;

      $_SESSION['auditdomains'] = array();
      $_SESSION['emails'] = $emails;
      $_SESSION['folders'] = array();
      $_SESSION['extra_folders'] = array();
   }


   private function checkLoginAgainstFallbackLDAP($user = array(), $password = '') {
      if($password == '' || !isset($user['username']) || !isset($user['domain']) || !isset($user['dn']) || strlen($user['domain']) < 2){ return 0; }

      $query = $this->db->query("SELECT remotehost, basedn FROM " . TABLE_REMOTE . " WHERE remotedomain=?", array($user['domain']));

      if($query->num_rows != 1) { return 0; }

      $ldap = new LDAP($query->row['remotehost'], $user['dn'], $password);

      if($ldap->is_bind_ok()) {
         $this->change_password($user['username'], $password);

         AUDIT(ACTION_LOGIN, $user['username'], '', '', 'changed password in local table');

         return 1;
      }
      else {
         AUDIT(ACTION_LOGIN_FAILED, $user['username'], '', '', 'failed bind to ' . $query->row['remotehost'], $user['dn']);
      }

      return 0; 
   }


   private function checkLoginAgainstIMAP($username = '', $password = '') {
      $user = array();

      $imap = new Zend_Mail_Protocol_Imap(IMAP_HOST, IMAP_PORT, IMAP_SSL);
      if($imap->login($username, $password)) {
         $imap->logout();

         $this->add_session_vars($username, $username, array($username), 0);

         $_SESSION['password'] = $password;

         return 1;
      }

      return 0;
   }


   public function check_ntlm_auth() {
      $ldap_mail_attr = 'mail';
      $ldap_account_objectclass = 'user';
      $ldap_distributionlist_attr = 'member';
      $ldap_distributionlist_objectclass = 'group';

      if(!isset($_SERVER['REMOTE_USER'])) { return 0; }

      $u = explode("\\", $_SERVER['REMOTE_USER']);

      if(!isset($u[1])) { return 0; }

      $ldap = new LDAP(LDAP_HOST, LDAP_HELPER_DN, LDAP_HELPER_PASSWORD);

      if($ldap->is_bind_ok()) {

         $query = $ldap->query(LDAP_BASE_DN, "(&(objectClass=$ldap_account_objectclass)(samaccountname=" . $u[1] . "))", array());

         if(isset($query->row['dn'])) {
            $a = $query->row;

            if(isset($a['mail']['count'])) { $username = $a['mail'][0]; } else { $username = $a['mail']; }
            $username = strtolower(preg_replace("/^smtp\:/i", "", $username));

            $query = $ldap->query(LDAP_BASE_DN, "(|(&(objectClass=$ldap_account_objectclass)($ldap_mail_attr=$username))(&(objectClass=$ldap_distributionlist_objectclass)($ldap_distributionlist_attr=$username)" . ")(&(objectClass=$ldap_distributionlist_objectclass)($ldap_distributionlist_attr=" . $a['dn'] . ")))", array());

            $emails = $this->get_email_array_from_ldap_attr($query->rows);

            $this->add_session_vars($a['cn'], $username, $emails, 0);

            AUDIT(ACTION_LOGIN, $username, '', '', 'successful auth against LDAP');

            return 1;
         }

      }

      return 0; 
   }


   public function change_password($username = '', $password = '') {
      if($username == "" || $password == ""){ return 0; }

      $query = $this->db->query("UPDATE " . TABLE_USER . " SET password=? WHERE username=?", array(crypt($password), $username));

      $rc = $this->db->countAffected();

      return $rc;
   }

}

?>
