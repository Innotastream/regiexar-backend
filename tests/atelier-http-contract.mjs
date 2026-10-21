import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { spawn, spawnSync } from 'node:child_process';
import { mkdtemp, readdir, readFile, rm } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Opt-in only: never infer a database or credential from a project configuration.
if (process.env.XAR_TEST_MYSQL_ALLOW_LOCAL !== '1') {
  console.log('Atelier HTTP/MySQL contract skipped: explicit disposable SQL opt-in required.');
  process.exit(0);
}
assert.match(process.env.XAR_TEST_MYSQL_DSN ?? '', /^mysql:host=127\.0\.0\.1;port=[1-9][0-9]{3,4};charset=utf8mb4$/);
const php = process.env.XAR_TEST_PHP_BINARY ?? 'php';
const phpArgs = JSON.parse(process.env.XAR_TEST_PHP_ARGUMENTS ?? '[]');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const temporary = await mkdtemp(path.join(os.tmpdir(), 'atelier-http-fixture-'));
const schema = `atelier_contract_${randomBytes(8).toString('hex')}`;
const port = Number(process.env.XAR_ATELIER_TEST_HTTP_PORT ?? 33379);
const env = { ...process.env, XAR_ATELIER_TEST_SCHEMA: schema, XAR_ATELIER_TEST_PRIVATE_DIRECTORY: temporary };
const command = action => {
  const result = spawnSync(php, [...phpArgs, 'tests/atelier-test-bootstrap.php', action], {cwd: root, env, encoding:'utf8'});
  assert.equal(result.status, 0, result.stdout + result.stderr);
  return result.stdout;
};
let server;
let checks = 0;
function check(condition, message) { assert.ok(condition, message); checks++; console.log(`PASS ${message}`); }
const base = `http://127.0.0.1:${port}/api/v1/image-studio/atelier`;
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jL1sAAAAASUVORK5CYII=', 'base64');
const requestId = () => randomBytes(16).toString('hex');
async function request(route, {cookie='', method='GET', body, origin} = {}) {
  const headers = {}; if(cookie) headers.Cookie=cookie;
  if(origin) headers.Origin=origin;
  if(body && !(body instanceof FormData)) {headers['Content-Type']='application/json';body=JSON.stringify(body);}
  const response = await fetch(base+route,{method,headers,body});
  const contentType=response.headers.get('content-type')??'';
  const value=contentType.includes('application/json')?await response.json():Buffer.from(await response.arrayBuffer());
  return {status:response.status,value,cookie:response.headers.get('set-cookie')?.split(';')[0]??'',headers:response.headers};
}
function multipart(metadata, files=[]) {
  const data=new FormData(); if(metadata!==null)data.set('metadata',JSON.stringify(metadata));
  for(const file of files)data.append('files[]',new Blob([file.bytes??png],{type:file.type??'image/png'}),file.name??'reference.png');
  return data;
}
try {
  check(JSON.parse(command('initialize')).schema===25,'Migration additive 25 et seconde exécution sans erreur.');
  server=spawn(php,[...phpArgs,'-d','upload_max_filesize=16M','-d','post_max_size=42M','-d','max_file_uploads=6','-S',`127.0.0.1:${port}`,'tests/atelier-http-router.php'],{cwd:root,env,stdio:['ignore','ignore','pipe'],windowsHide:true});
  let serverLog='';server.stderr.on('data',chunk=>{serverLog+=chunk;});
  let ready=false;
  for(let i=0;i<30;i++){try{if((await request('/auth/setup')).status===200){ready=true;break;}}catch{} await new Promise(r=>setTimeout(r,100));}
  assert.ok(ready,serverLog);
  check((await request('/auth/me')).status===401,'Une session Régie absente ne devient pas une session Atelier.');
  check((await request('/fixture/regie-auth',{method:'POST',body:{username:'',password:''}})).status===403,'Le helper Régie refuse aussi les identifiants invalides qui coïncident avec un compte canari factice.');
  check((await request('/fixture/regie-auth',{method:'POST',body:{username:'invalid',password:'xxxxxxxxxx'}})).status===200,'Le correctif Régie conserve la connexion normale du compte canari avec ses vrais identifiants.');
  const setup={username:'AtelierOwner',displayName:'Propriétaire Atelier fictif',password:'Fixture-Owner-Password-2026'};
  check((await request('/auth/setup',{method:'POST',body:setup})).status===401,'Un visiteur anonyme ne peut pas créer le premier administrateur.');
  const studioCookie=`xar_studio_session=${'S'.repeat(43)}`;
  const initialized=await request('/auth/setup',{cookie:studioCookie,method:'POST',body:setup});
  check(initialized.status===200&&initialized.value.account.isAdministrator&&initialized.cookie.startsWith('xar_atelier_session='),'L’initialisation exige la preuve owner Régie et émet une session Atelier distincte.');
  const owner=initialized.cookie;
  check((await request('/auth/setup',{cookie:studioCookie,method:'POST',body:setup})).status===409,'Le premier propriétaire ne peut pas être remplacé par une seconde initialisation.');
  check((await request('/auth/me',{cookie:studioCookie})).status===401,'Le cookie Studio ne donne pas accès aux comptes Atelier.');
  const previousRegieSessions=command('invariants');
  check((await request('/capabilities',{cookie:owner})).value.canOpenLocalEngine===true,'Le propriétaire Atelier possède les commandes locales explicites.');
  check((await request('/users',{cookie:owner,method:'POST',origin:'https://evil.invalid',body:{username:'refused',password:'Fixture-Password-2026'}})).status===403,'Une écriture avec origine étrangère est refusée.');
  const makeUser=async name=>{
    const account=await request('/users',{cookie:owner,method:'POST',body:{username:name,displayName:name,password:'Fixture-User-Password-2026'}});
    assert.equal(account.status,201,JSON.stringify(account));
    const login=await request('/auth/login',{method:'POST',body:{username:name,password:'Fixture-User-Password-2026'}});
    assert.equal(login.status,200,JSON.stringify(login));
    return {id:account.value.account.id,cookie:login.cookie};
  };
  const alice=await makeUser('AliceFixture'); const bob=await makeUser('BobFixture');
  const dummyAccount=await request('/users',{cookie:owner,method:'POST',body:{username:'invalid',password:'xxxxxxxxxx'}});
  assert.equal(dummyAccount.status,201,JSON.stringify(dummyAccount));
  check((await request('/auth/login',{method:'POST',body:{username:'',password:''}})).status===403,'Une entrée invalide ne peut pas ouvrir le compte correspondant aux valeurs de vérification factices.');
  check((await request('/auth/login',{method:'POST',body:{username:'invalid',password:'xxxxxxxxxx'}})).status===200,'Le nom de compte « invalid » reste utilisable avec son vrai mot de passe.');
  check(command('invariants')===previousRegieSessions,'Les créations et connexions Atelier conservent les sessions Régie et Studio octet pour octet.');
  check(!(await request('/capabilities',{cookie:alice.cookie})).value.canManageLocalTickets,'Un utilisateur ordinaire ne peut pas piloter le moteur local.');
  check((await request('/users',{cookie:alice.cookie})).status===403,'La gestion des comptes est réservée au propriétaire Atelier.');
  const metadata={sourcePrompt:'Une forêt calme et un pont de pierre, illustration non sensible.',clientRequestId:requestId()};
  const created=await request('/local-tickets',{cookie:alice.cookie,method:'POST',body:multipart(metadata,[{}])});
  assert.equal(created.status,201,JSON.stringify(created)); const ticket=created.value.ticket;
  check(ticket.revision===1&&ticket.status==='waiting'&&ticket.references.length===1,'Création réelle multipart : description, référence privée et révision initiale.');
  check((await request(`/local-tickets/${ticket.id}`,{cookie:bob.cookie})).status===404,'Un second utilisateur ne voit ni le ticket ni son existence.');
  check((await request('/local-tickets',{cookie:bob.cookie})).value.tickets.length===0,'La liste utilisateur est limitée à ses propres demandes.');
  check((await request('/local-tickets',{cookie:owner})).value.tickets.length===1,'Le propriétaire voit la file complète.');
  const attachment=ticket.references[0];
  check((await request(attachment.url.replace('/api/v1/image-studio/atelier',''),{cookie:bob.cookie})).status===404,'L’URL de pièce jointe ne contourne pas les droits du ticket.');
  const image=await request(attachment.url.replace('/api/v1/image-studio/atelier',''),{cookie:alice.cookie});
  check(image.status===200&&image.value.equals(png)&&image.headers.get('cache-control').includes('no-store'),'L’auteur récupère les octets réels avec cache privé désactivé.');
  const duplicate=await request('/local-tickets',{cookie:alice.cookie,method:'POST',body:multipart(metadata,[{}])});
  check(duplicate.status===200&&duplicate.value.deduplicated&&duplicate.value.ticket.id===ticket.id,'Une reprise réseau de création ne duplique ni ticket ni fichier.');
  const mismatch=await request('/local-tickets',{cookie:alice.cookie,method:'POST',body:multipart({...metadata,sourcePrompt:'Autre demande.'},[{}])});
  check(mismatch.status===409,'Réutiliser la clé avec une autre demande est refusé.');
  check((await request(`/local-tickets/${ticket.id}/status`,{cookie:alice.cookie,method:'POST',body:{expectedRevision:1,status:'processing',note:'',clientRequestId:requestId()}})).status===409,'L’auteur ne peut pas s’attribuer un traitement administrateur.');
  const claimed=await request(`/local-tickets/${ticket.id}/status`,{cookie:owner,method:'POST',body:{expectedRevision:1,status:'processing',note:'Essai fictif',clientRequestId:requestId()}});
  check(claimed.status===200&&claimed.value.ticket.revision===2,'Le propriétaire prend la demande avec une révision vérifiée.');
  check((await request(`/local-tickets/${ticket.id}/status`,{cookie:owner,method:'POST',body:{expectedRevision:1,status:'rejected',note:'',clientRequestId:requestId()}})).value.code==='revision_conflict','Une réponse tardive ne remplace pas l’état courant.');
  const deliverMeta={expectedRevision:2,clientRequestId:requestId(),reply:'Résultat fictif terminé.'};
  check((await request(`/local-tickets/${ticket.id}/deliver`,{cookie:alice.cookie,method:'POST',body:multipart(deliverMeta,[{}])})).status===403,'Seul le propriétaire peut joindre le résultat du moteur local.');
  const delivered=await request(`/local-tickets/${ticket.id}/deliver`,{cookie:owner,method:'POST',body:multipart(deliverMeta,[{name:'resultat.png'}])});
  check(delivered.status===200&&delivered.value.ticket.status==='completed'&&delivered.value.ticket.resultAttachments.length===1&&delivered.value.ticket.revision===3,'La livraison conserve résultat, réponse et progression de révision.');
  const redelivered=await request(`/local-tickets/${ticket.id}/deliver`,{cookie:owner,method:'POST',body:multipart(deliverMeta,[{name:'resultat.png'}])});
  check(redelivered.status===200&&redelivered.value.deduplicated&&redelivered.value.ticket.resultAttachments.length===1,'Une livraison répétée reste idempotente même avec une ancienne révision.');
  const invalid=await request('/local-tickets',{cookie:alice.cookie,method:'POST',body:multipart({...metadata,clientRequestId:requestId()},[{name:'fake.png',bytes:Buffer.from('<svg><script/></svg>')}])});
  check(invalid.status===415,'Un SVG déguisé en PNG est refusé par inspection du contenu.');
  const excessive=await request('/local-tickets',{cookie:alice.cookie,method:'POST',body:multipart({...metadata,clientRequestId:requestId()},Array.from({length:5},()=>({})))});
  check(excessive.status===400,'Cinq pièces jointes sont refusées pour les tickets limités à quatre.');
  const oversized=Buffer.alloc(15*1024*1024+1);png.copy(oversized);
  check((await request('/local-tickets',{cookie:alice.cookie,method:'POST',body:multipart({...metadata,clientRequestId:requestId()},[{bytes:oversized}])})).status===413,'Un fichier dépassant 15 Mo est refusé avant écriture persistante.');
  const batchFile=Buffer.alloc(13.5*1024*1024);png.copy(batchFile);
  check((await request('/local-tickets',{cookie:alice.cookie,method:'POST',body:multipart({...metadata,clientRequestId:requestId()},Array.from({length:3},()=>({bytes:batchFile})))})).status===413,'Un lot de fichiers autorisés individuellement dépassant 40 Mo est refusé.');
  const beforeRollback=await readdir(path.join(temporary,'media'));
  command('inject-failure');
  const interrupted=await request('/local-tickets',{cookie:alice.cookie,method:'POST',body:multipart({...metadata,clientRequestId:requestId()},[{name:'rollback.png'}])});
  command('remove-failure');
  check(interrupted.status===500&&interrupted.value.detail.includes('fixture_rollback_after_file_write')
    &&JSON.stringify(await readdir(path.join(temporary,'media')))===JSON.stringify(beforeRollback)
    &&(await request('/local-tickets',{cookie:alice.cookie})).value.tickets.length===1,
    'Une panne SQL après écriture du fichier annule ticket, messages, reçu et fichier ensemble.');
  const refs=await request('/references',{cookie:alice.cookie,method:'POST',body:multipart(null,[{name:'ingredient.png'}])});
  assert.equal(refs.status,201,JSON.stringify(refs)); const ref=refs.value.references[0];
  check(ref.kind==='upload'&&ref.mediaId.length===24,'Une référence locale GPT reçoit un identifiant de média serveur réutilisable.');
  check((await request(ref.imageUrl.replace('/api/v1/image-studio/atelier',''),{cookie:bob.cookie})).status===404,'Une référence GPT locale conserve ses droits Atelier.');
  check((await request(ref.imageUrl.replace('/api/v1/image-studio/atelier',''),{cookie:owner})).status===200,'Le propriétaire peut consulter une référence pour exécuter une demande.');
  check((await request(`/fixture/regie-other-media/${ref.mediaId}`)).status===404,'Un autre MJ Régie, même administrateur, ne peut pas lire une référence privée Atelier par la route générique.');
  check((await request(`/fixture/regie-owner-media/${ref.mediaId}`)).status===200,'Le worker Régie du propriétaire conserve l’accès aux références nécessaires au calcul.');
  check((await request(`/fixture/media-reference-count/${ref.mediaId}`)).value.references===1,'La rétention Régie considère un média Atelier comme référencé et le protège de la purge des orphelins.');
  check((await readdir(path.join(temporary,'media'))).length===3,'Les reprises et validations refusées ne laissent aucun fichier supplémentaire.');
  check(command('invariants')===previousRegieSessions,'Les envois et livraisons ne modifient aucune session Régie existante.');
  const revoked=await request(`/users/${alice.id}`,{cookie:owner,method:'PATCH',body:{revoked:true}});
  check(revoked.status===200&&(await request('/auth/me',{cookie:alice.cookie})).status===401,'La révocation invalide immédiatement la session Atelier concernée.');
  const revokedLogin=await request('/auth/login',{method:'POST',body:{username:'AliceFixture',password:'Fixture-User-Password-2026'}});
  assert.equal(revokedLogin.status,403,JSON.stringify(revokedLogin));
  check(true,'Un compte révoqué ne peut pas ouvrir une nouvelle session.');
  check((await request('/auth/password',{cookie:bob.cookie,method:'POST',body:{currentPassword:'Incorrect-fixture-password',newPassword:'Fixture-Changed-Password-2026'}})).status===403,'Un changement de mot de passe exige la connaissance du mot de passe actuel.');
  const changed=await request('/auth/password',{cookie:bob.cookie,method:'POST',body:{currentPassword:'Fixture-User-Password-2026',newPassword:'Fixture-Changed-Password-2026'}});
  check(changed.status===200&&changed.cookie!==bob.cookie&&(await request('/auth/me',{cookie:bob.cookie})).status===401
    &&(await request('/auth/me',{cookie:changed.cookie})).status===200,'Changer son mot de passe renouvelle le cookie Atelier et clôture son ancienne session.');
  check((await request('/auth/login',{method:'POST',body:{username:'BobFixture',password:'Fixture-User-Password-2026'}})).status===403,'L’ancien mot de passe ne permet plus de se reconnecter.');
  check((await request('/fixture/stale-issue-session',{cookie:changed.cookie})).status===401,'Une vérification de mot de passe dépassée par une nouvelle révision ne peut pas créer une session actualisée.');
  const bobAgain=await request('/auth/login',{method:'POST',body:{username:'BobFixture',password:'Fixture-Changed-Password-2026'}});
  assert.equal(bobAgain.status,200,JSON.stringify(bobAgain));
  check((await request('/fixture/stale-password-change',{cookie:bobAgain.cookie})).status===401,'Une ancienne session ne peut pas écraser une réinitialisation concurrente de mot de passe.');
  check((await request('/auth/login',{method:'POST',body:{username:'BobFixture',password:'Fixture-Reset-Winner-2026'}})).status===200,'Le mot de passe de la réinitialisation concurrente reste autoritatif.');
  console.log(JSON.stringify({ok:true,checks,sql:'MariaDB disposable loopback',http:'real PHP multipart'}));
} finally {
  if(server){server.kill();await new Promise(resolve=>{if(server.exitCode!==null)return resolve();server.once('exit',resolve);setTimeout(resolve,2000);});}
  try{command('cleanup');}finally{
    assert.ok(path.basename(temporary).startsWith('atelier-http-fixture-'));
    await rm(temporary,{recursive:true,force:true});
  }
}
