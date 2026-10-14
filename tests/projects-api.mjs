// Dedicated disposable test database only. Accounts are created with unique names.
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
const url=process.env.ZHILIAN_TEST_URL;
if(!url)throw new Error('Set ZHILIAN_TEST_URL to a PHP service using a dedicated test database.');
const suffix=crypto.randomBytes(5).toString('hex'), password=crypto.randomBytes(24).toString('hex');
let count=0;
function check(ok,label){assert.ok(ok,label);count++;console.log('PASS '+label);}
function client(){let cookie='';let project=null;return {setProject(id){project=id;},async request(path,method='GET',body,override){const pid=override===undefined?project:override;const res=await fetch(url+'/api/'+path,{method,headers:{'Content-Type':'application/json','X-Zhilian-Request':'1',...(cookie?{Cookie:cookie}:{}),...(pid?{'X-Project-Id':pid}:{})},...(body?{body:JSON.stringify(body)}:{})});const set=res.headers.get('set-cookie');if(set)cookie=set.split(';')[0];return{status:res.status,body:await res.json()};}};}
const alice=client(),bob=client(),carol=client(),guest=client();
const usernameA='alice_'+suffix,usernameB='bob_'+suffix,usernameC='carol_'+suffix;
let r=await guest.request('state');check(r.body.demo&&!r.body.entities&&!r.body.projects,'未登录不返回项目数据');
r=await alice.request('register','POST',{username:usernameA,name:'测试甲',password,password_confirmation:password,project_name:'甲的默认项目',with_demo:true});check(r.status===200,'自助注册并自动登录');
const pA=r.body.project.id,idA=r.body.user.id;alice.setProject(pA);
check(r.body.project.owner_id===idA&&r.body.project.role==='admin','注册用户拥有自己的默认项目');
r=await bob.request('register','POST',{username:usernameB,name:'测试乙',password,project_name:'乙的默认项目'});check(r.status===200,'已有用户后仍可自助注册');
const pB=r.body.project.id,idB=r.body.user.id;bob.setProject(pB);
await carol.request('register','POST',{username:usernameC,name:'测试丙',password,project_name:'丙的默认项目'});
let stateA=(await alice.request('state')).body,stateB=(await bob.request('state')).body;
check(stateA.entities.length===13&&stateA.relations.length===15,'示例数据仅加载到注册用户的项目');
check(stateB.entities.length===0&&stateB.relations.length===0&&stateB.projects.every(p=>p.id!==pA),'其他用户无法看到甲的项目');
check((await guest.request('register','POST',{username:usernameA,name:'重复',password,project_name:'重复'})).status===409,'重复账号被拒绝');
check((await guest.request('register','POST',{username:'short_'+suffix,name:'短密码',password:'123',project_name:'无效'})).status===400,'短密码注册被拒绝');
check((await guest.request('register','POST',{username:'confirm_'+suffix,name:'不匹配',password,password_confirmation:'mismatch',project_name:'无效'})).status===400,'后端校验两次密码一致');
for(const [label,patch] of [
  ['超过 80 字的账号',{username:'x'.repeat(81)}],
  ['超过 80 字的姓名',{name:'😀'.repeat(81)}],
  ['非字符串姓名',{name:[]}],
  ['超过加密长度上限的密码',{password:'x'.repeat(73)}],
  ['包含空字节的密码',{password:'password\u0000invalid'}],
  ['超过 80 字的项目名称',{project_name:'x'.repeat(81)}]
])check((await guest.request('register','POST',{username:'invalid_'+suffix,name:'无效输入',password,project_name:'无效',...patch})).status===400,label+'被明确拒绝');
for(const path of ['state','audit','export','users'])check((await bob.request(path,'GET',null,pA)).status===403,'跨项目读取 '+path+' 被拒绝');
const nA=stateA.entities[0];
check((await bob.request('entities/'+nA.id,'PUT',{...nA,name:'非法覆盖'})).status===404,'其他项目的对象编号不能用于修改');
check((await bob.request('entities','POST',{name:'非法创建',type:'系统'},pA)).status===403,'未加入项目不能新建对象');
r=await bob.request('entities','POST',{name:'乙的资源',type:'资源',owner:'测试乙'});check(r.status===200,'用户可以维护自己的项目');const nB=r.body.record;
check((await bob.request('relations','POST',{source:nB.id,target:nA.id,type:'跨项目关联'})).status===400,'关系两端必须属于同一项目');
r=await alice.request('projects','POST',{name:'甲的第二个项目',description:'独立记录'});check(r.status===200,'同一用户可以创建多个项目');const pA2=r.body.project.id;
check((await alice.request('state','GET',null,pA2)).body.entities.length===0,'新项目不混入原项目数据');
check((await bob.request('projects/'+pA2,'PUT',{name:'非法改名',description:''})).status===403,'其他用户无法修改项目资料');
check((await alice.request('projects/'+pA2,'PUT',{name:'甲的归档资料',description:'项目资料已修改'})).status===200,'创建者可以修改项目资料');
let projects=(await alice.request('projects')).body.projects;check(projects.filter(p=>p.owner_id===idA).length===2,'我的项目列表列出多个自建项目');
check((await alice.request('users','POST',{username:usernameB,role:'viewer'})).status===200,'项目管理员添加已注册用户为查看者');
check((await alice.request('users','POST',{username:'missing_'+suffix,role:'editor'})).status===400,'未注册账号不能被添加');
check((await bob.request('state','GET',null,pA)).body.entities.length===13,'受邀成员可读取当前协作项目');
check((await bob.request('state','GET',null,pA)).body.projects.every(p=>p.id!==pA2),'协作者不能看到创建者的其他项目');
check((await bob.request('entities','POST',{name:'无权写入',type:'系统'},pA)).status===403,'协作查看者不能写入');
check((await bob.request('export','GET',null,pA)).status===403,'协作查看者不能批量导出');
check((await alice.request('users/'+idB,'PUT',{role:'editor'})).status===200,'项目角色可独立升级为编辑者');
check((await bob.request('entities','POST',{name:'乙协作添加的对象',type:'设备'},pA)).status===200,'协作编辑者可以维护被授权项目');
check((await bob.request('users','GET',null,pA)).status===403,'编辑者不能管理项目成员');
check((await alice.request('users/'+idA,'PUT',{role:'viewer'})).status===400,'项目创建者保留管理员权限');
check((await alice.request('users/'+idB,'PUT',{password:'change-not-allowed'})).status===403,'项目管理员不能重置协作者登录密码');
check((await alice.request('users/'+idB,'PUT',{role:'admin'})).status===200,'协作者可以成为项目管理员');
check((await bob.request('users','GET',null,pA)).status===200,'协作管理员可读取当前项目成员');
check((await bob.request('users/'+idB,'PUT',{role:'viewer'},pA)).status===200,'协作管理员可降低自己的项目权限');
check((await bob.request('entities','POST',{name:'降权后的写入',type:'系统'},pA)).status===403,'自我降权后写入立即被拒绝');
check((await alice.request('users/'+idB,'PUT',{active:false})).status===200,'可以将协作者移出项目');
check((await bob.request('audit','GET',null,pA)).status===403,'移出后当前项目访问立即失效');
check((await bob.request('state')).body.entities.some(n=>n.id===nB.id),'移出协作项目不影响其个人项目');
check((await bob.request('state')).body.user.active==1,'移出协作项目不会停用用户账号');
const countBefore=(await alice.request('state')).body.entities.length;
check((await alice.request('import','POST',{format:'zhilian-library',schema_version:1,entities:[{id:'new1',name:'回滚',type:'系统'}],relations:[{source:'new1',target:nB.id,type:'非法关联'}]})).status===400,'非法跨项目导入被拒绝');
check((await alice.request('state')).body.entities.length===countBefore,'失败导入仍整体回滚');
r=await alice.request('entities/'+nA.id,'PUT',{...nA,description:'版本更新'});check(r.status===200&&r.body.record.version===2,'项目内更新递增版本');
check((await alice.request('entities/'+nA.id,'PUT',nA)).status===409,'项目内并发编辑防止覆盖');
const auditA=(await alice.request('audit')).body.entries,auditB=(await bob.request('audit')).body.entries;
check(auditA.every(e=>e.project_id===pA)&&auditB.every(e=>e.project_id===pB),'审计记录按项目隔离');
check(auditA.some(e=>e.object_id===nA.id&&e.before?.description!==e.after?.description),'变更记录保存前后快照');
const exportA=(await alice.request('export')).body;check(exportA.project_name==='甲的默认项目'&&!exportA.entities.some(n=>n.id===nB.id),'导出只包含当前项目内容');
check((await carol.request('state','GET',null,pA)).status===403,'第三个注册用户仍无权访问');
await alice.request('logout','POST',{});check((await alice.request('audit')).status===401,'退出后项目接口需重新登录');
check((await alice.request('login','POST',{username:usernameA,password})).status===200,'注册账号可再次登录');
check((await alice.request('projects')).body.projects.length===2,'再次登录后保留全部个人项目');
const initialBefore=(await alice.request('state')).body,auditBefore=(await alice.request('audit')).body.entries.length;
r=await alice.request('entities','POST',{name:'从选中对象创建',type:'设备',initial_relation:{source:nA.id,type:'连接到'}});
check(r.status===200&&r.body.relation.source===nA.id&&r.body.relation.target===r.body.record.id,'新建对象同时建立从起点指向新对象的关系');
check(r.body.relation.type==='连接到'&&r.body.relation.owner==='测试甲','起始关系支持自定义类型和默认维护人');
check(!('initial_relation' in r.body.record),'起始关系不混入对象属性');
const linkedRecord=r.body.record;
let linkedState=(await alice.request('state')).body;
check(linkedState.entities.length===initialBefore.entities.length+1&&linkedState.relations.length===initialBefore.relations.length+1,'新对象与起始关系一同持久化');
check((await alice.request('audit')).body.entries.length===auditBefore+2,'对象和起始关系各有独立变更记录');
const rollbackBefore=linkedState,rollbackAudit=(await alice.request('audit')).body.entries.length;
for(const [label,initial_relation] of [
  ['跨项目起点',{source:nB.id,type:'关联'}],
  ['不存在的起点',{source:'0'.repeat(24),type:'关联'}],
  ['空白关系类型',{source:nA.id,type:' '}],
  ['格式错误的起始关系','invalid']
])check((await alice.request('entities','POST',{name:'不应保留的对象',type:'系统',initial_relation})).status===400,label+'被拒绝');
linkedState=(await alice.request('state')).body;
check(linkedState.entities.length===rollbackBefore.entities.length&&linkedState.relations.length===rollbackBefore.relations.length&&(await alice.request('audit')).body.entries.length===rollbackAudit,'起始关系失败时对象、关系和审计整体回滚');
check((await alice.request('entities/'+linkedRecord.id,'PUT',{...linkedRecord,initial_relation:{source:nA.id,type:'关联'}})).status===400,'编辑对象不会附带新增起始关系');
check((await bob.request('entities','POST',{name:'越权新建并关联',type:'系统',initial_relation:{source:nA.id,type:'关联'}},pA)).status===403,'组合创建仍校验项目访问权限');
console.log(`\n${count} registration/project-isolation API checks passed.`);
