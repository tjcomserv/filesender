<?php

/*
 * FileSender www.filesender.org
 * 
 * Copyright (c) 2009-2014, AARNet, Belnet, HEAnet, SURFnet, UNINETT
 * All rights reserved.
 * 
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 * 
 * *	Redistributions of source code must retain the above copyright
 * 	notice, this list of conditions and the following disclaimer.
 * *	Redistributions in binary form must reproduce the above copyright
 * 	notice, this list of conditions and the following disclaimer in the
 * 	documentation and/or other materials provided with the distribution.
 * *	Neither the name of AARNet, Belnet, HEAnet, SURFnet and UNINETT nor the
 * 	names of its contributors may be used to endorse or promote products
 * 	derived from this software without specific prior written permission.
 * 
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

// --------------------------------
// SAML authentication.
// --------------------------------
class AuthSaml
{
    private static $instance = null;

    public static function getInstance()
    {
        // Returns existing instance, initiates new one otherwise
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    // Checks if a user is SAML authenticated and is administrator: returns true/false.
    // Admins can be added in the configuration file using the configured $config['saml_uid_attribute'].
    public function authIsAdmin()
    {
        global $config;
        require_once($config['site_simplesamllocation'] . 'lib/_autoload.php');

        $as = new SimpleSAML_Auth_Simple($config['site_authenticationSource']);
        if ($as->isAuthenticated()) {
            $as->requireAuth();
            $attributes = $as->getAttributes();

            // Compare config admin to userUID.
            if (isset($attributes[$config['saml_uid_attribute']][0])) {
                $attributes['saml_uid_attribute'] = $attributes[$config['saml_uid_attribute']][0];
            } elseif (isset($attributes[$config['saml_uid_attribute']])) {
                $attributes['saml_uid_attribute'] = $attributes[$config['saml_uid_attribute']];
            } else {
                // Required attribute does not exist.
                logEntry('UID attribute not found in IDP (' . $config['saml_uid_attribute'] . ')', 'E_ERROR');
                return false;
            }

            $knownAdmins = array_map('trim', explode(',', $config['admin']));
            return in_array($attributes['saml_uid_attribute'], $knownAdmins);
        }

        return false;
    }

    // Returns SAML authenticated user information as json array.
    public function sAuth()
    {
        global $config;

        require_once($config['site_simplesamllocation'] . 'lib/_autoload.php');

        $as = new SimpleSAML_Auth_Simple($config['site_authenticationSource']);
        $as->requireAuth();
        $attributes = $as->getAttributes();
        $missingAttributes = false;

        // need to capture email from SAML attribute. may be single attribute or array 
        // ensure that it's always an array.
        if (isset($attributes[$config['saml_email_attribute']])) {
            if (is_array($attributes[$config['saml_email_attribute']])) {
                $attributes['email'] = $attributes[$config['saml_email_attribute']];
            } else {
                $attributes['email'] = array($attributes[$config['saml_email_attribute']]);
            }
        }

        // Check for empty or invalid email attribute
        if (empty($attributes["email"])) {
            logEntry(
                "No valid email attribute found in IDP (looking for '" . $config['saml_email_attribute'] . "')",
                "E_ERROR"
            );
            $missingAttributes = true;
        }

        foreach ($attributes["email"] as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                logEntry("Invalid email attribute received from IdP: '" . $email . "'", "E_ERROR");
                $missingAttributes = true;
            }
        }

        if (isset($attributes[$config['saml_name_attribute']][0])) {
            $attributes['cn'] = $attributes[$config['saml_name_attribute']][0];
        }

        if (!isset($attributes[$config['saml_name_attribute']]) && isset($attributes['email'])) {
            $attributes['cn'] = substr($attributes['email'], 0, strpos($attributes['email'], '@'));
        }

        if (isset($attributes[$config['saml_uid_attribute']][0])) {
            $attributes['saml_uid_attribute'] = $attributes[$config['saml_uid_attribute']][0];
        } elseif (isset($attributes[$config['saml_uid_attribute']])) {
            $attributes['saml_uid_attribute'] = $attributes[$config['saml_uid_attribute']];
        } else {
            // Required UID attribute missing.
            logEntry("UID attribute not found in IDP (looking for '" . $config['saml_uid_attribute'] . "')", "E_ERROR");
            $missingAttributes = true;
        }

        // Logs access by a user and users logged on array data.
        // This could be moved to logging function in future versions.
        $inGlue = '=';
        $outGlue = '&';
        $separator = '|';
        $message = '';

        foreach ($attributes as $tk => $tv) {
            $message .= (isset($return) ? $return . $outGlue : '') . $tk . $inGlue . 
                (is_array($tv) ? implode($separator, $tv) : $tv) . $outGlue;
        }

        $ip = $_SERVER['REMOTE_ADDR']; // Capture IP.

        if ($config['dnslookup'] == true) {
            $domain = gethostbyaddr($ip);
        } else {
            $domain = '';
        }

        $message .= '[' . $ip . '(' . $domain . ')] ' . $_SERVER['HTTP_USER_AGENT'];

        $attributes['SessionID'] = session_id();

        if ($missingAttributes) {
            logEntry($message, 'E_ERROR');
            return 'err_attributes';
        } else {
            logEntry($message, 'E_NOTICE');
            return $attributes;
        }
    }

    // Requests logon URL from SAML and returns string.
    public function logonURL()
    {
        global $config;

        $logonUrl = $config['site_simplesamlurl'] . 'module.php/core/as_login.php?AuthId=' . 
            $config['site_authenticationSource'] . '&ReturnTo=' . $config['site_url'] . 'index.php?s=upload';
        return htmlentities($logonUrl);
    }

    // Requests log OFF URL from SAML and returns string.
    public function logoffURL()
    {
        global $config;
        require_once($config['site_simplesamllocation'] . 'lib/_autoload.php');

        $logoffUrl = $config['site_simplesamlurl'] . 'module.php/core/as_logout.php?AuthId=' . 
            $config['site_authenticationSource'] . '&ReturnTo=' . $config['site_logouturl'] . '';
        return htmlentities($logoffUrl);
    }

    // Checks SAML for authenticated user: returns true/false.
    public function isAuth()
    {
        global $config;
        require_once($config['site_simplesamllocation'] . 'lib/_autoload.php');

        $as = new SimpleSAML_Auth_Simple($config['site_authenticationSource']);
        return $as->isAuthenticated();
    }
}

