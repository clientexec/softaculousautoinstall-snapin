<?php
class Soft_Install
{

    // The Login URL
    var $login = '';

    var $debug = 0;

    var $cookie;

    // THE POST DATA
    var $data = array();

    function install($sid)
    {
        $scripts = $this->scripts();

        if (empty($scripts[$sid])) {
            return 'List of scripts not loaded. Aborting Installation attempt!';
        }

        // Add a Question mark if necessary
        if (substr_count($this->login, '?') < 1) {
            $this->login = $this->login.'?';
        }

        // Login PAGE
        if ($scripts[$sid]['type'] == 'js') {
            $this->login = $this->login.'act=js&soft='.$sid;
        } elseif ($scripts[$sid]['type'] == 'perl') {
            $this->login = $this->login.'act=perl&soft='.$sid;
        } elseif ($scripts[$sid]['type'] == 'java') {
            $this->login = $this->login.'act=java&soft='.$sid;
        } else {
            $this->login = $this->login.'act=software&soft='.$sid;
        }

        // Give an Overwrite signal for existing files and folders
        if (!isset($this->data['overwrite_existing'])) {
            $this->data['overwrite_existing'] = 1;
        }

        $this->login = $this->login.'&autoinstall='.rawurlencode(base64_encode(serialize($this->data)));

        if (!empty($this->debug)) {
            return $this->data;
        }

        // Set the curl parameters.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->login);
        $caPathOrFile = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
        if (is_dir($caPathOrFile)) {
            curl_setopt($ch, CURLOPT_CAPATH, $caPathOrFile);
        } else {
            curl_setopt($ch, CURLOPT_CAINFO, $caPathOrFile);
        }
        curl_setopt($ch, CURLOPT_HEADER, false);

        // Is there a Cookie
        if (!empty($this->cookie)) {
            curl_setopt($ch, CURLOPT_COOKIESESSION, true);
            curl_setopt($ch, CURLOPT_COOKIE, $this->cookie);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Get response from the server.
        $resp = curl_exec($ch);

        // Did we reach out to that place ?
        if ($resp === false) {
            throw new CE_Exception('Installation not completed. cURL Error : ' . curl_error($ch));
        }

        curl_close($ch);

        // Was there any error ?
        if ($resp != 'installed') {
            return $resp;
        }

        return 'installed';
    }

    function scripts()
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.softaculous.com/scripts.php?in=serialize');
        $caPathOrFile = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
        if (is_dir($caPathOrFile)) {
            curl_setopt($ch, CURLOPT_CAPATH, $caPathOrFile);
        } else {
            curl_setopt($ch, CURLOPT_CAINFO, $caPathOrFile);
        }
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Get response from the server
        $resp = curl_exec($ch);
        $scripts = unserialize($resp);

        if (file_exists('plugins/snapin/softaculousautoinstall/extra_scripts.php')) {
            require 'plugins/snapin/softaculousautoinstall/extra_scripts.php';
            if (is_array($extraScripts)) {
                foreach ($extraScripts as $k => $v) {
                    $scripts[$k] = $v;
                }
            }
        }
        if (!is_array($scripts)) {
            throw new CE_Exception('Could not download list of scripts: ' . curl_error($ch));
        }
        return $scripts;
    }
}
