export const typeColors = { '系统': '#397f74', '账号': '#b07a35', '人员': '#6379a1', '项目': '#8b6b91', '资源': '#71816a', '文档': '#927463' };
export const defaultTypes = Object.keys(typeColors);
export const relationTypes = ['使用', '可访问', '负责', '依赖', '部署于', '属于', '同步至', '指向', '存放于', '关联'];
export const statusNames = { active: '有效', inactive: '停用', archived: '已归档', ended: '已结束' };
export const roles = { admin: '管理员', editor: '编辑者', viewer: '查看者' };
export const colorOf = type => typeColors[type] || '#728183';
export const isStale = record => !record.verified_at || (Date.now() - new Date(record.verified_at).getTime()) > 90 * 86400000;
export const activeRelation = r => r.status === 'active' && (!r.start_date || r.start_date <= new Date().toLocaleDateString('en-CA')) && (!r.end_date || r.end_date >= new Date().toLocaleDateString('en-CA'));
export const needsReview = (node, relations) => isStale(node) || !node.owner || !relations.some(r => (r.source === node.id || r.target === node.id) && activeRelation(r));
export function neighborhood(id, nodes, relations, depth=1, includeEnded=false) {
  const known = new Set(nodes.map(n => n.id));
  const ids = new Set(known.has(id) ? [id] : []);
  for (let d=0; d<depth; d++) {
    const old = new Set(ids);
    relations.filter(r => includeEnded || activeRelation(r)).forEach(r => {
      if (old.has(r.source) && known.has(r.target)) ids.add(r.target);
      if (old.has(r.target) && known.has(r.source)) ids.add(r.source);
    });
  }
  return ids;
}
export function findPath(source, target, nodes, relations, includeEnded=false) {
  const known = new Set(nodes.map(n => n.id));
  if (!known.has(source) || !known.has(target)) return null;
  const queue = [[source]], visited = new Set([source]);
  while (queue.length) {
    const path = queue.shift(), last = path[path.length-1];
    if (last === target) return path;
    for (const r of relations) {
      if (!includeEnded && !activeRelation(r)) continue;
      const next = r.source === last ? r.target : r.target === last ? r.source : null;
      if (next && known.has(next) && !visited.has(next)) { visited.add(next); queue.push([...path, next]); }
    }
  }
  return null;
}
export function demoData() {
  const t = new Date().toISOString();
  const raw = [
    ['crm','客户管理系统','系统','陈晓','客户资料、销售跟进与客户服务的统一入口。',570,270,['核心业务']],
    ['erp','财务系统','系统','林悦','合同结算与财务核算。',900,270,['财务']],
    ['wiki','团队知识库','系统','陈晓','团队文档、操作说明与项目资料。',900,510,['协作']],
    ['account1','CRM · chen.xiao','账号','陈晓','客户管理系统的管理员账号。',285,140,['管理员']],
    ['account2','CRM · lin.yue','账号','林悦','客户管理系统的业务账号。',285,350,['业务账号']],
    ['account3','财务 · lin.yue','账号','林悦','财务系统的审核账号。',900,50,['审核']],
    ['person1','陈晓','人员','陈晓','负责系统维护与销售运营。',20,140,['运营']],
    ['person2','林悦','人员','林悦','财务审核与项目协作。',20,350,['财务']],
    ['project','客户服务升级','项目','陈晓','客户服务流程改造与知识库建设。',650,25,['进行中']],
    ['database','客户数据库','资源','陈晓','客户与销售业务的数据存储。',570,510,['数据库']],
    ['server','业务服务器','资源','陈晓','内部业务应用的运行环境。',285,575,['服务器']],
    ['domain','crm.example.internal','资源','陈晓','客户管理系统的内网入口。',340,-60,['域名']],
    ['contract','年度服务合同','文档','林悦','客户服务升级项目的交付依据。',1170,70,['合同']],
  ];
  const entities = raw.map(([id,name,type,owner,description,x,y,tags])=>({id,name,type,owner,description,x,y,tags,status:'active',fields: id==='crm'?{'环境':'生产','访问方式':'内网','维护周期':'每月'}:{},verified_at:t,created_at:t,updated_at:t,version:1}));
  entities.find(n=>n.id==='account2').verified_at='2026-01-01T00:00:00Z';
  const links = [['person1','使用','account1',''],['person2','使用','account2',''],['person2','使用','account3',''],['account1','可访问','crm','管理员'],['account2','可访问','crm','业务人员'],['account3','可访问','erp','审核员'],['project','使用','crm',''],['project','使用','wiki',''],['crm','同步至','erp',''],['crm','依赖','database',''],['crm','部署于','server',''],['database','部署于','server',''],['domain','指向','crm',''],['contract','属于','project',''],['contract','存放于','wiki','']];
  const relations = links.map(([source,type,target,role],i)=>({id:'r'+(i+1),source,type,target,role,status:'active',owner:i===5?'林悦':'陈晓',description:'',evidence:'示例数据 · 用于体验关系结构',start_date:'',end_date:'',verified_at:t,created_at:t,updated_at:t,version:1}));
  relations[4].verified_at='2026-01-01T00:00:00Z';
  return {entities,relations,user:null,demo:true,configured:false,workspace:'示例空间',entries:[]};
}
