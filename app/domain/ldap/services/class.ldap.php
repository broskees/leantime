<?php

namespace leantime\domain\services;

use leantime\core\environment;
use leantime\domain\repositories;

class ldap
{
    private $ldapConnection;
    private $host;
    private $port;
    private $baseDn; //Base DN for domain
    private $ldapDn; //DN where users are located (including baseDn)
    public $userDomain;
    private $ldapKeys = array(
        "username" => "uid",
        "groups" => "memberof",
        "email" => "mail",
        "firstname" => "displayname",
        "lastname" => '',
        "phonenumber" => 'telephonenumber'
    );
    private $ldapLtGroupAssignments = array();
    private $settingsRepo;
    private $defaultRoleKey;
    private $directoryType = "OL";

    /**
     * @var environment $config
     */
    private environment $config;

    /**
     * @var array|bool|int|mixed|string
     */
    public $useLdap;

    /**
     * @var array|bool|int|mixed|string
     */
    public $autoCreateUser;

    public function __construct($differentConfig = false)
    {

        $this->settingsRepo = new repositories\setting();

        if (!$differentConfig) {
            $this->config = \leantime\core\environment::getInstance();
            //Map config vars
            $this->useLdap = $this->config->useLdap;

            //Don't do anything else if ldap is turned off
            if ($this->useLdap === false) {
                return false;
            }

            //Prepare and map in case we want to get the config from somewhere else in the future
            $this->host = $this->config->ldapHost;
            $this->baseDn = $this->config->baseDn;
            $this->ldapDn = $this->config->ldapDn;
            $this->defaultRoleKey = $this->config->ldapDefaultRoleKey;
            $this->port = $this->config->ldapPort;
            $this->userDomain = $this->config->ldapUserDomain;
            $this->ldapLtGroupAssignments = json_decode(trim(preg_replace('/\s+/', '', $this->config->ldapLtGroupAssignments)));
            $this->ldapKeys = $this->settingsRepo->getSetting('companysettings.ldap.ldapKeys') ? json_decode($this->settingsRepo->getSetting('companysettings.ldap.ldapKeys')) : json_decode(trim(preg_replace('/\s+/', '', $this->config->ldapKeys)));
            $this->directoryType = $this->config->ldapType;

            if (!is_object($this->ldapLtGroupAssignments)) {
                error_log("LDAP: Group Assignment array failed to parse. Please check for valid json");
                return false;
            }

            if (!is_object($this->ldapKeys)) {
                error_log("LDAP: Ldap Keys failed to parse. Please check for valid json");
                return false;
            }
        }

        return true;
    }

    public function connect()
    {

        if (!$this->config->useLdap) {
            return false;
        }

        if (function_exists("ldap_connect")) {
            $this->ldapConnection = ldap_connect($this->host, $this->port);

            ldap_set_option($this->ldapConnection, LDAP_OPT_PROTOCOL_VERSION, 3) or die('Unable to set LDAP protocol version');
            ldap_set_option($this->ldapConnection, LDAP_OPT_REFERRALS, 0); // We need this for doing an LDAP search.

            if ($this->config->debug) {
                ldap_set_option($this->ldapConnection, LDAP_OPT_DEBUG_LEVEL, 7);
            }

            return true;
        } else {
            error_log("ldap extension not installed", 0);
            return false;
        }
    }

    public function bind($username = '', $password = '')
    {

        if ($username != '' && $password != '') {
            if ($this->directoryType == 'AD') {
                $usernameDN = $username;
            } else {
                $usernameDN = $this->ldapKeys->username . "=" . $username . "," . $this->ldapDn;
            }
            $passwordBind = $password;

            $bind = ldap_bind($this->ldapConnection, $usernameDN, $passwordBind);

            if ($bind) {
                return true;
            } else {
                if ($this->config->debug == 1) {
                    error_log(ldap_error($this->ldapConnection));
                    ldap_get_option($this->ldapConnection, LDAP_OPT_DIAGNOSTIC_MESSAGE, $err);
                    if ($err) {
                        error_log($err);
                    }
                }

                return false;
            }
        } else {
            return false;
        }
    }

    public function getEmail($username)
    {
        if (!$this->ldapConnection) {
            error_log("No connection, last error: " . ldap_error($this->ldapConnection));
        }
        $filter = "(" . $this->ldapKeys->username . "=" . $this->extractLdapFromUsername($username) . ")";

        $attr = array($this->ldapKeys->groups, $this->ldapKeys->firstname, $this->ldapKeys->lastname, $this->ldapKeys->email);

        $result = ldap_search($this->ldapConnection, $this->ldapDn, $filter, $attr) or exit("Unable to search LDAP server");
        $entries = ldap_get_entries($this->ldapConnection, $result);

        if ($entries === false) {
            error_log(ldap_error($this->ldapConnection));
            ldap_get_option($this->ldapConnection, LDAP_OPT_DIAGNOSTIC_MESSAGE, $err);
            error_log($err);
        }
        $mail = isset($entries[0][$this->ldapKeys->email]) ? $entries[0][$this->ldapKeys->email][0] : '';
        return $mail;
    }

    public function getSingleUser($username)
    {

        if (!$this->ldapConnection) {
            error_log("No connection, last error: " . ldap_error($this->ldapConnection));
        }

        $filter = "(" . $this->ldapKeys->username . "=" . $this->extractLdapFromUsername($username) . ")";

        $attr = array($this->ldapKeys->groups, $this->ldapKeys->firstname, $this->ldapKeys->lastname, $this->ldapKeys->email, $this->ldapKeys->phonenumber);

        $result = ldap_search($this->ldapConnection, $this->ldapDn, $filter, $attr) or exit("Unable to search LDAP server");
        $entries = ldap_get_entries($this->ldapConnection, $result);

        if ($entries === false || !isset($entries[0])) {
            error_log(ldap_error($this->ldapConnection));
            ldap_get_option($this->ldapConnection, LDAP_OPT_DIAGNOSTIC_MESSAGE, $err);
            error_log($err);
            return false;
        }

        //Find Role
        $role = $this->defaultRoleKey;

        foreach ($entries[0][$this->ldapKeys->groups] as $grps) {
            foreach ($this->ldapLtGroupAssignments as $key => $row) {
                if ($row->ldapRole != "") {
                    if (strpos($grps, $row->ldapRole)) {
                        if ($key > $role) {
                            $role = $key;
                        }
                    }
                }
            }
        }

        //Find Firstname & Lastname
        $firstname = isset($entries[0][$this->ldapKeys->firstname]) ? $entries[0][$this->ldapKeys->firstname][0] : '';
        $lastname = isset($entries[0][$this->ldapKeys->lastname]) ? $entries[0][$this->ldapKeys->lastname][0] : '';
        $phonenumber = isset($entries[0][$this->ldapKeys->phonenumber]) ? $entries[0][$this->ldapKeys->phonenumber][0] : '';
        $uname = isset($entries[0][$this->ldapKeys->email]) ? $entries[0][$this->ldapKeys->email][0] : '';

        if ($this->config->debug) {
            error_log("LEANTIME: Testing the logging\n");

            //$uname = $this->extractLdapFromUsername($username)."".$this->userDomain;
            //$uname = str_replace("@","AT", $uname);
            error_log("LEANTIME: >>>Attributes Begin>>>>>>\n");
            error_log("LEANTIME: fn $firstname", 0);
            error_log("LEANTIME: sn $lastname", 0);
            error_log("LEANTIME: phone $phonenumber", 0);
            error_log("LEANTIME: role $role", 0);
            error_log("LEANTIME: username $uname ", 0);
            error_log("LEANTIME: >>>Attributes End>>>>>>\n", 0);
        }

        return array(
            "user" => $uname,
            "firstname" => $firstname,
            "lastname" => $lastname,
            "role" => $role,
            "phonenumber" => $phonenumber
        );
    }

    public function extractLdapFromUsername($username)
    {

        $getLdap = explode("@", $username);

        if ($getLdap && is_array($getLdap)) {
            return $getLdap[0];
        } else {
            return "";
        }
    }

    public function getAllMembers()
    {

        if (function_exists("ldap_search")) {
            $attr = array($this->ldapKeys->groups, $this->ldapKeys->firstname, $this->ldapKeys->lastname);

            $filter = "(cn=*)";

            $result = ldap_search($this->ldapConnection, $this->ldapDn, $filter, $attr) or exit("Unable to search LDAP server");
            $entries = ldap_get_entries($this->ldapConnection, $result);

            $allUsers = array();

            foreach ($entries as $key => $row) {
                if (isset($row["dn"])) {
                    preg_match('/(?:^|.*,)uid=(.*?)(?:,.*$|$)/', $row["dn"], $usernameArray);

                    if (count($usernameArray) > 0) {
                        $allUsers[] = $this->getSingleUser($usernameArray[1]);
                    }
                }
            }

            return $allUsers;
        } else {
            error_log("ldap extension not installed", 0);
            return false;
        }
    }

    public function upsertUsers($ldapUsers)
    {

        $userRepo = new repositories\users();

        foreach ($ldapUsers as $user) {
            //Update
            $checkUser = $userRepo->getUserByEmail($user['user']);

            if (is_array($checkUser)) {
                $userRepo->patchUser($checkUser['id'], array("firstname" => $user["firstname"], "lastname" => $user["lastname"], "role" => $user["role"]));
            } else {
                //Insert
                $userArray = array(
                    'firstname' => $user['firstname'],
                    'lastname' => $user['lastname'],
                    'phone' => '',
                    'user' => $user['user'],
                    'role' => $user['role'],
                    'password' => '',
                    'clientId' => '',
                    'source' => 'ldap'
                );

                $userRepo->addUser($userArray);
            }
        }

        return true;
    }
}
