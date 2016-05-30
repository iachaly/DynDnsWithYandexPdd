<?php 

    function rotateLog($logFile, $maxFileSize) {
        if (file_exists($logFile) && filesize($logFile) > $maxFileSize) {
            if (file_exists($logFile.'.old')) unlink($logFile.'.old');
            rename($logFile, $logFile.'.old');
        }
    }

    function writeLog($message, $output=true) {
        if ($output) echo trim($message).PHP_EOL;
        $logFile = dirname(__FILE__).'/change-ip.log';
        rotateLog($logFile, 1024*1024);
        $line = date('d.m.y H:i:s')." ] ".trim($message);
        file_put_contents($logFile, trim($line).PHP_EOL, FILE_APPEND);
    }

    function wwwGet($url) {
        return file_get_contents($url);
    }

    function wwwPost($url, $postData) {
        $ch = curl_init();

        curl_setopt($ch,CURLOPT_URL,$url);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
        curl_setopt($ch,CURLOPT_HEADER, false); 
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
 
        $output = curl_exec($ch);

        curl_close($ch);

        return $output; 
    }

    function getCachedUrl($url, $expireAfter=3600) {
        $cacheFile = dirname(__FILE__).'/cache/'.md5($url).'.cache';
        if (file_exists($cacheFile) && ((filemtime($cacheFile)+ $expireAfter)>time())) {
            return file_get_contents($cacheFile);
        } elseif ($data = wwwGet($url)) {
            file_put_contents($cacheFile, $data);
            return $data;
        }
        return false;
    }

    function API1_getDomainRecords($token, $domain) {
        $ret = [];
        $url = 'https://pddimp.yandex.ru/nsapi/get_domain_records.xml?token='.$token.'&domain='.$domain;
        $data = wwwGet($url);

        $xml = simplexml_load_string($data);
        if ($xml) {
            if (!empty($xml->domains->domain->response->record)) {
                foreach ($xml->domains->domain->response->record as $record) {
                    $recInfo = reset($record->attributes());
                    if (empty($recInfo['type'])) continue;
                    $ret[$recInfo['type']][] = [
                        'ip' => (string) $record,
                        'info' => $recInfo,
                    ];
                }
            }
        }
        return $ret;
    }

    function API2_getDomainRecords($token, $domain) {
        $ret = [];
        $url = 'https://pddimp.yandex.ru/api2/admin/dns/list?token='.$token.'&domain='.$domain;
        if ($data = wwwGet($url)) {
            $answer = json_decode($data, true);
            if (!empty($answer['records'])) {
                foreach ($answer['records'] as $record) {
                    if (empty($record['type'])) continue;
                    $ret[$record['type']][] = [
                        'ip' => $record['content'],
                        'info' => $record,
                    ];
                }
            }
        }
        return $ret;
    }

    function getWanIp(){
        // http://stackoverflow.com/questions/7909362/how-do-i-get-the-external-ip-of-my-server-using-php
        $ret = false;
        $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($res = socket_connect($sock, '8.8.8.8', 53)) {
            socket_getsockname($sock, $addr);
            socket_shutdown($sock);
            socket_close($sock);
            $ret = $addr;
        }
        return $ret;
    }
    
    function API2_changePddDnsRecord($token, $domain, $recordId, $ip) {
        $ret = false;

        $url = 'https://pddimp.yandex.ru/api2/admin/dns/edit';

        $postData = [
            'domain' => $domain,
            'record_id' => $recordId,
            'content' => $ip,
            'token' => $token,
        ];

        $data = wwwPost($url, $postData);

        if ($data) {
            $answer = json_decode($data, true);

            $ret = $answer['success'] == 'ok';

            if ($ret) {
                writeLog('IP for domain '.$answer['record']['fqdn'].' changed to '.$answer['record']['content']);
            } else {
                writeLog('Error: IP for recordId #'.$recordId.' (base domain: '.$domain.') NOT changed');
            }
        }

        return $ret;
    }

    function API2_changeIpAddressOf($token, $domain, $wanIp, $excludeDomains=[]) {

        $records = API2_getDomainRecords($token, $domain);

        if (empty($records['A'])) {
            writeLog('Error: Can\'t resolve A records for '.$domain);
            return;
        } else {
            foreach($records['A'] as $recA) {
                if (in_array($recA['info']['fqdn'], $excludeDomains)) {
                    writeLog('Skip domain '.$recA['info']['domain'].' according to configuration..');
                    continue;
                }
                if ($recA['ip'] != $wanIp) {
                    writeLog('For domain '.$recA['info']['fqdn'].' IP changed from '.$recA['ip'].' [DNS] to '.$wanIp.' [WAN], changing DNS records..');
                    if (!API2_changePddDnsRecord(
                        $token, 
                        $domain, 
                        $recA['info']['record_id'], 
                        $wanIp
                    )) {
                        writeLog('Error: IP for domain: '.$recA['info']['fqdn'].' (base domain: '.$domain.') NOT changed');
                    }
                } else {
                    writeLog('For domain '.$recA['info']['fqdn'].' IPs the same: '.$recA['ip'].' [DNS] and '.$wanIp.' [WAN], no changes needed');
                }
            }
        }

        return true;
    }

    function getLastIp(){
        $ret = '';
        $lastIpLogFile = dirname(__FILE__).'/last_ip.log';
        if (file_exists($lastIpLogFile)) $ret = trim(file_get_contents($lastIpLogFile));
        return $ret;
    }

    function updateLastIp($ip) {
        $lastIpLogFile = dirname(__FILE__).'/last_ip.log';
        file_put_contents($lastIpLogFile, $ip);
    }

    // ********************************************************************************

    $wanIp = getWanIp();

    if (!$wanIp) {
        writeLog('Can\'t resolve WAN ip');
        writeLog('--------------------------------------');
        exit;
    }
    if (empty($argv[1]) OR $argv[1]!='force') {
        $lastIp = getLastIp();
        if ($lastIp == $wanIp) {
            writeLog('Last wan ip ['.$lastIp.'] and wan ip ['.$wanIp.'] the same, additional check not needed. To force update send flag "force" to the script');
            writeLog('--------------------------------------');
            exit;
        }
    }

    updateLastIp($wanIp);

    // Tokens you can get here: https://pddimp.yandex.ru/api2/admin/get_token

    $domains = [
        [
            'domain'  => 'domain.com',
            'token' => '6LKLM4RNLYNPA5ENSKH3TX4DDOL4EKUDRHRVJ7NBHNPXOV26PAEK',
            'exclude' => [], // ex: ['subdomain.domain.com']
        ],
    ];

    foreach ($domains as $domain) {
        API2_changeIpAddressOf(
            $domain['token'], 
            $domain['domain'], 
            $wanIp, 
            $domain['exclude']
        );
    }

    writeLog('--------------------------------------');
