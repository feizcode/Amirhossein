const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),{execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),out=path.join(root,'outputs'),stamp=new Date().toISOString().replace(/[:.]/g,'-'),stage=path.join(out,'release-'+stamp),deploy=path.join(stage,'deploy'),source=path.join(stage,'source');
if(!fs.existsSync(path.join(root,'vendor/autoload.php'))||!fs.existsSync(path.join(root,'public/assets/build/.vite/manifest.json'))||!fs.existsSync(path.join(root,'database/001-initial.sql')))throw Error('Run composer install, Vite build and export-sql first.');
fs.mkdirSync(stage,{recursive:true});
function copy(from,to){fs.mkdirSync(path.dirname(to),{recursive:true});fs.cpSync(from,to,{recursive:true});}
const privateRoot=path.join(deploy,'amir-app'),publicRoot=path.join(deploy,'public_html/amir');
for(const part of['src','vendor','templates','database','composer.json','composer.lock'])copy(path.join(root,part),path.join(privateRoot,part));
copy(path.join(root,'config/example.php'),path.join(privateRoot,'config/example.php'));
for(const file of['restore.php','backup-to-sql.php','configure.php'])copy(path.join(root,'bin',file),path.join(privateRoot,'bin',file));
copy(path.join(root,'public/assets/build/.vite/manifest.json'),path.join(privateRoot,'resources/manifest.json'));
for(const dir of['sessions','backups','logs','cache'])fs.mkdirSync(path.join(privateRoot,'storage',dir),{recursive:true});
for(const file of['index.php','.htaccess','favicon.svg'])copy(path.join(root,'public',file),path.join(publicRoot,file));
copy(path.join(root,'public/assets/build/assets'),path.join(publicRoot,'assets/build/assets'));
copy(path.join(root,'docs'),path.join(privateRoot,'docs'));copy(path.join(root,'licenses'),path.join(privateRoot,'licenses'));
for(const file of['src','templates','resources','database','bin','tests','docs','licenses','README.md','composer.json','composer.lock','package.json','pnpm-lock.yaml','pnpm-workspace.yaml','vite.config.js','.gitignore'])copy(path.join(root,file),path.join(source,file));
copy(path.join(root,'config/example.php'),path.join(source,'config/example.php'));
for(const file of['index.php','router.php','.htaccess','favicon.svg'])copy(path.join(root,'public',file),path.join(source,'public',file));
fs.mkdirSync(path.join(source,'storage'),{recursive:true});fs.writeFileSync(path.join(source,'storage/.gitkeep'),'');
const php=process.env.PHP_BIN||(fs.existsSync(path.join(root,'work/tools/php/php.exe'))?path.join(root,'work/tools/php/php.exe'):'php'),artifacts=[];
for(const[name,dir]of[['kashf-deploy',deploy],['kashf-source',source]]){const file=path.join(out,name+'-'+stamp+'.zip');execFileSync(php,[path.join(root,'bin/zip.php'),dir,file],{stdio:'inherit'});artifacts.push({file:path.basename(file),bytes:fs.statSync(file).size,sha256:crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex')});}
fs.writeFileSync(path.join(out,'release-manifest.json'),JSON.stringify({version:'1.0.0',built_at:new Date().toISOString(),deployed:false,artifacts},null,2));
console.log(JSON.stringify(artifacts,null,2));
