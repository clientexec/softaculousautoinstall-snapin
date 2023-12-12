<?php

require_once 'modules/admin/models/SnapinPlugin.php';
require_once 'Soft_Install.php';

class PluginSoftaculousautoinstall extends SnapinPlugin
{
    public $listeners = array(
        array("Plugin-Action-Create", "activateCallback")
    );

    public function getVariables()
    {
        $variables = [
            lang('Plugin Name') => [
                'type' => 'hidden',
                'description' => '',
                'value' => lang('Softaculous Auto Installer'),
            ],
            lang('Script Custom Field Name')  => [
                'type' => 'text',
                'description' => lang('Enter the custom field name for the script to auto-install'),
                'value' => '',
            ],
            lang('Username Custom Field Name')  => [
                'type' => 'text',
                'description' => lang('Enter the custom field name for the admin username'),
                'value' => '',
            ],
            lang('Password Custom Field Name')  => [
                'type' => 'text',
                'description' => lang('Enter the custom field name for the admin password'),
                'value' => '',
            ],
            lang('Auto Upgrade?') => [
                'type' => 'yesno',
                'description' => lang('Enable Auto Upgrade (Auto upgrade will be enabled only if the script supports auto upgrade)'),
                'value' => ''
            ],
            lang('Auto Backup?') => [
                'type' => 'options',
                'description' => lang('Enable Auto Backups?'),
                'options' => ['No' => 'No', 'Daily' => 'Daily', 'Weekly' => 'Weekly', 'Monthly' => 'Monthly'],
                'value' => 'No'
            ]
        ];
            return $variables;
    }

    public function activateCallback($e)
    {
        $userPackageGateway = new UserPackageGateway($this->user);
        $event = $e->getParams();
        $userPackage = $event['userPackage'];

        $script = $userPackage->getCustomField(
            $this->settings->get('plugin_softaculousautoinstall_Script Custom Field Name'),
            CUSTOM_FIELDS_FOR_PACKAGE
        );

        if ($script != '' && strtolower($script) != 'none') {
            $userPackageGateway->hasPlugin($userPackage, $pluginName);
            if ($pluginName == 'cpanel') {
                // There's a bug with cPanel or Softaculous where we need to wait for 20-25 seconds before we attempt to auto-install Clientexec
                if (strtolower($script) === 'clientexec') {
                    sleep(25);
                }
                $this->installcPanel($userPackage, $script);
            } elseif ($pluginName == 'directadmin') {
                $this->installDirectAdmin($userPackage, $script);
            }
        }
    }

    private function installDirectAdmin($userPackage, $script)
    {
        $user = new User($userPackage->CustomerId);
        $serverId = $userPackage->getCustomField('Server Id');
        $server = new Server($serverId);
        $vars = $server->getAllServerPluginVariables($this->user, $server->getPluginName());

        $userPackageUsername = strtolower($userPackage->getCustomField('User Name'));
        $userPackagePassword = $this->getPassword($userPackage);

        $install = new Soft_Install();

        $scriptId = '';
        foreach ($install->scripts() as $key => $value) {
            if (trim(strtolower($value['name'])) == trim(strtolower($script))) {
                $scriptId = $key;
                break;
            }
        }
        if ($scriptId == '') {
            throw new CE_Exception('Can not determine script to install.');
        }

        $install->login = 'https://' . $vars['ServerHostName'] . ':' . $vars['plugin_directadmin_Port'] . '/CMD_PLUGINS/softaculous/index.raw';

        $install->data['softproto'] = 4;
        $install->data['admin_username'] = $this->getAdminUsername($userPackage);
        $install->data['admin_pass'] = $this->getAdminPassword($userPackage);
        $install->data['admin_email'] = $user->getEmail();

        if ($this->settings->get('plugin_softaculousautoinstall_Auto Upgrade?') == 1) {
            $install->data['eu_auto_upgrade'] = 1;
        }

        if ($this->settings->get('plugin_softaculousautoinstall_Auto Backup?') != 'No') {
            $install->data['auto_backup'] = strtolower($this->settings->get('plugin_softaculousautoinstall_Auto Backup?'));
        }

        // Login and get the cookies
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://' . $vars['ServerHostName'] . ':' . $vars['plugin_directadmin_Port'] . '/CMD_LOGIN');
        curl_setopt($ch, CURLOPT_VERBOSE, 1);
        $caPathOrFile = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
        if (is_dir($caPathOrFile)) {
            curl_setopt($ch, CURLOPT_CAPATH, $caPathOrFile);
        } else {
            curl_setopt($ch, CURLOPT_CAINFO, $caPathOrFile);
        }

        $post = [
            'username' => $userPackageUsername,
            'password' => $userPackagePassword,
            'referer' => '/'
        ];

        curl_setopt($ch, CURLOPT_POST, 1);
        $nvpreq = http_build_query($post);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $nvpreq);

        // Check the Header
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // Get response from the server.
        $resp = curl_exec($ch);

        // Did we login ?
        if ($resp === false) {
            throw new CE_Exception('Could not login to the remote server. cURL Error : ' . curl_error($ch));
            return false;
        }

        curl_close($ch);

        $resp = explode("\n", $resp);

        // Find the cookies
        foreach ($resp as $k => $v) {
            if (preg_match('/^' . preg_quote('set-cookie:', '/') . '(.*?)$/is', $v, $mat)) {
                $install->cookie = trim($mat[1]);
            }
        }

        // Add a Question mark if necessary
        if (substr_count($install->login, '?') < 1) {
            $install->login = $install->login . '?';
        }

        // Login PAGE
        if ($scripts[$scriptId]['type'] == 'js') {
            $install->login = $install->login . 'act=js&soft=' . $scriptId;
        } elseif ($scripts[$scriptId]['type'] == 'perl') {
            $install->login = $install->login . 'act=perl&soft=' . $scriptId;
        } else {
            $install->login = $install->login . 'act=software&soft=' . $scriptId;
        }
        $install->login = $install->login . '&autoinstall=' . rawurlencode(base64_encode(serialize($install->data)));

        $resp = '';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $install->login);
        curl_setopt($ch, CURLOPT_VERBOSE, 1);
        $caPathOrFile = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
        if (is_dir($caPathOrFile)) {
            curl_setopt($ch, CURLOPT_CAPATH, $caPathOrFile);
        } else {
            curl_setopt($ch, CURLOPT_CAINFO, $caPathOrFile);
        }

        if (!empty($install->cookie)) {
            curl_setopt($ch, CURLOPT_COOKIESESSION, true);
            curl_setopt($ch, CURLOPT_COOKIE, $install->cookie);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_REFERER, 'https://' . $vars['ServerHostName'] . ':' . $vars['plugin_directadmin_Port'] . '/');
        $resp = curl_exec($ch);

        // Did we login ?
        if ($resp === false) {
            throw new CE_Exception('Could not login to the remote server. cURL Error : ' . curl_error($ch));
            return false;
        }

        curl_close($ch);

        if ($resp == 'installed') {
            $userPackage->setCustomField(
                $this->settings->get('plugin_softaculousautoinstall_Username Custom Field Name'),
                $install->data['admin_username'],
                CUSTOM_FIELDS_FOR_PACKAGE
            );
             $userPackage->setCustomField(
                 $this->settings->get('plugin_softaculousautoinstall_Password Custom Field Name'),
                 $install->data['admin_pass'],
                 CUSTOM_FIELDS_FOR_PACKAGE
             );
            CE_Lib::log(4, 'Script Installed successfully');
        } else {
            throw new CE_Exception('The following errors occured : ' . $resp);
        }
    }

    private function installcPanel($userPackage, $script)
    {
        $user = new User($userPackage->CustomerId);
        $serverId = $userPackage->getCustomField('Server Id');
        $server = new Server($serverId);
        $vars = $server->getAllServerPluginVariables($this->user, $server->getPluginName());

        $install = new Soft_Install();

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://' . $vars['ServerHostName'] . ':2083/login/');
        curl_setopt($ch, CURLOPT_VERBOSE, 1);
        $caPathOrFile = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
        if (is_dir($caPathOrFile)) {
            curl_setopt($ch, CURLOPT_CAPATH, $caPathOrFile);
        } else {
            curl_setopt($ch, CURLOPT_CAINFO, $caPathOrFile);
        }

        $userPackageUsername = strtolower($userPackage->getCustomField('User Name'));
        $userPackagePassword = $this->getPassword($userPackage);
        $post = [
            'user' => $userPackageUsername,
            'pass' => $userPackagePassword,
            'goto_uri' => '/'
        ];

        curl_setopt($ch, CURLOPT_POST, 1);
        $nvpreq = http_build_query($post);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $nvpreq);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $resp = curl_exec($ch);

        if ($resp === false) {
            throw new CE_Exception('Could not login to the remote server. cURL Error : ' . curl_error($ch));
        }

        $curl_info = curl_getinfo($ch);
        if (!empty($curl_info['redirect_url'])) {
            $parsed = parse_url($curl_info['redirect_url']);
        } else {
            $parsed = parse_url($curl_info['url']);
        }
        $path = trim(dirname($parsed['path']));
        $path = ($path[0] == '/' ? $path : '/' . $path);

        if (empty($path)) {
            throw new CE_Exception('Could not determine the location of the Softaculous on the remote server. There could be a firewall preventing access.');
        }

        $scriptId = '';
        foreach ($install->scripts() as $key => $value) {
            if (trim(strtolower($value['name'])) == trim(strtolower($script))) {
                $scriptId = $key;
                break;
            }
        }
        if ($scriptId == '') {
            throw new CE_Exception('Can not determine script to install.');
        }

        $install->login = 'https://' . rawurlencode($userPackageUsername) . ':' . rawurlencode($userPackagePassword) . '@' . $vars['ServerHostName'] . ':2083' . $path . '/softaculous/index.live.php';
        $install->data['softproto'] = 4;
        $install->data['admin_username'] = $this->getAdminUsername($userPackage);
        $install->data['admin_pass'] = $this->getAdminPassword($userPackage);
        $install->data['admin_email'] = $user->getEmail();

        if ($this->settings->get('plugin_softaculousautoinstall_Auto Upgrade?') == 1) {
            $install->data['eu_auto_upgrade'] = 1;
        }

        if ($this->settings->get('plugin_softaculousautoinstall_Auto Backup?') != 'No') {
            $install->data['auto_backup'] = strtolower($this->settings->get('plugin_softaculousautoinstall_Auto Backup?'));
        }

        $res = trim($install->install($scriptId));
        if (preg_match('/installed/is', $res)) {
            $userPackage->setCustomField(
                $this->settings->get('plugin_softaculousautoinstall_Username Custom Field Name'),
                $install->data['admin_username'],
                CUSTOM_FIELDS_FOR_PACKAGE
            );
             $userPackage->setCustomField(
                 $this->settings->get('plugin_softaculousautoinstall_Password Custom Field Name'),
                 $install->data['admin_pass'],
                 CUSTOM_FIELDS_FOR_PACKAGE
             );
            CE_Lib::log(4, 'Script Installed successfully');
        } else {
            throw new CE_Exception('The following errors occured : ' . $res);
        }
    }

    private function getPassword($userPackage)
    {
        if ($this->settings->get('Domain Passwords are Encrypted') == 1) {
            return htmlspecialchars_decode(Clientexec::decryptString($userPackage->getCustomField('Password')), ENT_QUOTES);
        }
        return htmlspecialchars_decode($userPackage->getCustomField('Password'), ENT_QUOTES);
    }

    private function getAdminUsername($userPackage)
    {
        $username = $userPackage->getCustomField(
            $this->settings->get('plugin_softaculousautoinstall_Username Custom Field Name'),
            CUSTOM_FIELDS_FOR_PACKAGE
        );

        if ($username == '') {
            $username = 'admin';
        }

        return $username;
    }

    private function getAdminPassword($userPackage)
    {
        $password = $userPackage->getCustomField(
            $this->settings->get('plugin_softaculousautoinstall_Password Custom Field Name'),
            CUSTOM_FIELDS_FOR_PACKAGE
        );

        if ($password == '') {
            $password = $this->__srandstr(12);
        }

        return $password;
    }

    private function __srandstr($length, $special = 0)
    {
        $randstr = "";
        $randstr .= strtoupper(chr(97 + mt_rand(0, 25)));
        $randstr .= mt_rand(0, 9);

        if (!empty($special)) {
            // Special Character
            $sp_chars = '!@#$%&*?';
            $randstr .= $sp_chars[rand(0, strlen($sp_chars) - 1)];
        }

        $newlength = ($length - strlen($randstr));

        for ($i = 0; $i < $newlength; $i++) {
            $randnum = mt_rand(0, 61);
            if ($randnum < 10) {
                $randstr .= chr($randnum + 48);
            } elseif ($randnum < 36) {
                $randstr .= chr($randnum + 55);
            } else {
                $randstr .= chr($randnum + 61);
            }
        }
        return str_shuffle($randstr);
    }
}
