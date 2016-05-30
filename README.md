# DynDnsWithYandexPdd
Script that change Yandex PDD DNS A-records to WAN IP of server when it changed by some reason

Link to obtain API V2 token for domain:
https://pddimp.yandex.ru/api2/admin/get_token

Documentation:
- https://tech.yandex.ru/pdd/doc/concepts/api-dns-docpage/

You can find additional info in articles from habr:
 - https://habrahabr.ru/post/239465/
 - https://habrahabr.ru/sandbox/102896/

Cron example:
*/5    *   *   *   *   php /var/www/dyndns/change-ip.php > /dev/null 2>&1
