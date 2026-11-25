<?php
declare(strict_types=1);
/* This controller never executes a command taken from the manifest. */
abstract class LabHost {
    abstract public function bases(): array;
    abstract public function readConfig(): array;
    abstract public function digest(): string;
    abstract public function saveConfig(array $config, string $expectedDigest): void;
    abstract public function accountName(string $name): ?array;
    abstract public function accountUid(int $uid): ?array;
    abstract public function addUser(array $user): void;
    abstract public function deleteUser(string $name): void;
    abstract public function processUids(): array;
    abstract public function portBusy(int $port): bool;
    abstract public function publicKey(string $token): string;
    abstract public function reload(): void;
    abstract public function runtimeRules(): string;
    abstract public function runtimeCron(): string;
    abstract public function configFile(): string;
    abstract public function passwdFile(): string;
    public function owner(): int { return 0; }
}

function lab_fail(string $message): void { throw new RuntimeException($message); }
function lab_get(array $a, array $keys, $default = null) {
    foreach ($keys as $key) {
        if (!is_array($a) || !array_key_exists($key, $a)) { return $default; }
        $a = $a[$key];
    }
    return $a;
}
function lab_set(array &$a, array $keys, $value): void {
    $ref =& $a;
    foreach ($keys as $key) {
        if (!isset($ref[$key]) || !is_array($ref[$key])) { $ref[$key] = []; }
        $ref =& $ref[$key];
    }
    $ref = $value;
}
function lab_exists(string $path): bool { return file_exists($path) || is_link($path); }
function lab_read(string $path): string {
    $data = @file_get_contents($path);
    if ($data === false) { lab_fail("Cannot read {$path}"); }
    return $data;
}

final class PracticeLab {
    private LabHost $h;
    private array $b;
    private string $state;
    public function __construct(LabHost $host) {
        $this->h = $host;
        $this->b = $host->bases();
        foreach (['conf', 'root', 'rc', 'www', 'home'] as $key) {
            if (empty($this->b[$key]) || $this->b[$key][0] !== '/') { lab_fail('Invalid fixed base directory.'); }
        }
        $this->state = $this->b['conf'] . '/ccdc-practice';
    }
    private function safeParents(string $path): void {
        $parent = dirname($path);
        for ($p = $parent; $p !== '/'; $p = dirname($p)) {
            clearstatcache(true, $p);
            if (is_link($p)) { lab_fail("Symlink parent refused: {$p}"); }
            if (file_exists($p) && !is_dir($p)) { lab_fail("Non-directory parent: {$p}"); }
        }
    }
    private function privateDir(string $path, bool $create): bool {
        $this->safeParents($path);
        if (!lab_exists($path)) {
            if (!$create) { return false; }
            if (!@mkdir($path, 0700)) { lab_fail("Cannot create private directory: {$path}"); }
        }
        clearstatcache(true, $path);
        if (is_link($path) || !is_dir($path) || fileowner($path) !== $this->h->owner()
            || (fileperms($path) & 0077) !== 0) { lab_fail("Unsafe state directory: {$path}"); }
        return true;
    }
    private function privateFile(string $path): void {
        $this->safeParents($path);
        clearstatcache(true, $path);
        $st = @lstat($path);
        if (!$st || is_link($path) || !is_file($path) || $st['uid'] !== $this->h->owner()
            || ($st['mode'] & 0077) !== 0 || $st['nlink'] !== 1) { lab_fail("Unsafe state file: {$path}"); }
    }
    private function writeNew(string $path, string $data, int $mode = 0600): void {
        $this->safeParents($path);
        $f = @fopen($path, 'xb');
        if (!$f) { lab_fail("Cannot exclusively create {$path}"); }
        try {
            if (!chmod($path, $mode) || fwrite($f, $data) !== strlen($data) || !fflush($f)) {
                lab_fail("Cannot write {$path}");
            }
            if (function_exists('fsync')) { fsync($f); }
        } finally { fclose($f); }
    }
    private function jsonWrite(string $path, array $data): void {
        if (lab_exists($path)) { $this->privateFile($path); }
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(5));
        $this->writeNew($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        if (!rename($tmp, $path)) { @unlink($tmp); lab_fail("Cannot replace state file {$path}"); }
    }
    private function paths(string $token): array {
        return [
            'startup' => $this->b['rc'] . '/99-ccdc-lab-' . $token . '.sh',
            'credentials' => $this->b['root'] . '/ccdc-lab-' . $token . '-credentials.txt',
            'web' => $this->b['www'] . '/ccdc-lab-' . $token . '.txt',
        ];
    }
    private function home(string $name): string { return $this->b['home'] . '/' . $name; }
    private function runDir(array $m): string { return $this->state . '/' . $m['run']; }
    private function save(array $m): void { $this->jsonWrite($this->runDir($m) . '/manifest.json', $m); }
    private function manifest(): ?array {
        if (!$this->privateDir($this->state, false)) { return null; }
        $pointer = $this->state . '/active.json';
        if (!lab_exists($pointer)) { return null; }
        $this->privateFile($pointer);
        $a = json_decode(lab_read($pointer), true, 32, JSON_THROW_ON_ERROR);
        $run = $a['run'] ?? '';
        if (!is_string($run) || !preg_match('/^\d{8}T\d{6}Z-[a-f0-9]{10}$/D', $run)) { lab_fail('Invalid active run ID.'); }
        $this->privateDir($this->state . '/' . $run, false) || lab_fail('Run directory is missing.');
        $path = $this->state . '/' . $run . '/manifest.json';
        $this->privateFile($path);
        $m = json_decode(lab_read($path), true, 64, JSON_THROW_ON_ERROR);
        if (($m['schema'] ?? null) !== 1 || ($m['run'] ?? '') !== $run
            || !preg_match('/^[a-f0-9]{10}$/D', $m['token'] ?? '')
            || !str_ends_with($run, '-' . $m['token'])
            || ($m['tag'] ?? '') !== 'CCDC-LAB:' . $m['token']
            || !in_array($m['phase'] ?? '', ['prepared','config-writing','config-written','seeded','seed-failed','resetting','reset-required','reset'], true)) {
            lab_fail('Invalid manifest. Do not guess ownership; use the VM snapshot.');
        }
        foreach (['admin' => 'ccla_', 'key' => 'cclk_'] as $role => $prefix) {
            $u = $m['users'][$role] ?? [];
            if (($u['name'] ?? '') !== $prefix . $m['token'] || !is_int($u['uid'] ?? null)
                || $u['uid'] < 2000 || $u['uid'] >= 60000) { lab_fail('Invalid recorded account.'); }
        }
        if ($m['users']['admin']['uid'] === $m['users']['key']['uid']
            || !is_int($m['tracker'] ?? null) || $m['tracker'] < 1000000000
            || $m['tracker'] > 2000000000
            || !is_int($m['port'] ?? null) || $m['port'] < 1024 || $m['port'] > 65535
            || !is_int($m['nextuid_after'] ?? null) || $m['nextuid_after'] < 2002 || $m['nextuid_after'] > 60000
            || !is_bool($m['nextuid_before_present'] ?? null)
            || ($m['nextuid_before_present'] && (!is_scalar($m['nextuid_before'] ?? null)
                || !preg_match('/^[0-9]+$/D', (string)$m['nextuid_before'])))
            || !is_string($m['public_key'] ?? null)) { lab_fail('Invalid recorded IDs or allocator state.'); }
        return $m;
    }
    private function noHa(array $c): void {
        $ha = $c['hasync'] ?? [];
        if (!empty($ha['synchronizetoip']) || !empty($ha['synchronizepeerip'])) {
            lab_fail('HA configuration sync peer found. Use a standalone lab VM.');
        }
    }
    public function preflight(string $interface, int $port): array {
        $c = $this->h->readConfig();
        $this->noHa($c);
        if (!preg_match('/^(lan|opt[1-9][0-9]*)$/D', $interface)) { lab_fail('Choose lan or optN; WAN is refused.'); }
        $i = $c['interfaces'][$interface] ?? [];
        if (!$i || !array_key_exists('enable', $i) || empty($i['if']) || !empty($i['gateway'])) {
            lab_fail('Selected interface must be assigned, enabled, and have no upstream gateway.');
        }
        if (empty($i['ipaddr']) || in_array($i['ipaddr'], ['none','dhcp','pppoe'], true)) {
            lab_fail('Selected exercise interface must have a static IPv4 address.');
        }
        if (!filter_var($i['ipaddr'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { lab_fail('Static IPv4 interface required.'); }
        if ($port < 1024 || $port > 65535 || in_array($port, [1194,1900,4500,5353,8080,8443], true)) {
            lab_fail('Choose an unused high TCP port, normally 46290.');
        }
        if ($this->h->portBusy($port)) { lab_fail("TCP port {$port} already has a listener; choose another port."); }
        foreach (['conf','root','rc','www'] as $key) {
            $p = $this->b[$key]; $this->safeParents($p . '/placeholder');
            if (!is_dir($p) || !is_writable($p) || fileowner($p) !== $this->h->owner()
                || (fileperms($p) & 0022)) { lab_fail("Base directory is unavailable or writable by others: {$p}"); }
        }
        $m = $this->manifest();
        if ($m && $m['phase'] !== 'reset') { lab_fail('An exercise run is active/incomplete. Run reset --apply first.'); }
        if ($m && array_filter($this->inventory($m))) { lab_fail('Previous run still has live artifacts. Reset it first.'); }
        echo "Preflight passed: {$interface} ({$i['if']}), static IPv4 {$i['ipaddr']}, TCP {$port} unused.\n";
        echo "Isolation, snapshot, and console access must be checked by the operator.\n";
        return $c;
    }
    private function allocate(array $c): array {
        $next = max(2000, (int)lab_get($c, ['system','nextuid'], 2000)); $uids = [];
        $existing = array_map('intval', array_column(lab_get($c, ['system','user'], []), 'uid'));
        while (count($uids) < 2 && $next < 60000) {
            if (!in_array($next, $existing, true) && !$this->h->accountUid($next)) { $uids[] = $next; }
            $next++;
        }
        if (count($uids) !== 2) { lab_fail('No safe UID allocation available.'); }
        return [$uids, $next];
    }
    public function seed(string $interface, int $port, bool $apply, bool $confirmed, bool $console): int {
        $c = $this->preflight($interface, $port);
        echo "Plan: two new accounts, restricted SSH public-key artifact, one interface pass rule,\n";
        echo "      one logging cron job, startup logger, fake credentials, and a text web marker.\n";
        echo "Existing accounts/keys/management settings are not edited. No listener or reboot.\n";
        if (!$apply) { echo "PREVIEW ONLY. Add --apply --lab-confirmed --console-ready to seed.\n"; return 0; }
        if (!$confirmed || !$console) { lab_fail('Applying a seed requires --lab-confirmed and --console-ready.'); }
        $digest = $this->h->digest();
        // Refresh after the digest so a GUI change during preparation is detected at commit.
        $c = $this->h->readConfig(); $this->noHa($c);
        [$uids, $next] = $this->allocate($c);
        $token = bin2hex(random_bytes(5)); $tag = 'CCDC-LAB:' . $token;
        $run = gmdate('Ymd\THis\Z') . '-' . $token;
        $users = ['admin' => ['name'=>'ccla_'.$token, 'uid'=>$uids[0]], 'key'=>['name'=>'cclk_'.$token, 'uid'=>$uids[1]]];
        foreach ($users as $u) {
            if ($this->h->accountName($u['name']) || lab_exists($this->home($u['name']))) { lab_fail('Generated account/home collision.'); }
            foreach (lab_get($c, ['system','user'], []) as $v) { if (($v['name'] ?? '') === $u['name']) { lab_fail('Config username collision.'); } }
        }
        $paths = $this->paths($token);
        foreach ($paths as $path) { $this->safeParents($path); if (lab_exists($path)) { lab_fail('Generated file collision.'); } }
        $trackers = array_map('intval', array_column(lab_get($c, ['filter','rule'], []), 'tracker'));
        do { $tracker = random_int(1000000000, 2000000000); } while (in_array($tracker, $trackers, true));
        $key = $this->h->publicKey($token);
        if (!preg_match('/^ssh-ed25519 [A-Za-z0-9+\/=]+ CCDC-LAB:[a-f0-9]{10}$/D', $key)) { lab_fail('Invalid generated public key.'); }
        $key = 'command="/usr/bin/false",restrict,from="127.0.0.1,::1" ' . $key . "\n";
        $m = ['schema'=>1,'version'=>'1.0.0','run'=>$run,'token'=>$token,'tag'=>$tag,'phase'=>'prepared',
            'created_utc'=>gmdate('c'),'interface'=>$interface,'port'=>$port,'tracker'=>$tracker,'users'=>$users,
            'nextuid_before_present'=>array_key_exists('nextuid', $c['system'] ?? []),
            'nextuid_before'=>$c['system']['nextuid'] ?? null,'nextuid_after'=>$next,
            'public_key'=>$key,'file_hashes'=>[], 'error'=>null];
        $this->privateDir($this->state, true);
        $this->privateDir($this->runDir($m), true);
        $this->writeNew($this->runDir($m).'/config.before.xml', lab_read($this->h->configFile()));
        $this->writeNew($this->runDir($m).'/master.passwd.reference', lab_read($this->h->passwdFile()));
        $this->save($m); $this->jsonWrite($this->state.'/active.json', ['run'=>$run]);
        try {
            $contents = [
                'startup'=>"#!/bin/sh\n# {$tag} harmless exercise startup marker\n/usr/bin/logger -t ccdc-practice '{$tag} startup-marker'\nexit 0\n",
                'credentials'=>"{$tag}\nEXERCISE ONLY: fake, nonfunctional credentials.\nusername=training-operator\npassword=NotARealPassword-{$token}\nThis password does not authenticate either seeded account.\n",
                'web'=>"{$tag}\nTraining artifact: unapproved text file in the firewall web directory.\nNo script, secret, or remote-control functionality.\n",
            ];
            foreach ($contents as $role=>$data) { $m['file_hashes'][$role] = hash('sha256', $data); }
            $this->save($m);
            foreach ($contents as $role=>$data) { $this->writeNew($paths[$role], $data, $role==='startup' ? 0700 : ($role==='web' ? 0644 : 0600)); }
            $new = $c; $list = lab_get($new, ['system','user'], []); $entries = [];
            foreach ($users as $role=>$u) {
                $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
                if (!$hash) { lab_fail('Cannot hash random account password.'); }
                $entry = ['name'=>$u['name'],'uid'=>(string)$u['uid'],'scope'=>'user','descr'=>$tag.' '.$role,
                    'bcrypt-hash'=>$hash,'priv'=>[$role==='admin' ? 'page-all' : 'user-shell-access'],
                    'authorizedkeys'=>$role==='key' ? base64_encode($key) : ''];
                $list[]=$entry; $entries[]=$entry;
            }
            lab_set($new, ['system','user'], $list); lab_set($new, ['system','nextuid'], $next);
            $rules = lab_get($new, ['filter','rule'], []);
            array_unshift($rules, ['type'=>'pass','interface'=>$interface,'ipprotocol'=>'inet','protocol'=>'tcp',
                'source'=>['any'=>''],'destination'=>['network'=>'(self)','port'=>(string)$port],
                'descr'=>$tag.' exercise allow','tracker'=>$tracker]);
            lab_set($new, ['filter','rule'], $rules);
            $cron = lab_get($new, ['cron','item'], []);
            $cron[]=['minute'=>'*/5','hour'=>'*','mday'=>'*','month'=>'*','wday'=>'*','who'=>'root','command'=>$this->cronCommand($m)];
            lab_set($new, ['cron','item'], $cron);
            $m['phase']='config-writing'; $this->save($m);
            $this->h->saveConfig($new, $digest);
            $m['phase']='config-written'; $this->save($m);
            foreach ($entries as $entry) { $this->h->addUser($entry); }
            $this->h->reload();
            $inv = $this->inventory($m);
            if (count(array_filter($inv)) !== 8 || !$this->seedNativeChecks($m)) { lab_fail('Native artifact verification failed.'); }
            $m['phase']='seeded'; $this->save($m);
            echo "Seeded and verified run {$run}. Use status/grade for instructor inventory.\n";
            return 0;
        } catch (Throwable $e) {
            $m['phase']='seed-failed'; $m['error']=$e->getMessage(); $this->save($m);
            echo "Seed failed: {$e->getMessage()}\nAttempting selective cleanup.\n";
            try { $this->reset(true); }
            catch (Throwable $rollback) { echo "Cleanup incomplete: {$rollback->getMessage()}\nUse the direct console and reset --apply; VM snapshot is the final fallback.\n"; }
            return 1;
        }
    }
    private function cronCommand(array $m): string {
        return "/usr/bin/logger -t ccdc-practice '".$m['tag']." scheduled-marker'";
    }
    private function seedNativeChecks(array $m): bool {
        foreach ($m['users'] as $u) {
            $os=$this->h->accountName($u['name']);
            if (!$os || (int)$os['uid'] !== $u['uid']) { return false; }
        }
        $keyPath=$this->home($m['users']['key']['name']).'/.ssh/authorized_keys';
        return is_file($keyPath) && !is_link($keyPath) && str_contains(lab_read($keyPath), $m['public_key'])
            && str_contains($this->h->runtimeRules(), (string)$m['tracker'])
            && str_contains($this->h->runtimeCron(), $this->cronCommand($m));
    }
    private function inventory(array $m): array {
        $c=$this->h->readConfig(); $users=lab_get($c,['system','user'],[]); $paths=$this->paths($m['token']);
        $out=['admin_account'=>false,'shell_account'=>false,'ssh_key'=>false,'firewall_rule'=>false,
            'cron_job'=>false,'startup_file'=>lab_exists($paths['startup']),
            'fake_credentials'=>lab_exists($paths['credentials']),'web_text_file'=>lab_exists($paths['web'])];
        foreach ($m['users'] as $role=>$u) {
            $key=$role==='admin'?'admin_account':'shell_account';
            $os=$this->h->accountName($u['name']); $byUid=$this->h->accountUid($u['uid']);
            $out[$key]=(bool)$os || (bool)$byUid;
            foreach ($users as $v) {
                if (($v['name']??'')===$u['name'] || (int)($v['uid']??-1)===$u['uid']) {
                    $out[$key]=true;
                    $decoded=base64_decode($v['authorizedkeys']??'',true);
                    if (is_string($decoded) && str_contains($decoded,$m['tag'])) { $out['ssh_key']=true; }
                }
            }
            // A retained home key is not live once its OS account has been removed.
            $kp=$this->home($u['name']).'/.ssh/authorized_keys';
            $this->safeParents($kp);
            if ($os && is_file($kp) && !is_link($kp) && str_contains(lab_read($kp),$m['tag'])) { $out['ssh_key']=true; }
        }
        foreach (lab_get($c,['filter','rule'],[]) as $r) {
            if ((int)($r['tracker']??0)===$m['tracker'] || str_contains($r['descr']??'',$m['tag'])) { $out['firewall_rule']=true; }
        }
        $live=$this->h->runtimeRules();
        if (preg_match('/(?<!\d)'.preg_quote((string)$m['tracker'],'/').'(?!\d)/', $live) || str_contains($live,$m['tag'])) { $out['firewall_rule']=true; }
        foreach (lab_get($c,['cron','item'],[]) as $r) { if (str_contains($r['command']??'',$m['tag'])) { $out['cron_job']=true; } }
        if (str_contains($this->h->runtimeCron(),$m['tag'])) { $out['cron_job']=true; }
        return $out;
    }
    public function status(bool $grade=false, bool $verify=false): int {
        $m=$this->manifest();
        if (!$m) { echo "No recorded lab run. Nothing can be graded or reset from a manifest.\n"; return $verify?1:0; }
        echo "Run: {$m['run']} | phase: {$m['phase']}\n";
        $inv=$this->inventory($m);
        foreach ($inv as $name=>$present) { echo sprintf("%-20s %s\n",$name,$present?'PRESENT / REVIEW':'absent'); }
        $n=count(array_filter($inv)); echo "{$n}/8 recorded artifact categories remain.\n";
        echo "Inventory is not proof the firewall is clean. Logs/backups/retained homes are excluded.\n";
        if ($verify && $m['phase']!=='reset') { echo "Run reset --apply to finish bookkeeping, even if all artifacts were removed manually.\n"; return 1; }
        return ($grade || $verify) && $n ? 2:0;
    }
    private function resetPlan(array $m, array $c): array {
        $this->noHa($c); $new=$c; $kept=[]; $uids=[];
        foreach ($m['users'] as $u) {
            $uids[]=$u['uid'];
            foreach (lab_get($c,['system','user'],[]) as $v) {
                $sameName=($v['name']??'')===$u['name']; $sameUid=(int)($v['uid']??-1)===$u['uid'];
                if ($sameName !== $sameUid) { lab_fail('Account identity conflict in config: '.$u['name']); }
            }
            $os=$this->h->accountName($u['name']); $byUid=$this->h->accountUid($u['uid']);
            if (($os && (int)$os['uid']!==$u['uid']) || ($byUid && $byUid['name']!==$u['name'])) { lab_fail('OS account identity conflict: '.$u['name']); }
            if ($os) {
                $expectedHome=$this->home($u['name']); $declaredHome=rtrim($os['dir'],'/');
                $resolvedHome=realpath($declaredHome);
                if ($declaredHome!==$expectedHome && ($resolvedHome===false || $resolvedHome!==realpath($expectedHome))) {
                    lab_fail('Changed account home path: '.$u['name']);
                }
            }
        }
        if (array_intersect($uids,$this->h->processUids())) { lab_fail('A process runs under a seeded UID. End those sessions from the console, then retry reset.'); }
        foreach (lab_get($c,['system','user'],[]) as $v) { if (!in_array((int)($v['uid']??-1),$uids,true)) { $kept[]=$v; } }
        lab_set($new,['system','user'],$kept);
        $groups=lab_get($new,['system','group'],[]);
        foreach ($groups as &$g) {
            if (isset($g['member']) && is_array($g['member'])) {
                $g['member']=array_values(array_filter($g['member'],fn($x)=>!in_array((int)$x,$uids,true)));
            }
        }
        unset($g);
        if (array_key_exists('group',$new['system']??[])) { lab_set($new,['system','group'],$groups); }
        $rules=[];
        foreach (lab_get($c,['filter','rule'],[]) as $r) {
            $sameTracker=(int)($r['tracker']??0)===$m['tracker']; $tagged=str_contains($r['descr']??'',$m['tag']);
            if ($sameTracker && !$tagged) { lab_fail('Firewall tracker reused or label changed. Resolve the ownership conflict manually.'); }
            if (!$sameTracker && !$tagged) { $rules[]=$r; }
        }
        lab_set($new,['filter','rule'],$rules);
        $cron=array_values(array_filter(lab_get($c,['cron','item'],[]),fn($x)=>!str_contains($x['command']??'',$m['tag'])));
        lab_set($new,['cron','item'],$cron);
        if ((int)lab_get($new,['system','nextuid'],-1)===$m['nextuid_after']) {
            if ($m['nextuid_before_present']) { $new['system']['nextuid']=$m['nextuid_before']; }
            else { unset($new['system']['nextuid']); }
        }
        foreach ($this->paths($m['token']) as $path) {
            $this->safeParents($path);
            if (lab_exists($path) && !is_link($path) && !is_file($path)) { lab_fail('Exercise file became a directory/device: '.$path); }
        }
        foreach ($m['users'] as $u) {
            $p=$this->home($u['name']); $this->safeParents($p);
            if (lab_exists($p) && (is_link($p) || !is_dir($p))) { lab_fail('Unsafe exercise home: '.$p); }
        }
        return $new;
    }
    private function retainFile(array $m, string $role, string $path): void {
        if (!lab_exists($path)) { return; }
        $ret=$this->runDir($m).'/retained'; $this->privateDir($ret,true);
        $suffix=bin2hex(random_bytes(5));
        if (is_link($path)) {
            $this->writeNew($ret.'/'.$role.'-'.$suffix.'.symlink.txt',"Unlinked exercise-path symlink; target was not followed:\n".readlink($path)."\n");
        } else {
            if (!is_file($path)) { lab_fail('Unsupported exercise file type: '.$path); }
            // Preserve all current content, including student edits, before removing the original.
            $this->writeNew($ret.'/'.$role.'-'.$suffix.'.saved',lab_read($path));
        }
        if (!unlink($path)) { lab_fail('Cannot unlink exercise path: '.$path); }
    }
    private function retainHome(array $m, array $u): void {
        $home=$this->home($u['name']);
        if (!lab_exists($home)) { return; }
        $this->safeParents($home);
        if (is_link($home) || !is_dir($home)) { lab_fail('Unsafe exercise home: '.$home); }
        // No recursive delete/chown: the original directory and student files are preserved.
        if (!chown($home,$this->h->owner()) || !chgrp($home,$this->h->owner()) || !chmod($home,0700)) { lab_fail('Cannot secure retained home: '.$home); }
        $ret=$this->runDir($m).'/retained'; $this->privateDir($ret,true);
        $dest=$ret.'/home-'.$u['name'];
        if (!lab_exists($dest) && @rename($home,$dest)) { echo "Retained home: {$dest}\n"; }
        else { echo "Retained home in place, root-only: {$home}\n"; }
    }
    public function reset(bool $apply): int {
        $m=$this->manifest();
        if (!$m) { echo "No recorded run; no changes.\n"; return 0; }
        $digest=$this->h->digest(); $c=$this->h->readConfig(); $new=$this->resetPlan($m,$c);
        echo "Reset plan for {$m['run']}: remove matching generated accounts, tagged rules/cron,\n";
        echo "and the three recorded exercise paths. Preserve current contents and account homes.\n";
        if (!$apply) { echo "PREVIEW ONLY. Add --apply to reset.\n"; return 0; }
        $m['phase']='resetting'; $this->save($m);
        try {
            $this->h->saveConfig($new,$digest);
            foreach ($m['users'] as $u) {
                $os=$this->h->accountName($u['name']);
                if ($os) {
                    if ((int)$os['uid']!==$u['uid']) { lab_fail('Account changed during reset.'); }
                    $this->h->deleteUser($u['name']);
                }
                $this->retainHome($m,$u);
            }
            foreach ($this->paths($m['token']) as $role=>$path) { $this->retainFile($m,$role,$path); }
            $this->h->reload();
            if (array_filter($this->inventory($m))) { lab_fail('Recorded artifacts remain after reset. Inspect status and rerun reset.'); }
            $m['phase']='reset'; $m['reset_utc']=gmdate('c'); $m['error']=null; $this->save($m);
            echo "Reset verified. Sensitive backups, retained evidence, logs and config history remain.\n";
            return 0;
        } catch (Throwable $e) {
            $m['phase']='reset-required'; $m['error']=$e->getMessage(); $this->save($m); throw $e;
        }
    }
}

function lab_cli(LabHost $host, array $argv): int {
    $cmd=$argv[1]??''; $opt=['apply'=>false,'lab-confirmed'=>false,'console-ready'=>false,'interface'=>'','port'=>46290];
    $seen=[];
    for ($i=2;$i<count($argv);$i++) {
        $a=$argv[$i];
        if (!str_starts_with($a,'--') || !array_key_exists(substr($a,2),$opt)) { lab_fail('Unknown option: '.$a); }
        $k=substr($a,2); if (isset($seen[$k])) { lab_fail('Duplicate option: '.$a); } $seen[$k]=true;
        if (in_array($k,['interface','port'],true)) {
            if (!isset($argv[++$i]) || str_starts_with($argv[$i],'--')) { lab_fail('Missing value: '.$a); }
            $opt[$k]=$argv[$i];
        } else { $opt[$k]=true; }
    }
    if (!in_array($cmd,['seed','preflight','reset','status','grade','verify-reset'],true)) { lab_fail('Unknown command. Use: sh lab.sh help'); }
    $allowed=match($cmd) {'seed'=>array_keys($opt),'preflight'=>['interface','port'],'reset'=>['apply'],default=>[]};
    foreach ($seen as $k=>$_) { if (!in_array($k,$allowed,true)) { lab_fail('Option --'.$k.' does not apply to '.$cmd); } }
    if (!preg_match('/^[0-9]{1,5}$/D',(string)$opt['port'])) { lab_fail('Invalid port.'); }
    $lab=new PracticeLab($host);
    return match($cmd) {
        'preflight'=>(function() use($lab,$opt){$lab->preflight($opt['interface'],(int)$opt['port']);return 0;})(),
        'seed'=>$lab->seed($opt['interface'],(int)$opt['port'],$opt['apply'],$opt['lab-confirmed'],$opt['console-ready']),
        'reset'=>$lab->reset($opt['apply']),
        'status'=>$lab->status(), 'grade'=>$lab->status(true), 'verify-reset'=>$lab->status(true,true),
    };
}
