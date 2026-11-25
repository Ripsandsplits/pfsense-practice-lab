// Development test only. In-memory fixtures; no connection to a firewall.
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
import fs from 'node:fs';
const php=new PHP(await loadNodeRuntime('8.3',{emscriptenOptions:{processId:502}}));
for(const dir of ['/work/lib','/work/tests'])php.mkdirTree(dir);
for(const file of ['lib/lab.php','tests/controller-test.php'])php.writeFile('/work/'+file,fs.readFileSync(new URL('../'+file,import.meta.url)));
let r;
try{r=await php.run({scriptPath:'/work/tests/controller-test.php'});}catch(e){if(!e.response)throw e;r=e.response;}

console.log(r.text);console.error(r.errors);process.exit(r.exitCode);
