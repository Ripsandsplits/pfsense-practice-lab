// Native platform guards/APIs/commands are mocked in a virtual COPY of the source.
// The shipped production source is never modified by this harness.
// Development test only. In-memory fixtures; no connection to a firewall.
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
import fs from 'node:fs';import assert from 'node:assert/strict';
const php=new PHP(await loadNodeRuntime('8.3',{emscriptenOptions:{processId:503}}));
for(const dir of ['/work/lib','/etc/inc','/conf','/root','/usr/local/etc/rc.d','/usr/local/www','/home','/usr/sbin','/usr/bin','/bin','/sbin'])php.mkdirTree(dir);
php.writeFile('/work/lib/lab.php',fs.readFileSync(new URL('../lib/lab.php',import.meta.url)));
let source=fs.readFileSync(new URL('../lib/native.php',import.meta.url),'utf8');
source=source.replaceAll("PHP_SAPI !== 'cli'",'false').replaceAll("php_uname('s')","'FreeBSD'").replaceAll("!function_exists('posix_geteuid')",'false').replaceAll('posix_geteuid()','0').replaceAll('posix_getpwnam','mock_getpwnam').replaceAll('posix_getpwuid','mock_getpwuid').replace('function lab_exec(','function native_lab_exec_unexecuted(');
php.writeFile('/work/lib/native.php',source);
const inc=`<?php
$config=json_decode(file_get_contents('/conf/config.xml'),true);
function fixture(){return json_decode(file_get_contents('/fixture.json'),true);}
function update_fixture($x){file_put_contents('/fixture.json',json_encode($x));}
function config_get_path($p,$d=null){global $config;return lab_get($config,explode('/',$p),$d);}
function parse_config($fresh=false){return json_decode(file_get_contents('/conf/config.xml'),true);}
function write_config($why=''){global $config;file_put_contents('/conf/config.xml',json_encode($config));return true;}
function mock_getpwnam($name){$f=fixture();return $f['accounts'][$name]??false;}
function mock_getpwuid($uid){foreach(fixture()['accounts']as$u){if($u['uid']==$uid)return $u;}return false;}
function local_user_set($u){
 if($u['uid']==0||!preg_match('/^ccl[ak]_[a-f0-9]{10}$/',$u['name']))throw new RuntimeException('existing user touched');
 $f=fixture();$f['accounts'][$u['name']]=['name'=>$u['name'],'uid'=>(int)$u['uid'],'dir'=>'/home/'.$u['name']];$f['user_calls'][]=$u['name'];update_fixture($f);
 $home='/home/'.$u['name'];mkdir($home,0700);if($u['authorizedkeys']!==''){mkdir($home.'/.ssh',0700);file_put_contents($home.'/.ssh/authorized_keys',base64_decode($u['authorizedkeys']));}
}
function configure_cron(){global $config;$lines=[];foreach($config['cron']['item']??[]as$x)$lines[]=$x['command'];file_put_contents('/etc/crontab',implode("\n",$lines));}
function filter_configure_sync($states=true){if($states!==false)throw new RuntimeException('state flush requested');global $config;$lines=[];foreach($config['filter']['rule']??[]as$r)$lines[]=($r['descr']??'').' ridentifier '.($r['tracker']??'');file_put_contents('/live-rules',implode("\n",$lines));return 0;}
function lab_exec($args){
 $f=fixture();$f['commands'][]=$args;update_fixture($f);
 switch($args[0]){
  case '/usr/bin/sockstat':return "USER COMMAND PID FD PROTO LOCAL-ADDRESS FOREIGN-ADDRESS\n".(!empty($f['busy'])?"root test 1 3 tcp4 127.0.0.1:46290 *:*\n":'');
  case '/bin/ps':return " 0 1\n 2000 100\n";
  case '/sbin/pfctl':return file_get_contents('/live-rules');
  case '/usr/sbin/pw':if($args[1]!=='userdel'||$args[2]!=='-n'||count($args)!==4)throw new RuntimeException('unexpected recursive user deletion');unset($f['accounts'][$args[3]]);update_fixture($f);return '';
  case '/usr/bin/ssh-keygen':$file=$args[count($args)-1];$comment=$args[array_search('-C',$args)+1];file_put_contents($file,'PRIVATE TEMPORARY FIXTURE');file_put_contents($file.'.pub','ssh-ed25519 '.base64_encode(pack('N',11).'ssh-ed25519'.pack('N',32).str_repeat('P',32)).' '.$comment."\n");return '';
 }throw new RuntimeException('unexpected command '.json_encode($args));
}
`;
php.writeFile('/etc/inc/config.inc',inc);
for(const f of ['globals.inc','functions.inc','auth.inc','services.inc','filter.inc'])php.writeFile('/etc/inc/'+f,'<?php');
for(const f of ['/usr/sbin/pw','/usr/bin/ssh-keygen','/usr/bin/sockstat','/bin/ps','/sbin/pfctl','/usr/bin/false','/usr/bin/logger'])php.writeFile(f,'fixture');
let init=await php.run({code:`<?php foreach(['/conf','/root','/usr/local/etc/rc.d','/usr/local/www','/home']as$d)chmod($d,0755);foreach(['/usr/sbin/pw','/usr/bin/ssh-keygen','/usr/bin/sockstat','/bin/ps','/sbin/pfctl','/usr/bin/false','/usr/bin/logger']as$p)chmod($p,0700);`});assert.equal(init.exitCode,0);
const baseline={system:{nextuid:'2001',user:[{name:'admin',uid:'0','bcrypt-hash':'KEEP',priv:['page-all']},{name:'ops',uid:'2000','bcrypt-hash':'KEEP_OPS',authorizedkeys:'existing'}],group:[],webgui:{protocol:'https'}},interfaces:{lan:{if:'vtnet1',enable:'',ipaddr:'192.0.2.1'}},filter:{rule:[{tracker:100,descr:'business'}]},cron:{item:[{command:'/usr/bin/true'}]},nat:{rule:[{descr:'business NAT'}]}};
php.writeFile('/conf/config.xml',JSON.stringify(baseline));php.writeFile('/etc/master.passwd','SECRET EXISTING HASH REFERENCE');php.writeFile('/etc/crontab','/usr/bin/true');php.writeFile('/live-rules','business ridentifier 100');
php.writeFile('/fixture.json',JSON.stringify({accounts:{admin:{name:'admin',uid:0,dir:'/root'},ops:{name:'ops',uid:2000,dir:'/home/ops'}},commands:[],user_calls:[]}));
async function run(...args){const argv=JSON.stringify(['native.php',...args]);let r;try{r=await php.run({code:`<?php if(!defined('STDERR'))define('STDERR',fopen('php://stderr','w'));$argv=json_decode('${argv}',true);require '/work/lib/native.php';`});}catch(e){if(!e.response)throw e;r=e.response;}return{code:r.exitCode,out:r.text,err:r.errors};}
let n=0;const lines=[];function ok(v,m){assert.ok(v,m);lines.push('PASS '+m);n++;}
let r=await run('preflight','--interface','lan');ok(r.code===0,'native bootstrap/API discovery passes mocked platform: '+r.err+r.out);
r=await run('seed','--interface','lan');ok(r.code===0&&php.readFileAsText('/conf/config.xml')===JSON.stringify(baseline),'native seed preview does not change saved config');
r=await run('seed','--interface','lan','--apply','--lab-confirmed','--console-ready');ok(r.code===0,'native adapter seeds through mocked pfSense APIs: '+r.err+r.out);
const seeded=JSON.parse(php.readFileAsText('/conf/config.xml'));const f=JSON.parse(php.readFileAsText('/fixture.json'));
ok(f.user_calls.length===2&&seeded.system.user.length===4,'local_user_set called only for two generated accounts');
ok(JSON.stringify(seeded.system.user.slice(0,2))===JSON.stringify(baseline.system.user)&&JSON.stringify(seeded.nat)===JSON.stringify(baseline.nat),'native write preserves existing user/password/key and NAT data');
let query=await php.run({code:"<?php echo json_encode(glob('/tmp/ccdc-lab-key-*'));"});ok(query.text==='[]','temporary key pair is removed before seed completes');
r=await run('grade');ok(r.code===2&&r.out.includes('8/8'),'native runtime/config inventory observes all eight categories');
r=await run('reset','--apply');ok(r.code===0,'native adapter selectively resets through mocked APIs: '+r.err+r.out);
const reset=JSON.parse(php.readFileAsText('/conf/config.xml'));ok(JSON.stringify(reset.system.user)===JSON.stringify(baseline.system.user)&&reset.filter.rule.length===1&&reset.cron.item.length===1,'native reset preserves baseline users, rule and cron');
const commands=JSON.parse(php.readFileAsText('/fixture.json')).commands;const deletions=commands.filter(x=>x[0]==='/usr/sbin/pw');ok(deletions.length===2&&deletions.every(x=>x.length===4&&!x.includes('-r')),'OS deletion never requests recursive home deletion');
r=await run('verify-reset');ok(r.code===0&&r.out.includes('0/8'),'native adapter verifies live reset inventory');
r=await run('reset','--apply');ok(r.code===0,'native reset repeat succeeds');
lines.push(`${n} native adapter assertions passed with mocked platform, APIs, and commands; not real pfSense integration.`);
console.log(lines.join('\n'));process.exit(0);
