<?php
declare(strict_types=1);
function delivery_request(CurlHandle $curl): void {
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>config()['delivery_timeout_seconds'],CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false]);
    // Optional dedicated trust store, e.g. for a company SMTP CA. Verification stays enabled.
    if (!empty(config()['delivery_ca_file'])) curl_setopt($curl,CURLOPT_CAINFO,config()['delivery_ca_file']);
    $response=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    if ($response===false || $code<200 || $code>=300) throw new RuntimeException('Versand vom Anbieter nicht bestätigt.');
}
function send_invitation(array $link): void {
    if (!extension_loaded('curl')) throw new RuntimeException('PHP-cURL fehlt.');
    if ($link['kind']==='email') send_email($link); else send_sms($link);
}
function send_email(array $link): void {
    $c=config()['smtp'];
    if (empty($c['enabled'])) throw new RuntimeException('E-Mail-Versand ist nicht eingerichtet.');
    $host=(string)($c['host']??'');$port=(int)($c['port']??587);$security=$c['security']??'starttls';
    if (!preg_match('/^[a-zA-Z0-9.-]+$/',$host) || $port<1 || $port>65535 || !in_array($security,['starttls','tls'],true)) throw new RuntimeException('Ungültige SMTP-Konfiguration.');
    $from=normalize_identity('email',(string)($c['from']??''));
    $to=normalize_identity('email',$link['identity']);
    $subject='=?UTF-8?B?'.base64_encode('Deine Datei in der Dropkiste').'?=';
    $body="Eine Datei wartet auf dich:\n\n".$link['url']."\n\nBitte bestätige dort deine eingeladene E-Mail-Adresse.\nDer Link ist nur für dich bestimmt und zeitlich begrenzt verfügbar.\n";
    $message="Date: ".date(DATE_RFC2822)."\r\nFrom: <".$from.">\r\nTo: <".$to.">\r\nSubject: ".$subject."\r\nMessage-ID: <".bin2hex(random_bytes(16))."@".substr($from,strpos($from,'@')+1).">\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($body),76,"\r\n");
    $stream=fopen('php://temp','w+b');fwrite($stream,$message);rewind($stream);
    $curl=curl_init(($security==='tls'?'smtps':'smtp').'://'.$host.':'.$port);
    curl_setopt_array($curl,[CURLOPT_UPLOAD=>true,CURLOPT_INFILE=>$stream,CURLOPT_INFILESIZE=>strlen($message),CURLOPT_MAIL_FROM=>$from,CURLOPT_MAIL_RCPT=>[$to],CURLOPT_USE_SSL=>CURLUSESSL_ALL]);
    if (!empty($c['username'])) curl_setopt_array($curl,[CURLOPT_USERNAME=>$c['username'],CURLOPT_PASSWORD=>$c['password']??'']);
    try {delivery_request($curl);} finally {fclose($stream);}
}
function send_sms(array $link): void {
    $c=config()['sms'];
    if (empty($c['enabled']) || ($c['provider']??'')!=='twilio') throw new RuntimeException('SMS-Versand ist nicht eingerichtet.');
    $account=(string)($c['account_sid']??'');
    if (!preg_match('/^AC[a-fA-F0-9]{32}$/',$account) || empty($c['auth_token'])) throw new RuntimeException('Ungültige SMS-Konfiguration.');
    $to=normalize_identity('phone',$link['identity']);
    $data=['To'=>$to,'Body'=>"Deine Datei: ".$link['url']." Bitte bestätige deine Telefonnummer mit Ländervorwahl. Link nicht weitergeben."];
    if (!empty($c['messaging_service_sid'])) $data['MessagingServiceSid']=$c['messaging_service_sid'];
    else $data['From']=normalize_identity('phone',(string)($c['from']??''));
    $curl=curl_init('https://api.twilio.com/2010-04-01/Accounts/'.$account.'/Messages.json');
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data),CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_USERNAME=>$account,CURLOPT_PASSWORD=>$c['auth_token']]);
    delivery_request($curl);
    // Accepted by the provider is not the same as delivered to the device.
}
