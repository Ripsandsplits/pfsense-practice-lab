<?php
declare(strict_types=1);
/* Run with PHP 8.1+ on a development machine, never against a real firewall.
 * Every path is an isolated temporary fixture. Native pfSense APIs are mocked.
 */
require_once(__DIR__.'/../lib/lab.php');
final class FakeLabHost extends LabHost {
    public string $root;
    public array $os=[];
    public array $processes=[];
    public bool $busy=false;
    public int $failReload=0;
    public bool $silentReload=false;
    public bool $failSave=false;
    public bool $partialSave=false;
    public bool $failAdd=false;
    public bool $race=false;
    public string $rules='';
    public string $cron='';
    public int $writes=0;
    public function __construct(string $root) {
        $this->root=$root;
        foreach (['conf','root','rc','www','home'] as $x) { mkdir($root.'/'.$x,0700,true); }
        $this->putConfig(['system'=>['nextuid'=>'2001','user'=>[
            ['name'=>'admin','uid'=>'0','bcrypt-hash'=>'KEEP_ADMIN','priv'=>['page-all']],
            ['name'=>'ops','uid'=>'2000','bcrypt-hash'=>'KEEP_OPS','authorizedkeys'=>base64_encode('existing-key')]],
            'group'=>[['name'=>'admins','member'=>['0','2000'],'priv'=>['page-all']]],
            'webgui'=>['protocol'=>'https','port'=>'443']],
            'interfaces'=>['lan'=>['if'=>'vtnet1','enable'=>'','ipaddr'=>'192.0.2.1','subnet'=>'24'],
                'wan'=>['if'=>'vtnet0','enable'=>'','ipaddr'=>'dhcp']],
            'filter'=>['rule'=>[['tracker'=>100,'descr'=>'business rule','type'=>'pass']]],
            'cron'=>['item'=>[['command'=>'/usr/bin/true','minute'=>'0','who'=>'root']]],
            'nat'=>['rule'=>[['descr'=>'business NAT','target'=>'192.0.2.10']]],
            'aliases'=>['alias'=>[['name'=>'business_alias']]],
            'dhcpd'=>['lan'=>['range'=>['from'=>'192.0.2.100','to'=>'192.0.2.150']]]]);
        file_put_contents($this->passwdFile(),'admin:KEEP_ADMIN:0:0:...');
        $this->os=['admin'=>['name'=>'admin','uid'=>0,'dir'=>$root.'/root'],
            'ops'=>['name'=>'ops','uid'=>2000,'dir'=>$root.'/home/ops']];
        mkdir($root.'/home/ops',0700); mkdir($root.'/home/ops/.ssh',0700);
        file_put_contents($root.'/home/ops/.ssh/authorized_keys','existing unmanaged key');
        $this->refreshRuntime();
    }
    public function bases(): array { $out=[];foreach(['conf','root','rc','www','home']as$x){$out[$x]=$this->root.'/'.$x;}return $out; }
    public function readConfig(): array { return json_decode(file_get_contents($this->configFile()),true,64,JSON_THROW_ON_ERROR); }
    public function putConfig(array $c): void { file_put_contents($this->configFile(),json_encode($c,JSON_THROW_ON_ERROR)); }
    public function digest(): string { return hash_file('sha256',$this->configFile()); }
    public function saveConfig(array $c,string $d): void {
        if($this->race){$this->race=false;$x=$this->readConfig();$x['system']['concurrent']='keep';$this->putConfig($x);}
        if(!hash_equals($d,$this->digest())){lab_fail('Concurrent configuration edit.');}
        if($this->failSave){lab_fail('Simulated native write failure.');}
        $this->writes++;$this->putConfig($c);
        if($this->partialSave){$this->partialSave=false;lab_fail('Simulated failure after committed write.');}
    }
    public function accountName(string $n): ?array { return $this->os[$n]??null; }
    public function accountUid(int $uid): ?array { foreach($this->os as$u){if($u['uid']===$uid)return $u;}return null; }
    public function addUser(array $u): void {
        if($this->failAdd){$this->failAdd=false;lab_fail('Simulated account generator failure.');}
        $home=$this->root.'/home/'.$u['name'];$this->os[$u['name']]=['name'=>$u['name'],'uid'=>(int)$u['uid'],'dir'=>$home];
        mkdir($home,0700);
        if(!empty($u['authorizedkeys'])){mkdir($home.'/.ssh',0700);file_put_contents($home.'/.ssh/authorized_keys',base64_decode($u['authorizedkeys']));}
    }
    public function deleteUser(string $n): void { unset($this->os[$n]); }
    public function processUids(): array { return $this->processes; }
    public function portBusy(int $p): bool { return $this->busy; }
    public function publicKey(string $t): string { return 'ssh-ed25519 '.base64_encode(pack('N',11).'ssh-ed25519'.pack('N',32).str_repeat('P',32)).' CCDC-LAB:'.$t; }
    public function refreshRuntime(): void {
        $c=$this->readConfig();$this->rules='';$this->cron='';
        foreach($c['filter']['rule']??[]as$r){$this->rules.=($r['descr']??'').' ridentifier '.($r['tracker']??'')."\n";}
        foreach($c['cron']['item']??[]as$r){$this->cron.=($r['command']??'')."\n";}
    }
    public function reload(): void {
        if($this->failReload>0){$this->failReload--;lab_fail('Simulated reload failure.');}
        if(!$this->silentReload){$this->refreshRuntime();}
    }
    public function runtimeRules(): string { return $this->rules; }
    public function runtimeCron(): string { return $this->cron; }
    public function configFile(): string { return $this->root.'/conf/config.xml'; }
    public function passwdFile(): string { return $this->root.'/master.passwd'; }
    public function owner(): int { return fileowner($this->root); }
}

$checks=0;$cases=0;
function check(bool $v,string $msg): void {global $checks;if(!$v)throw new RuntimeException('FAIL '.$msg);$checks++;echo 'PASS '.$msg."\n";}
function quiet(callable $fn): array {ob_start();try{$v=$fn();$out=ob_get_clean();return[$v,$out,null];}catch(Throwable $e){$out=ob_get_clean();return[1,$out,$e];}}
function fixture(): FakeLabHost {global $cases;$cases++;return new FakeLabHost(sys_get_temp_dir().'/pfsense-lab-test-'.bin2hex(random_bytes(8)).'-'.$cases);}
function cli(FakeLabHost $h,string ...$args): array {return quiet(fn()=>lab_cli($h,array_merge(['lab.php'],$args)));}
function seeded(FakeLabHost $h): array {[$rc,$out,$e]=cli($h,'seed','--interface','lan','--apply','--lab-confirmed','--console-ready');if($rc!==0||$e)throw new RuntimeException('Cannot seed fixture: '.$out.' '.($e?$e->getMessage():''));return manifestOf($h);}
function manifestOf(FakeLabHost $h): array {$s=$h->root.'/conf/ccdc-practice';$p=json_decode(file_get_contents($s.'/active.json'),true);return json_decode(file_get_contents($s.'/'.$p['run'].'/manifest.json'),true);}

$h=fixture();$before=$h->readConfig();$digest=$h->digest();[$r,$out,$e]=cli($h,'seed','--interface','lan');
check($r===0&&!$e&&str_contains($out,'PREVIEW ONLY'),'seed preview succeeds');
check($h->digest()===$digest&&!is_dir($h->root.'/conf/ccdc-practice')&&count($h->os)===2,'seed preview writes no files, accounts, or configuration');
[$r,,$e]=cli($h,'seed','--interface','lan','--apply');check($e!==null&&$h->digest()===$digest,'seed apply requires both lab acknowledgments');
foreach ([['seed','--interface','wan'],['preflight','--interface','lan','--port','22'],['preflight','--interface','lan','--port','no'],['status','--apply'],['reset','--apply','--apply'],['reset','--unknown'],['preflight','--interface']] as$a) {
    [,,$e]=cli($h,...$a);check($e!==null,'invalid command/options refused: '.implode(' ',$a));
}
$h->busy=true;[,,$e]=cli($h,'preflight','--interface','lan');check($e!==null,'listening target port refused');$h->busy=false;
$c=$h->readConfig();$c['hasync']=['synchronizetoip'=>'192.0.2.2'];$h->putConfig($c);[,,$e]=cli($h,'preflight','--interface','lan');check($e!==null,'HA synchronization peer refused');$h->putConfig($before);
$c=$before;$c['interfaces']['lan']['gateway']='WAN_GW';$h->putConfig($c);[,,$e]=cli($h,'preflight','--interface','lan');check($e!==null,'upstream interface refused');$h->putConfig($before);
$c=$before;unset($c['interfaces']['lan']['enable']);$h->putConfig($c);[,,$e]=cli($h,'preflight','--interface','lan');check($e!==null,'disabled interface refused');$h->putConfig($before);
$m=seeded($h);$after=$h->readConfig();
check(count($after['system']['user'])===4&&count($h->os)===4,'seed adds exactly two managed/OS accounts');
check($m['users']['admin']['uid']>=2000&&$m['users']['key']['uid']!==$m['users']['admin']['uid'],'new accounts use distinct non-root UIDs');
check(strlen($m['users']['admin']['name'])<=16,'generated account names fit 16-character limit');
check(array_slice($after['system']['user'],0,2)===$before['system']['user'],'existing password hashes and configured SSH keys unchanged');
check(file_get_contents($h->root.'/home/ops/.ssh/authorized_keys')==='existing unmanaged key','existing unmanaged authorized_keys untouched');
check($after['nat']===$before['nat']&&$after['aliases']===$before['aliases']&&$after['interfaces']===$before['interfaces']&&$after['dhcpd']===$before['dhcpd'],'NAT, aliases, addressing and DHCP untouched');
check($after['system']['webgui']===$before['system']['webgui'],'management settings unchanged');
$r=$after['filter']['rule'][0];check($r['destination']['port']==='46290'&&$r['destination']['network']==='(self)'&&$r['source']===['any'=>'']&&$r['ipprotocol']==='inet','practice rule limited to firewall-local IPv4 TCP high port');
check(str_starts_with($m['public_key'],'command="/usr/bin/false",restrict,from="127.0.0.1,::1" '),'public key is forced-command, restricted, loopback-only');
check(!str_contains(json_encode($m),'bcrypt-hash')&&!str_contains(json_encode($m),'PRIVATE KEY'),'manifest contains no account password hash or private key');
check(!password_verify('NotARealPassword-'.$m['token'],$after['system']['user'][2]['bcrypt-hash']),'fake credentials cannot authenticate privileged practice account');
check((fileperms($h->root.'/conf/ccdc-practice')&0077)===0&&(fileperms($h->root.'/conf/ccdc-practice/'.$m['run'].'/config.before.xml')&0077)===0,'state and sensitive backups are private');
[$r,$out,$e]=cli($h,'grade');check($r===2&&!$e&&str_contains($out,'8/8'),'grade returns 2 for eight live artifact categories');
[,,$e]=cli($h,'seed','--interface','lan');check($e!==null,'duplicate seed refused while active');
$digest=$h->digest();[$r,$out,$e]=cli($h,'reset');check($r===0&&!$e&&$h->digest()===$digest&&count($h->os)===4&&str_contains($out,'PREVIEW ONLY'),'reset preview does not mutate');
// Preserve student/administrator changes and all exercise file content.
$c=$h->readConfig();$c['system']['student_setting']='keep';$c['nat']['rule'][]=['descr'=>'student NAT'];$c['filter']['rule'][]=['tracker'=>101,'descr'=>'student rule'];$c['cron']['item'][]=['command'=>'/usr/bin/logger keep-student'];$c['system']['group'][0]['member'][]=(string)$m['users']['key']['uid'];$h->putConfig($c);
$fake=$h->root.'/root/ccdc-lab-'.$m['token'].'-credentials.txt';file_put_contents($fake,"student notes - keep these\n");
$home=$h->root.'/home/'.$m['users']['key']['name'];file_put_contents($home.'/student-notes.txt','keep home work');
[$r,$out,$e]=cli($h,'reset','--apply');check($r===0&&!$e,'selective reset succeeds');
$c=$h->readConfig();check($c['system']['user']===$before['system']['user']&&$c['system']['student_setting']==='keep','reset preserves original users and added student setting');
check(count($c['nat']['rule'])===2&&end($c['nat']['rule'])['descr']==='student NAT','reset preserves student NAT');
check(count($c['filter']['rule'])===2&&$c['filter']['rule'][0]['tracker']===100&&$c['filter']['rule'][1]['tracker']===101,'reset preserves baseline and student rules');
check(count($c['cron']['item'])===2&&!str_contains(json_encode($c['cron']),$m['tag']),'reset preserves baseline and student cron');
check($c['system']['group']===$before['system']['group'],'reset removes only generated UID group memberships');
check((string)$c['system']['nextuid']==='2001','nextuid restored when no later allocation occurred');
check(count($h->os)===2&&file_get_contents($h->root.'/home/ops/.ssh/authorized_keys')==='existing unmanaged key','OS cleanup preserves original accounts and unmanaged keys');
$ret=$h->root.'/conf/ccdc-practice/'.$m['run'].'/retained';$saved=glob($ret.'/credentials-*.saved');check(count($saved)===1&&file_get_contents($saved[0])==="student notes - keep these\n",'changed artifact file retained byte-for-byte');
check(file_get_contents($ret.'/home-'.$m['users']['key']['name'].'/student-notes.txt')==='keep home work','account home and student work retained');
[$r,$out,$e]=cli($h,'verify-reset');check($r===0&&!$e&&str_contains($out,'0/8'),'reset verifies all eight live categories absent');
[$r,,$e]=cli($h,'reset','--apply');check($r===0&&!$e,'repeated reset is idempotent');
$m2=seeded($h);check($m2['token']!==$m['token'],'new exercise after reset uses fresh resource names');cli($h,'reset','--apply');

$h=fixture();$m=seeded($h);$c=$h->readConfig();$c['system']['nextuid']=4000;$h->putConfig($c);cli($h,'reset','--apply');check($h->readConfig()['system']['nextuid']===4000,'later UID allocator changes preserved');
$h=fixture();$m=seeded($h);$h->processes=[$m['users']['key']['uid']];$digest=$h->digest();[,,$e]=cli($h,'reset','--apply');check($e!==null&&$h->digest()===$digest&&count($h->os)===4,'reset refuses running seeded-account processes before mutations');
$h=fixture();$m=seeded($h);$c=$h->readConfig();$c['system']['user'][2]['uid']='5000';$h->putConfig($c);$digest=$h->digest();[,,$e]=cli($h,'reset','--apply');check($e!==null&&$h->digest()===$digest,'config username/UID identity conflict refused');
$h=fixture();$m=seeded($h);$h->os[$m['users']['key']['name']]['uid']=5001;$digest=$h->digest();[,,$e]=cli($h,'reset','--apply');check($e!==null&&$h->digest()===$digest,'OS username/UID identity conflict refused');
$h=fixture();$m=seeded($h);$c=$h->readConfig();$c['filter']['rule'][0]['descr']='unrelated replacement';$h->putConfig($c);$digest=$h->digest();[,,$e]=cli($h,'reset','--apply');check($e!==null&&$h->digest()===$digest,'reused rule tracker without lab label refused');
$h=fixture();$h->failReload=1;[$r,,$e]=cli($h,'seed','--interface','lan','--apply','--lab-confirmed','--console-ready');check($r===1&&!$e&&manifestOf($h)['phase']==='reset'&&count($h->os)===2,'reload failure rolls back recorded artifacts selectively');
$h=fixture();$h->partialSave=true;[$r,,$e]=cli($h,'seed','--interface','lan','--apply','--lab-confirmed','--console-ready');check($r===1&&!$e&&manifestOf($h)['phase']==='reset'&&count($h->readConfig()['system']['user'])===2,'failure after config commit is recoverable');
$h=fixture();$h->failAdd=true;[$r,,$e]=cli($h,'seed','--interface','lan','--apply','--lab-confirmed','--console-ready');check($r===1&&!$e&&manifestOf($h)['phase']==='reset','account generator failure triggers selective rollback');
$h=fixture();$m=seeded($h);$h->failReload=1;[$r,,$e]=cli($h,'reset','--apply');check($e!==null&&manifestOf($h)['phase']==='reset-required','failed reset records recoverable phase');[$r,,$e]=cli($h,'reset','--apply');check($r===0&&!$e&&manifestOf($h)['phase']==='reset','retry completes interrupted reset');
$h=fixture();$m=seeded($h);$file=$h->root.'/www/ccdc-lab-'.$m['token'].'.txt';unlink($file);$outside=$h->root.'/keep.txt';file_put_contents($outside,'do not change');symlink($outside,$file);[$r,,$e]=cli($h,'reset','--apply');check($r===0&&!$e&&file_get_contents($outside)==='do not change'&&!is_link($file),'owned-path symlink is unlinked without following its target');
$h=fixture();mkdir($h->root.'/conf/ccdc-practice',0755);[,,$e]=cli($h,'status');check($e!==null,'insecure manifest directory refused');
$h=fixture();$m=seeded($h);$dir=$h->root.'/www';rename($dir,$h->root.'/www-original');symlink($h->root.'/www-original',$dir);$digest=$h->digest();[,,$e]=cli($h,'reset','--apply');check($e!==null&&$h->digest()===$digest,'changed parent symlink refused before reset mutation');
$h=fixture();[$r,,$e]=cli($h,'verify-reset');check($r===1&&!$e,'verify-reset refuses to claim a clean run without a manifest');
$h=fixture();$h->race=true;[$r,,$e]=cli($h,'seed','--interface','lan','--apply','--lab-confirmed','--console-ready');check($r===1&&!$e&&$h->readConfig()['system']['concurrent']==='keep','concurrent config change is preserved during failed seed cleanup');
$h=fixture();$h->silentReload=true;[$r,,$e]=cli($h,'seed','--interface','lan','--apply','--lab-confirmed','--console-ready');check($r===1&&!$e&&manifestOf($h)['phase']==='reset','silent native reload failure detected and cleaned up');
$h=fixture();$h->failSave=true;[$r,,$e]=cli($h,'seed','--interface','lan','--apply','--lab-confirmed','--console-ready');check($r===1&&!$e&&manifestOf($h)['phase']==='reset-required','persistent write failure leaves an explicit recovery journal');$h->failSave=false;[$r,,$e]=cli($h,'reset','--apply');check($r===0&&!$e,'reset recovers after native writer becomes available');
$h=fixture();$m=seeded($h);$p=$h->root.'/conf/ccdc-practice/'.$m['run'].'/manifest.json';$m['phase']='config-writing';file_put_contents($p,json_encode($m));[$r,,$e]=cli($h,'reset','--apply');check($r===0&&!$e&&manifestOf($h)['phase']==='reset','journal from interrupted config-writing phase resets');
$h=fixture();$m=seeded($h);$p=$h->root.'/conf/ccdc-practice/'.$m['run'].'/manifest.json';$m['nextuid_before']=['bad'=>'shape'];file_put_contents($p,json_encode($m));$digest=$h->digest();[,,$e]=cli($h,'reset','--apply');check($e!==null&&$h->digest()===$digest,'malformed allocator metadata refused before mutation');
$h=fixture();$m=seeded($h);$name=$m['users']['key']['name'];rename($h->root.'/home/'.$name,$h->root.'/home/'.$name.'-moved');$h->os[$name]['dir']=$h->root.'/missing-home';$digest=$h->digest();[,,$e]=cli($h,'reset','--apply');check($e!==null&&$h->digest()===$digest,'changed missing account home refused even when neither path resolves');
echo "{$checks} controller assertions passed; native pfSense/FreeBSD integration is not exercised.\n";
