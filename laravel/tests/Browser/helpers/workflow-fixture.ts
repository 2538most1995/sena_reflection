import { spawn, spawnSync, type ChildProcess } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { access, mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const project = resolve(dirname(fileURLToPath(import.meta.url)), '../../..')
export const workflowBaseUrl = 'http://127.0.0.1:8777'
export const workflowCredentials = { user: 'workflow-e2e', password: 'WorkflowE2E2026!' }
export type WorkflowFixture = { seedLegacyRecord: () => string; close: () => Promise<void>; records: () => { id: string; level: string; subject: string; present: number; total: number; attendance: { present: boolean }[]; images: { id: string; file_path: string }[] }[] }

function php(source: string, environment: NodeJS.ProcessEnv) {
  const result = spawnSync('php', [], { cwd: project, env: environment, input: `<?php\n${source}`, encoding: 'utf8' })
  if (result.status !== 0) throw new Error(`Isolated fixture PHP failed: ${result.stderr || result.stdout}`)
  return result.stdout
}

function phpString(value: string) { return `'${value.replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'` }

async function waitForServer(url: string, process: ChildProcess) {
  const deadline = Date.now() + 20_000
  while (Date.now() < deadline) {
    if (process.exitCode !== null) throw new Error(`Fixture server stopped (${process.exitCode})`)
    try { if ((await fetch(url)).ok) return } catch {}
    await new Promise(resolve => setTimeout(resolve, 150))
  }
  throw new Error(`Fixture server did not become ready: ${url}`)
}

async function stop(process?: ChildProcess) {
  if (!process || process.exitCode !== null) return
  process.kill('SIGTERM')
  await Promise.race([new Promise<void>(resolve => process.once('exit', () => resolve())), new Promise<void>(resolve => setTimeout(resolve, 2_000))])
  if (process.exitCode === null) process.kill('SIGKILL')
}

/** This opt-in fixture owns its SQLite database, storage, test account and both loopback servers. */
export async function startWorkflowFixture(): Promise<WorkflowFixture> {
  try { await access(resolve(project, 'public/hot')); throw new Error('An existing Vite hot file is present; stop that development server before running the isolated workflow fixture.') } catch (error) {
    if ((error as NodeJS.ErrnoException).code !== 'ENOENT') throw error
  }
  const directory = await mkdtemp(resolve(tmpdir(), 'sena-workflow-e2e-'))
  const storage = resolve(directory, 'storage')
  for (const path of ['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs']) await mkdir(resolve(storage, path), { recursive: true })
  const environment = {
    ...process.env, APP_ENV: 'local', APP_DEBUG: 'true', APP_URL: workflowBaseUrl,
    APP_KEY: `base64:${randomBytes(32).toString('base64')}`, DB_CONNECTION: 'sqlite', DB_DATABASE: resolve(directory, 'database.sqlite'), DB_URL: '',
    SESSION_DRIVER: 'file', SESSION_COOKIE: 'sena_workflow_e2e', SESSION_DOMAIN: '', SESSION_SECURE_COOKIE: 'false',
    CACHE_STORE: 'file', QUEUE_CONNECTION: 'sync', LOG_CHANNEL: 'single', SENA_E2E_STORAGE: storage,
    SENA_STUDENT_API_URL: '', SENA_STUDENT_API_KEY: '', ASSET_URL: '', VITE_APP_BASE_PATH: '',
  }
  let server: ChildProcess | undefined
  let vite: ChildProcess | undefined
  const bootstrap = `require getcwd().'/vendor/autoload.php'; $app=require getcwd().'/bootstrap/app.php'; $app->useStoragePath(getenv('SENA_E2E_STORAGE')); $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();`
  const close = async () => {
    await stop(vite)
    await stop(server)
    for (const name of ['hot', 'fonts-manifest.dev.json']) {
      const file = resolve(project, 'public', name)
      try { if ((await readFile(file, 'utf8')).includes(':5187')) await rm(file) } catch {}
    }
    await rm(directory, { recursive: true, force: true })
  }
  try {
    await writeFile(environment.DB_DATABASE, '')
    php(`${bootstrap}
      if(config('database.default')!=='sqlite'||config('database.connections.sqlite.database')!==getenv('DB_DATABASE'))throw new RuntimeException('Refusing non-fixture database.');
      $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->call('migrate',['--force'=>true]);
      App\\Models\\Tambon::create(['id'=>'workflow-tambon','name'=>'ตำบลทดสอบขั้นตอนบันทึก']);
      App\\Models\\User::create(['id'=>'workflow-district','username'=>'workflow-district','name'=>'ผู้ดูแลทดสอบ','password'=>Illuminate\\Support\\Facades\\Hash::make('WorkflowE2E2026!'),'role'=>'district','tambon'=>null]);
      App\\Models\\User::create(['id'=>'workflow-user','username'=>'workflow-e2e','name'=>'ครูทดสอบขั้นตอนบันทึก','password'=>Illuminate\\Support\\Facades\\Hash::make('WorkflowE2E2026!'),'role'=>'tambon','tambon'=>'workflow-tambon']);
      $groups=[];
      foreach(['1'=>'ประถมศึกษา','2'=>'มัธยมศึกษาตอนต้น','3'=>'มัธยมศึกษาตอนปลาย']as$code=>$name){
        $group='WORKFLOW-'.$code;
        $groups[]=['group_code'=>$group,'class_name'=>'กลุ่มทดสอบ '.$name,'level_code'=>(string)$code,'student_count'=>2];
        $students=[];
        foreach([1,2]as$index){$students[]=['student_id'=>'WF-'.$code.'-'.$index,'full_name'=>'นักศึกษาทดสอบ '.$code.'-'.$index,'group_code'=>$group,'class_name'=>'กลุ่มทดสอบ '.$name];}
        Illuminate\\Support\\Facades\\Cache::put('record-create:students:v3:'.hash('sha256',implode('|',['workflow-user','workflow-tambon',$group,$code])),['items'=>$students,'meta'=>['source'=>'fixture','stale'=>false],'error'=>null,'error_code'=>null],3600);
      }
      Illuminate\\Support\\Facades\\Cache::put('record-options:groups:v3:'.hash('sha256','workflow-user|workflow-tambon'),['items'=>$groups,'meta'=>['source'=>'fixture','stale'=>false],'error'=>null,'error_code'=>null],3600);
    `, environment)
    const router = `<?php
      $path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)??'/');
      $public=${phpString(resolve(project, 'public'))};
      $file=realpath($public.$path);
      if($file&&str_starts_with($file,$public.DIRECTORY_SEPARATOR)&&is_file($file))return false;
      require ${phpString(resolve(project, 'vendor/autoload.php'))};
      $app=require ${phpString(resolve(project, 'bootstrap/app.php'))};
      $app->useStoragePath(getenv('SENA_E2E_STORAGE'));
      $app->handleRequest(Illuminate\\Http\\Request::capture());
    `
    const routerPath = resolve(directory, 'router.php')
    await writeFile(routerPath, router)
    server = spawn('php', ['-S', '127.0.0.1:8777', '-t', 'public', routerPath], { cwd: project, env: environment, stdio: 'ignore' })
    vite = spawn(process.execPath, ['node_modules/vite/bin/vite.js', '--host', '127.0.0.1', '--port', '5187', '--strictPort'], { cwd: project, env: environment, stdio: 'ignore' })
    await Promise.all([waitForServer(`${workflowBaseUrl}/login`, server), waitForServer('http://127.0.0.1:5187/@vite/client', vite)])
    return {
      close,
      seedLegacyRecord: () => php(`${bootstrap}
        Illuminate\\Support\\Facades\\Schema::drop('record_images');
        Illuminate\\Support\\Facades\\Schema::create('record_images',function(Illuminate\\Database\\Schema\\Blueprint $t){$t->string('id',20)->primary();$t->string('record_id',20)->index();$t->string('file_path');$t->timestamp('created_at')->nullable();});
        $r=App\\Models\\TeachingRecord::create(['id'=>'workflow-legacy-3','tambon'=>'workflow-tambon','week'=>16,'date'=>'2026-09-30','semester'=>'1','year'=>'2569','level'=>'มัธยมศึกษาตอนปลาย','class_group_code'=>'WORKFLOW-3','class_name'=>'กลุ่มทดสอบ มัธยมศึกษาตอนปลาย','subject'=>'วิชาเดิมทดสอบ','result'=>'ผลการสอนเดิม','total'=>26,'present'=>7,'created_by'=>'workflow-user']);
        foreach(range(1,26)as$i)App\\Models\\RecordAttendance::create(['record_id'=>$r->id,'student_id'=>str_pad((string)$i,10,'0',STR_PAD_LEFT),'full_name'=>' ผู้เรียนเดิม '.$i.' ','group_code'=>' WORKFLOW-3 ','present'=>$i<=7?1:0,'learning_method'=>$i<=7?' ':null]);
        foreach(range(1,4)as$i){$p='records/original-'.$i.'.png';Illuminate\\Support\\Facades\\Storage::disk('public')->put($p,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jhx0AAAAASUVORK5CYII='));App\\Models\\RecordImage::create(['record_id'=>$r->id,'file_path'=>'storage/'.$p]);}
        echo $r->id;
      `, environment).trim(),
      records: () => JSON.parse(php(`${bootstrap} echo App\\Models\\TeachingRecord::query()->with(['attendance','images'])->orderBy('level')->get(['id','level','subject','present','total'])->toJson();`, environment)),
    }
  } catch (error) { await close(); throw error }
}
