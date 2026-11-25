<?php
declare(strict_types=1);
umask(0077);
if (PHP_SAPI !== 'cli' || php_uname('s') !== 'FreeBSD' || !function_exists('posix_geteuid') || posix_geteuid() !== 0
    || !is_file('/etc/inc/config.inc') || !is_file('/conf/config.xml')) {
    fwrite(STDERR,"ERROR: disposable pfSense VM, root CLI, and native PHP are required.\n"); exit(1);
}
// Top-level includes are intentional: native pfSense libraries use global configuration.
require_once('/etc/inc/globals.inc');
require_once('/etc/inc/config.inc');
require_once('/etc/inc/functions.inc');
require_once('/etc/inc/auth.inc');
require_once('/etc/inc/services.inc');
require_once('/etc/inc/filter.inc');
require_once(__DIR__.'/lab.php');

function lab_exec(array $args): string {
    $pipes=[];
    $p=proc_open($args,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($p)) { lab_fail('Cannot start native utility: '.$args[0]); }
    $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $rc=proc_close($p);
    if ($rc!==0) { lab_fail('Native utility failed: '.$args[0].' ('.$rc.') '.trim($err)); }
    return $out;
}
final class NativeLabHost extends LabHost {
    public function __construct() {
        foreach (['config_get_path','parse_config','write_config','local_user_set','configure_cron','filter_configure_sync','posix_getpwnam','posix_getpwuid','proc_open'] as $fn) {
            if (!function_exists($fn)) { lab_fail('Required native API unavailable: '.$fn); }
        }
        foreach (['/usr/sbin/pw','/usr/bin/ssh-keygen','/usr/bin/sockstat','/bin/ps','/sbin/pfctl','/usr/bin/false','/usr/bin/logger'] as $exe) {
            if (!is_executable($exe)) { lab_fail('Required native utility unavailable: '.$exe); }
        }
    }
    public function bases(): array {
        $out=[];
        foreach (['conf'=>'/conf','root'=>'/root','rc'=>'/usr/local/etc/rc.d','www'=>'/usr/local/www','home'=>'/home'] as $k=>$v) {
            $real=realpath($v);
            if ($real===false && $k==='home' && !is_link($v)) { $real=realpath(dirname($v)).'/'.basename($v); }
            if ($real===false) { lab_fail('Required base missing: '.$v); }
            $out[$k]=$real;
        }
        return $out;
    }
    public function readConfig(): array {
        global $config; $config=parse_config(true);
        if (!is_array($config) || !isset($config['system'])) { lab_fail('Cannot load native pfSense configuration.'); }
        return $config;
    }
    public function configFile(): string { return '/conf/config.xml'; }
    public function passwdFile(): string { return '/etc/master.passwd'; }
    public function digest(): string {
        $hash=hash_file('sha256',$this->configFile());
        if (!$hash) { lab_fail('Cannot hash current configuration.'); } return $hash;
    }
    public function saveConfig(array $next,string $expectedDigest): void {
        global $config;
        if (!hash_equals($expectedDigest,$this->digest())) { lab_fail('Configuration changed during planning. Pause GUI edits and retry.'); }
        $config=$next;
        if (write_config('CCDC practice lab: controlled seed/reset')===false) { lab_fail('Native write_config failed.'); }
        $actual=$this->readConfig();
        foreach (['system','filter','cron'] as $key) {
            if (($actual[$key]??[])!=($next[$key]??[])) { lab_fail('Saved configuration did not match the requested native sections.'); }
        }
    }
    public function accountName(string $name): ?array { $v=posix_getpwnam($name); return $v===false?null:$v; }
    public function accountUid(int $uid): ?array { $v=posix_getpwuid($uid); return $v===false?null:$v; }
    public function addUser(array $user): void { local_user_set($user); }
    public function deleteUser(string $name): void {
        // Deliberately omit -r; student files in the new account home are retained.
        lab_exec(['/usr/sbin/pw','userdel','-n',$name]);
        if ($this->accountName($name)) { lab_fail('Native account deletion did not complete: '.$name); }
    }
    public function processUids(): array {
        $uids=[];
        foreach (preg_split('/\r?\n/',lab_exec(['/bin/ps','-axo','uid=,pid='])) as $line) {
            if (preg_match('/^\s*(\d+)\s+\d+\s*$/D',$line,$m)) { $uids[]=(int)$m[1]; }
        }
        return array_values(array_unique($uids));
    }
    public function portBusy(int $port): bool {
        $out=lab_exec(['/usr/bin/sockstat','-46','-l','-P','tcp']);
        foreach (preg_split('/\r?\n/',$out) as $line) {
            $parts=preg_split('/\s+/',trim($line));
            // USER COMMAND PID FD PROTO LOCAL-ADDRESS FOREIGN-ADDRESS
            if (isset($parts[5]) && preg_match('/[:.]'.preg_quote((string)$port,'/').'$/D',$parts[5])) { return true; }
        }
        return false;
    }
    public function publicKey(string $token): string {
        $tmp='/tmp/ccdc-lab-key-'.bin2hex(random_bytes(12));
        if (!mkdir($tmp,0700)) { lab_fail('Cannot create private temporary key directory.'); }
        try {
            lab_exec(['/usr/bin/ssh-keygen','-q','-t','ed25519','-N','','-C','CCDC-LAB:'.$token,'-f',$tmp.'/key']);
            $public=trim(lab_read($tmp.'/key.pub'));
        } finally {
            // The private half is deleted BEFORE any public key is installed in an account.
            $a=!file_exists($tmp.'/key') || unlink($tmp.'/key');
            $b=!file_exists($tmp.'/key.pub') || unlink($tmp.'/key.pub');
            $c=@rmdir($tmp);
            if (!$a || !$b || !$c) { lab_fail('Temporary key cleanup failed; nothing has been installed. Inspect '.$tmp); }
        }
        return $public;
    }
    public function reload(): void {
        configure_cron();
        $rc=filter_configure_sync(false);
        if (is_int($rc) && $rc!==0) { lab_fail('Native firewall reload failed: '.$rc); }
    }
    public function runtimeRules(): string { return lab_exec(['/sbin/pfctl','-vvsr']); }
    public function runtimeCron(): string { return lab_read('/etc/crontab'); }
}

try { exit(lab_cli(new NativeLabHost(),$argv)); }
catch (Throwable $e) { fwrite(STDERR,'ERROR: '.$e->getMessage()."\n"); exit(1); }
