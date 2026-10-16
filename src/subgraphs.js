export const parentOf = record => record.parent_id ?? null;

export function canvasRecords(entities, relations, parentId=null) {
  const nodes=entities.filter(n=>parentOf(n)===parentId),ids=new Set(nodes.map(n=>n.id));
  return {entities:nodes,relations:relations.filter(r=>ids.has(r.source)&&ids.has(r.target))};
}

export function descendantsOf(id,entities) {
  const children=new Map();
  for(const n of entities){const p=parentOf(n);if(!children.has(p))children.set(p,[]);children.get(p).push(n);}
  const found=[],seen=new Set([id]),queue=[id];
  for(let i=0;i<queue.length;i++)for(const n of children.get(queue[i])||[])if(!seen.has(n.id)){seen.add(n.id);found.push(n);queue.push(n.id);}
  return found;
}

export function scopeTrail(id,entities) {
  const byId=new Map(entities.map(n=>[n.id,n])),seen=new Set(),trail=[];
  while(id){if(seen.has(id)||!byId.has(id))return [];seen.add(id);const n=byId.get(id);trail.push(n);id=parentOf(n);}
  return trail.reverse();
}

// Returns parents before children, while validating imported hierarchy independently of file order.
export function hierarchyOrder(entities) {
  const byId=new Map(),children=new Map();
  for(const n of entities){if(!n||typeof n.id!=='string'||!n.id||byId.has(n.id))throw new Error('对象编号缺失或重复');if(n.has_subgraph!=null&&typeof n.has_subgraph!=='boolean')throw new Error('子图开关必须为布尔值');byId.set(n.id,n);}
  for(const n of entities){const p=parentOf(n);if(p!==null&&(typeof p!=='string'||!byId.get(p)?.has_subgraph))throw new Error('子图所属对象不存在或未启用子图');if(!children.has(p))children.set(p,[]);children.get(p).push(n);}
  const ordered=[...(children.get(null)||[])];
  for(let i=0;i<ordered.length;i++)ordered.push(...(children.get(ordered[i].id)||[]));
  if(ordered.length!==entities.length)throw new Error('子图不能包含自身或形成循环嵌套');
  return ordered;
}
